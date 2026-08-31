package core_test

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"reflect"
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

func TestConformanceToolAdmissionApprovalReauthorizationAndIdempotency(t *testing.T) {
	policy := &testPolicy{allowWrite: false}
	executor := &recordingExecutor{}
	service := fixtureService(t, policy, executor)
	session, run := fixtureSessionAndRun(t, service)

	tools, err := service.AdvertisedTools(context.Background(), session.ID, run.ID)
	if err != nil {
		t.Fatal(err)
	}
	if len(tools) != 1 || tools[0].Metadata.Name != "status.read" {
		t.Fatalf("write tool must be hidden before a grant: %#v", tools)
	}

	policy.allowWrite = true
	tools, err = service.AdvertisedTools(context.Background(), session.ID, run.ID)
	if err != nil {
		t.Fatal(err)
	}
	if len(tools) != 2 {
		t.Fatalf("grant must advertise both tools, got %d", len(tools))
	}

	request := core.ToolCallRequest{SessionID: session.ID, RunID: run.ID, ToolRef: toolRef("task.write"), Arguments: json.RawMessage(`{"task":"deploy"}`), IdempotencyKey: "operation-1"}
	pending, err := service.RequestToolCall(context.Background(), request)
	if !errors.Is(err, core.ErrApprovalRequired) || pending.Approval == nil {
		t.Fatalf("write must request approval, result=%#v err=%v", pending, err)
	}
	if executor.calls != 0 {
		t.Fatal("executor ran before approval")
	}

	policy.allowExecute = false
	_, err = service.ResolveApproval(context.Background(), pending.Approval.ID, true)
	if !errors.Is(err, core.ErrDenied) {
		t.Fatalf("execution must re-authorize with actual input, got %v", err)
	}
	if executor.calls != 0 || policy.authorizeCalls != 1 {
		t.Fatalf("unauthorized call ran executor=%d authorize=%d", executor.calls, policy.authorizeCalls)
	}

	policy.allowExecute = true
	request.ToolRef = toolRef("status.read")
	request.IdempotencyKey = "read-1"
	first, err := service.RequestToolCall(context.Background(), request)
	if err != nil {
		t.Fatal(err)
	}
	second, err := service.RequestToolCall(context.Background(), request)
	if err != nil {
		t.Fatal(err)
	}
	if executor.calls != 1 || !second.Replayed || first.Lease.ID != second.Lease.ID {
		t.Fatalf("idempotency failed: calls=%d first=%#v second=%#v", executor.calls, first, second)
	}

	events, err := service.Events(run.ID)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{"run.started", "tool.approval.requested", "tool.approval.approved", "tool.completed"}
	if got := eventTypes(events); !reflect.DeepEqual(got, want) {
		t.Fatalf("unexpected event trail: got %v want %v", got, want)
	}
}

func TestConformanceRequiresCallerIdempotencyKey(t *testing.T) {
	service := fixtureService(t, &testPolicy{allowWrite: true, allowExecute: true}, &recordingExecutor{})
	session, run := fixtureSessionAndRun(t, service)
	_, err := service.RequestToolCall(context.Background(), core.ToolCallRequest{SessionID: session.ID, RunID: run.ID, ToolRef: toolRef("task.write"), Arguments: json.RawMessage(`{"task":"deploy"}`)})
	if !errors.Is(err, core.ErrIdempotencyKey) {
		t.Fatalf("expected caller idempotency requirement, got %v", err)
	}
}

func fixtureService(t *testing.T, policy *testPolicy, executor *recordingExecutor) *core.Service {
	t.Helper()
	catalog := core.NewCatalog()
	for _, tool := range []api.Tool{fixtureTool("status.read", api.EffectRead, api.IdempotencyCallerKeyRequired), fixtureTool("task.write", api.EffectWrite, api.IdempotencyCallerKeyRequired)} {
		if err := catalog.RegisterTool(tool); err != nil {
			t.Fatal(err)
		}
	}
	agent := api.Agent{APIVersion: api.APIVersion, Kind: api.KindAgent, Metadata: api.Metadata{Name: "operator", Version: "1.0.0"}, Spec: api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "adk-go", ModelProfileRef: "primary"}, Tools: []api.ResourceRef{toolRef("status.read"), toolRef("task.write")}}}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	count := 0
	service, err := core.NewService(catalog, policy, executor, func(prefix string) string { count++; return fmt.Sprintf("%s-%d", prefix, count) })
	if err != nil {
		t.Fatal(err)
	}
	return service
}

func fixtureSessionAndRun(t *testing.T, service *core.Service) (api.Session, api.Run) {
	t.Helper()
	session, err := service.CreateSession("user-1", api.ResourceRef{Kind: api.KindAgent, Name: "operator", Version: "1.0.0"})
	if err != nil {
		t.Fatal(err)
	}
	run, err := service.StartRun(session.ID)
	if err != nil {
		t.Fatal(err)
	}
	return session, run
}

func fixtureTool(name string, effect api.Effect, idempotency api.IdempotencyMode) api.Tool {
	return api.Tool{APIVersion: api.APIVersion, Kind: api.KindTool, Metadata: api.Metadata{Name: name, Version: "1.0.0"}, Spec: api.ToolSpec{Effect: effect, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Idempotency: idempotency, Executor: api.ExecutorBinding{Kind: "fixture"}}}
}

func toolRef(name string) api.ResourceRef {
	return api.ResourceRef{Kind: api.KindTool, Name: name, Version: "1.0.0"}
}

type testPolicy struct {
	allowWrite     bool
	allowExecute   bool
	authorizeCalls int
}

func (p *testPolicy) Advertise(_ context.Context, call core.ToolCall) bool {
	return call.Tool.Spec.Effect == api.EffectRead || p.allowWrite
}

func (p *testPolicy) Authorize(_ context.Context, call core.ToolCall) error {
	p.authorizeCalls++
	if call.Tool.Spec.Effect == api.EffectWrite && !p.allowExecute {
		return core.ErrDenied
	}
	return nil
}

type recordingExecutor struct{ calls int }

func (e *recordingExecutor) Execute(_ context.Context, _ core.ExecutionLease) (json.RawMessage, error) {
	e.calls++
	return json.RawMessage(`{"ok":true}`), nil
}

func eventTypes(events []api.Event) []string {
	result := make([]string, len(events))
	for i, event := range events {
		result[i] = event.Type
	}
	return result
}

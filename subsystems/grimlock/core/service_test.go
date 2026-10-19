package core_test

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"reflect"
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

func TestConformanceToolAdmissionApprovalReauthorizationAndIdempotency(t *testing.T) {
	fixture := loadConformanceFixture(t)
	policy := &testPolicy{allowWrite: false}
	executor := &recordingExecutor{}
	service := fixtureService(t, policy, executor)
	session, run := fixtureSessionAndRun(t, service)

	tools, err := service.AdvertisedTools(context.Background(), session.ID, run.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got := toolKeys(tools); !reflect.DeepEqual(got, fixture.Spec.Expectations.AdvertisedBeforeGrant) {
		t.Fatalf("write tool must be hidden before a grant: %#v", tools)
	}

	policy.allowWrite = true
	tools, err = service.AdvertisedTools(context.Background(), session.ID, run.ID)
	if err != nil {
		t.Fatal(err)
	}
	if got := toolKeys(tools); !reflect.DeepEqual(got, fixture.Spec.Expectations.AdvertisedAfterGrant) {
		t.Fatalf("grant must advertise the fixture tools, got %v", got)
	}

	request := fixture.Spec.Calls.Write.request(session.ID, run.ID)
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
	request = fixture.Spec.Calls.Read.request(session.ID, run.ID)
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
	want := fixture.Spec.Expectations.EventTypes
	if got := eventTypes(events); !reflect.DeepEqual(got, want) {
		t.Fatalf("unexpected event trail: got %v want %v", got, want)
	}
}

func TestConformanceRequiresCallerIdempotencyKey(t *testing.T) {
	service := fixtureService(t, &testPolicy{allowWrite: true, allowExecute: true}, &recordingExecutor{})
	session, run := fixtureSessionAndRun(t, service)
	fixture := loadConformanceFixture(t)
	request := fixture.Spec.Calls.Write.request(session.ID, run.ID)
	request.IdempotencyKey = ""
	_, err := service.RequestToolCall(context.Background(), request)
	if !errors.Is(err, core.ErrIdempotencyKey) {
		t.Fatalf("expected caller idempotency requirement, got %v", err)
	}
}

func TestIdempotencyRejectsArgumentReuseAndConcurrentExecution(t *testing.T) {
	service := fixtureService(t, &testPolicy{allowWrite: true, allowExecute: true}, &recordingExecutor{})
	session, run := fixtureSessionAndRun(t, service)
	fixture := loadConformanceFixture(t)
	request := fixture.Spec.Calls.Read.request(session.ID, run.ID)

	if _, err := service.RequestToolCall(t.Context(), request); err != nil {
		t.Fatal(err)
	}
	request.Arguments = json.RawMessage(`{"probe":"different"}`)
	if _, err := service.RequestToolCall(t.Context(), request); !errors.Is(err, core.ErrIdempotencyConflict) {
		t.Fatalf("expected argument conflict, got %v", err)
	}

	blocking := &blockingExecutor{started: make(chan struct{}), release: make(chan struct{})}
	service = fixtureService(t, &testPolicy{allowWrite: true, allowExecute: true}, blocking)
	session, run = fixtureSessionAndRun(t, service)
	request = fixture.Spec.Calls.Read.request(session.ID, run.ID)
	first := make(chan error, 1)
	go func() {
		_, err := service.RequestToolCall(context.Background(), request)
		first <- err
	}()
	<-blocking.started
	result, err := service.RequestToolCall(t.Context(), request)
	if !errors.Is(err, core.ErrIdempotencyInProgress) {
		t.Fatalf("expected an in-progress conflict, got result=%+v err=%v", result, err)
	}
	if result.Lease.ID == "" || blocking.calls != 1 {
		t.Fatalf("expected one reserved lease and one execution, result=%+v calls=%d", result, blocking.calls)
	}
	close(blocking.release)
	if err := <-first; err != nil {
		t.Fatal(err)
	}
	replayed, err := service.RequestToolCall(t.Context(), request)
	if err != nil || !replayed.Replayed || replayed.Lease.ID != result.Lease.ID || blocking.calls != 1 {
		t.Fatalf("expected a single completed replay, result=%+v err=%v calls=%d", replayed, err, blocking.calls)
	}
}

func TestIdempotencyDoesNotReplayAfterExecutionFailure(t *testing.T) {
	executor := &failingExecutor{}
	service := fixtureService(t, &testPolicy{allowWrite: true, allowExecute: true}, executor)
	session, run := fixtureSessionAndRun(t, service)
	request := loadConformanceFixture(t).Spec.Calls.Read.request(session.ID, run.ID)

	if _, err := service.RequestToolCall(t.Context(), request); !errors.Is(err, errFixtureExecution) {
		t.Fatalf("expected executor failure, got %v", err)
	}
	if _, err := service.RequestToolCall(t.Context(), request); !errors.Is(err, core.ErrIdempotencyFailed) || executor.calls != 1 {
		t.Fatalf("expected a terminal failed idempotency record, err=%v calls=%d", err, executor.calls)
	}
}

func fixtureService(t *testing.T, policy *testPolicy, executor core.ToolExecutor) *core.Service {
	t.Helper()
	fixture := loadConformanceFixture(t)
	catalog := core.NewCatalog()
	for _, tool := range fixture.Spec.Tools {
		if err := catalog.RegisterTool(tool); err != nil {
			t.Fatal(err)
		}
		if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
			t.Fatal(err)
		}
	}
	if err := catalog.RegisterAgent(fixture.Spec.Agent); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: fixture.Spec.Agent.Metadata.Name, Version: fixture.Spec.Agent.Metadata.Version}); err != nil {
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
	fixture := loadConformanceFixture(t)
	session, err := service.CreateSession("user-1", api.ResourceRef{Kind: api.KindAgent, Name: fixture.Spec.Agent.Metadata.Name, Version: fixture.Spec.Agent.Metadata.Version})
	if err != nil {
		t.Fatal(err)
	}
	run, err := service.StartRun(session.ID)
	if err != nil {
		t.Fatal(err)
	}
	return session, run
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

type blockingExecutor struct {
	started chan struct{}
	release chan struct{}
	calls   int
}

var errFixtureExecution = errors.New("fixture execution failed")

type failingExecutor struct{ calls int }

func (e *failingExecutor) Execute(_ context.Context, _ core.ExecutionLease) (json.RawMessage, error) {
	e.calls++
	return nil, errFixtureExecution
}

func (e *blockingExecutor) Execute(_ context.Context, _ core.ExecutionLease) (json.RawMessage, error) {
	e.calls++
	close(e.started)
	<-e.release
	return json.RawMessage(`{"ok":true}`), nil
}

type conformanceFixture struct {
	APIVersion string `json:"apiVersion"`
	Kind       string `json:"kind"`
	Spec       struct {
		Agent api.Agent  `json:"agent"`
		Tools []api.Tool `json:"tools"`
		Calls struct {
			Read  fixtureToolCall `json:"read"`
			Write fixtureToolCall `json:"write"`
		} `json:"calls"`
		Expectations struct {
			AdvertisedBeforeGrant []string `json:"advertisedBeforeGrant"`
			AdvertisedAfterGrant  []string `json:"advertisedAfterGrant"`
			EventTypes            []string `json:"eventTypes"`
		} `json:"expectations"`
	} `json:"spec"`
}

type fixtureToolCall struct {
	ToolRef        api.ResourceRef `json:"toolRef"`
	Arguments      json.RawMessage `json:"arguments"`
	IdempotencyKey string          `json:"idempotencyKey"`
}

func (c fixtureToolCall) request(sessionID, runID string) core.ToolCallRequest {
	return core.ToolCallRequest{
		SessionID: sessionID, RunID: runID, ToolRef: c.ToolRef,
		Arguments: append(json.RawMessage(nil), c.Arguments...), IdempotencyKey: c.IdempotencyKey,
	}
}

func loadConformanceFixture(t *testing.T) conformanceFixture {
	t.Helper()
	raw, err := os.ReadFile(filepath.Join("..", "conformance", "v1alpha1", "admission-approval-idempotency.fixture.json"))
	if err != nil {
		t.Fatal(err)
	}
	var fixture conformanceFixture
	if err := json.Unmarshal(raw, &fixture); err != nil {
		t.Fatal(err)
	}
	if fixture.APIVersion != api.APIVersion || fixture.Kind != "ConformanceFixture" {
		t.Fatalf("unexpected fixture identity: %s %s", fixture.APIVersion, fixture.Kind)
	}
	return fixture
}

func toolKeys(tools []api.Tool) []string {
	keys := make([]string, len(tools))
	for index, tool := range tools {
		keys[index] = tool.Metadata.Key()
	}
	return keys
}

func eventTypes(events []api.Event) []string {
	result := make([]string, len(events))
	for i, event := range events {
		result[i] = event.Type
	}
	return result
}

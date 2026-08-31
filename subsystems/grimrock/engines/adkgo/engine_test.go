package adkgo_test

import (
	"context"
	"encoding/json"
	"fmt"
	"iter"
	"sync/atomic"
	"testing"

	"google.golang.org/adk/v2/model"
	"google.golang.org/genai"

	grimlockapi "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	grimrockapi "github.com/akive/grimoire/subsystems/grimrock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimrock/engines/adkgo"
)

func TestEngineExecutesAnAdmittedGrimlockTool(t *testing.T) {
	catalog := core.NewCatalog()
	tool := grimlockapi.Tool{
		APIVersion: grimlockapi.APIVersion, Kind: grimlockapi.KindTool, Metadata: grimlockapi.Metadata{Name: "status.read", Version: "1"},
		Spec: grimlockapi.ToolSpec{Description: "Read status.", Effect: grimlockapi.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: grimlockapi.ExecutorBinding{Kind: "fixture"}},
	}
	agentManifest := grimlockapi.Agent{
		APIVersion: grimlockapi.APIVersion, Kind: grimlockapi.KindAgent, Metadata: grimlockapi.Metadata{Name: "operator", Version: "1"},
		Spec: grimlockapi.AgentSpec{Execution: grimlockapi.ExecutionSpec{Adapter: "adk-go", ModelProfileRef: "scripted"}, Instruction: "Read status when asked.", Tools: []grimlockapi.ResourceRef{{Kind: grimlockapi.KindTool, Name: "status.read", Version: "1"}}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agentManifest); err != nil {
		t.Fatal(err)
	}
	var ids atomic.Uint64
	var executions atomic.Uint64
	service, err := core.NewService(catalog, core.ReadOnlyPolicy{}, executorFunc(func(context.Context, core.ExecutionLease) (json.RawMessage, error) {
		executions.Add(1)
		return json.RawMessage(`{"status":"ready"}`), nil
	}), func(prefix string) string { return fmt.Sprintf("%s-%d", prefix, ids.Add(1)) })
	if err != nil {
		t.Fatal(err)
	}
	session, err := service.CreateSession("user-1", grimlockapi.ResourceRef{Kind: grimlockapi.KindAgent, Name: "operator", Version: "1"})
	if err != nil {
		t.Fatal(err)
	}
	run, err := service.StartRun(session.ID)
	if err != nil {
		t.Fatal(err)
	}

	adapter, err := adkgo.NewAdapter(catalog, service)
	if err != nil {
		t.Fatal(err)
	}
	engine, err := adkgo.NewEngine(adapter, func(context.Context, string) (model.LLM, error) {
		return &scriptedLLM{responses: []*model.LLMResponse{
			{Content: &genai.Content{Role: genai.RoleModel, Parts: []*genai.Part{{FunctionCall: &genai.FunctionCall{ID: "call-1", Name: "status_read", Args: map[string]any{}}}}}},
			{Content: genai.NewContentFromText("The system is ready.", genai.RoleModel)},
		}}, nil
	})
	if err != nil {
		t.Fatal(err)
	}

	var executionEvents []grimrockapi.Event
	for event, err := range engine.Run(t.Context(), grimrockapi.Request{
		RunID: run.ID, SessionID: session.ID, SubjectID: session.SubjectID,
		AgentRef: session.AgentRef, Engine: "adk-go", Input: json.RawMessage(`{"text":"Check status"}`),
	}) {
		if err != nil {
			t.Fatal(err)
		}
		executionEvents = append(executionEvents, event)
	}
	if executions.Load() != 1 {
		t.Fatalf("expected one Grimlock-governed execution, got %d", executions.Load())
	}
	if len(executionEvents) < 3 || executionEvents[0].Type != "execution.started" || executionEvents[len(executionEvents)-1].Type != "execution.completed" {
		t.Fatalf("unexpected Grimrock events: %+v", executionEvents)
	}
	events, err := service.Events(run.ID)
	if err != nil {
		t.Fatal(err)
	}
	if events[len(events)-1].Type != "tool.completed" {
		t.Fatalf("expected durable tool.completed event, got %+v", events)
	}
}

type executorFunc func(context.Context, core.ExecutionLease) (json.RawMessage, error)

func (f executorFunc) Execute(ctx context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
	return f(ctx, lease)
}

type scriptedLLM struct {
	responses []*model.LLMResponse
	index     int
}

func (s *scriptedLLM) Name() string { return "scripted" }

func (s *scriptedLLM) GenerateContent(context.Context, *model.LLMRequest, bool) iter.Seq2[*model.LLMResponse, error] {
	index := s.index
	s.index++
	return func(yield func(*model.LLMResponse, error) bool) {
		if index < len(s.responses) {
			yield(s.responses[index], nil)
			return
		}
		yield(&model.LLMResponse{Content: genai.NewContentFromText("done", genai.RoleModel)}, nil)
	}
}

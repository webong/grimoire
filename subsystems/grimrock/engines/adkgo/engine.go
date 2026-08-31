package adkgo

import (
	"context"
	"encoding/json"
	"errors"
	"iter"
	"sync"
	"time"

	"google.golang.org/adk/v2/agent"
	"google.golang.org/adk/v2/model"
	"google.golang.org/adk/v2/runner"
	"google.golang.org/adk/v2/session"
	"google.golang.org/genai"

	grimrockapi "github.com/akive/grimoire/subsystems/grimrock/api/v1alpha1"
)

type ModelResolver func(context.Context, string) (model.LLM, error)

type Engine struct {
	adapter       *Adapter
	resolveModel  ModelResolver
	now           func() time.Time
	mu            sync.Mutex
	cancellations map[string]context.CancelFunc
}

func NewEngine(adapter *Adapter, resolver ModelResolver) (*Engine, error) {
	if adapter == nil || resolver == nil {
		return nil, errors.New("adapter and model resolver are required")
	}
	return &Engine{adapter: adapter, resolveModel: resolver, now: time.Now, cancellations: map[string]context.CancelFunc{}}, nil
}

func (e *Engine) Name() string { return "adk-go" }

func (e *Engine) Run(ctx context.Context, request grimrockapi.Request) iter.Seq2[grimrockapi.Event, error] {
	return func(yield func(grimrockapi.Event, error) bool) {
		if err := request.Validate(); err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		if request.Engine != e.Name() {
			yield(grimrockapi.Event{}, errors.New("request engine does not match adk-go"))
			return
		}
		manifest, err := e.adapter.catalog.Agent(request.AgentRef)
		if err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		llm, err := e.resolveModel(ctx, manifest.Spec.Execution.ModelProfileRef)
		if err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		adkAgent, err := e.adapter.BuildAgent(ctx, request.SessionID, request.RunID, request.AgentRef, llm)
		if err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		adkRunner, err := runner.New(runner.Config{AppName: "grimoire", Agent: adkAgent, SessionService: session.InMemoryService(), AutoCreateSession: true})
		if err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		runContext, cancel := context.WithCancel(ctx)
		e.mu.Lock()
		e.cancellations[request.RunID] = cancel
		e.mu.Unlock()
		defer func() {
			cancel()
			e.mu.Lock()
			delete(e.cancellations, request.RunID)
			e.mu.Unlock()
		}()

		if !yield(e.event(request.RunID, "execution.started", map[string]string{"engine": e.Name()}), nil) {
			return
		}
		message, err := inputContent(request.Input)
		if err != nil {
			yield(grimrockapi.Event{}, err)
			return
		}
		for event, runErr := range adkRunner.Run(runContext, request.SubjectID, request.SessionID, message, agent.RunConfig{StreamingMode: agent.StreamingModeSSE}) {
			if runErr != nil {
				yield(grimrockapi.Event{}, runErr)
				return
			}
			if event == nil || event.LLMResponse.Content == nil {
				continue
			}
			for _, part := range event.LLMResponse.Content.Parts {
				if part.Text == "" {
					continue
				}
				kind := "model.completed"
				if event.LLMResponse.Partial {
					kind = "model.delta"
				}
				if !yield(e.event(request.RunID, kind, map[string]string{"text": part.Text}), nil) {
					return
				}
			}
		}
		yield(e.event(request.RunID, "execution.completed", map[string]string{"engine": e.Name()}), nil)
	}
}

func (e *Engine) Cancel(_ context.Context, runID string) error {
	e.mu.Lock()
	cancel, ok := e.cancellations[runID]
	e.mu.Unlock()
	if !ok {
		return errors.New("run is not active in adk-go")
	}
	cancel()
	return nil
}

func (e *Engine) event(runID, kind string, value any) grimrockapi.Event {
	data, _ := json.Marshal(value)
	return grimrockapi.Event{APIVersion: grimrockapi.APIVersion, RunID: runID, Type: kind, OccurredAt: e.now().UTC(), Data: data}
}

func inputContent(raw json.RawMessage) (*genai.Content, error) {
	var input struct {
		Text string `json:"text"`
	}
	if err := json.Unmarshal(raw, &input); err != nil {
		return nil, err
	}
	if input.Text == "" {
		return nil, errors.New("ADK input.text is required")
	}
	return genai.NewContentFromText(input.Text, genai.RoleUser), nil
}

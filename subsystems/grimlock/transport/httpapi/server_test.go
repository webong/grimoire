package httpapi_test

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	"github.com/akive/grimoire/subsystems/grimlock/transport/httpapi"
)

func TestControlPlaneLifecycleAndEventCursor(t *testing.T) {
	catalog := core.NewCatalog()
	tool := api.Tool{APIVersion: api.APIVersion, Kind: api.KindTool, Metadata: api.Metadata{Name: "status.read", Version: "1"}, Spec: api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: api.ExecutorBinding{Kind: "fixture"}}}
	agent := api.Agent{APIVersion: api.APIVersion, Kind: api.KindAgent, Metadata: api.Metadata{Name: "operator", Version: "1"}, Spec: api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "adk-go", ModelProfileRef: "test"}, Tools: []api.ResourceRef{{Kind: api.KindTool, Name: "status.read", Version: "1"}}}}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	var ids atomic.Uint64
	service, err := core.NewService(catalog, core.ReadOnlyPolicy{}, executorFunc(func(context.Context, core.ExecutionLease) (json.RawMessage, error) {
		return json.RawMessage(`{"ok":true}`), nil
	}), func(prefix string) string { return fmt.Sprintf("%s-%d", prefix, ids.Add(1)) })
	if err != nil {
		t.Fatal(err)
	}
	server, err := httpapi.New(service)
	if err != nil {
		t.Fatal(err)
	}

	session := request(t, server, http.MethodPost, "/v1/sessions", `{"subjectId":"user-1","agentRef":{"kind":"Agent","name":"operator","version":"1"}}`, http.StatusCreated)
	var createdSession api.Session
	decode(t, session, &createdSession)
	runBody := request(t, server, http.MethodPost, "/v1/sessions/"+createdSession.ID+"/runs", "{}", http.StatusCreated)
	var run api.Run
	decode(t, runBody, &run)
	request(t, server, http.MethodPost, "/v1/sessions/"+createdSession.ID+"/runs/"+run.ID+"/tool-calls", `{"toolRef":{"kind":"Tool","name":"status.read","version":"1"},"arguments":{}}`, http.StatusOK)

	recorder := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/v1/runs/"+run.ID+"/events?after=1", nil)
	req.Header.Set("Accept", "text/event-stream")
	server.ServeHTTP(recorder, req)
	if recorder.Code != http.StatusOK || !strings.Contains(recorder.Body.String(), "event: tool.completed") || strings.Contains(recorder.Body.String(), "run.started") {
		t.Fatalf("unexpected SSE response: status=%d body=%s", recorder.Code, recorder.Body.String())
	}
}

type executorFunc func(context.Context, core.ExecutionLease) (json.RawMessage, error)

func (f executorFunc) Execute(ctx context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
	return f(ctx, lease)
}

func request(t *testing.T, handler http.Handler, method, path, body string, status int) string {
	t.Helper()
	recorder := httptest.NewRecorder()
	handler.ServeHTTP(recorder, httptest.NewRequest(method, path, strings.NewReader(body)))
	if recorder.Code != status {
		t.Fatalf("%s %s: expected %d, got %d: %s", method, path, status, recorder.Code, recorder.Body.String())
	}
	return recorder.Body.String()
}

func decode(t *testing.T, value string, destination any) {
	t.Helper()
	if err := json.Unmarshal([]byte(value), destination); err != nil {
		t.Fatal(err)
	}
}

package registryhttp_test

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/webong/grimoire/subsystems/grimlock/core"
	"github.com/webong/grimoire/subsystems/grimlock/transport/registryhttp"
)

func TestResolvedAgentDefinitionEndpoint(t *testing.T) {
	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion, Kind: api.KindTool,
		Metadata: api.Metadata{Name: "status.read", Version: "1"},
		Spec:     api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: api.ExecutorBinding{Kind: "runtime-capability", Capability: "status.read"}},
	}
	agent := api.Agent{
		APIVersion: api.APIVersion, Kind: api.KindAgent,
		Metadata: api.Metadata{Name: "operator", Version: "1"},
		Spec:     api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "runtime", ModelProfileRef: "primary"}, Instruction: "Read status.", Tools: []api.ResourceRef{{Kind: api.KindTool, Name: "status.read", Version: "1"}}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: agent.Metadata.Name, Version: agent.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	server, err := registryhttp.New(catalog)
	if err != nil {
		t.Fatal(err)
	}

	recorder := httptest.NewRecorder()
	server.ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, "/v1/registry/agents/operator/1/resolved", nil))
	if recorder.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", recorder.Code, recorder.Body.String())
	}
	var definition api.ResolvedAgentDefinition
	if err := json.Unmarshal(recorder.Body.Bytes(), &definition); err != nil {
		t.Fatal(err)
	}
	if err := definition.Validate(); err != nil || definition.Agent.Metadata.Key() != "operator@1" || len(definition.Tools) != 1 {
		t.Fatalf("unexpected registry definition: %+v err=%v", definition, err)
	}

	missing := httptest.NewRecorder()
	server.ServeHTTP(missing, httptest.NewRequest(http.MethodGet, "/v1/registry/agents/missing/1/resolved", nil))
	if missing.Code != http.StatusNotFound {
		t.Fatalf("expected 404, got %d: %s", missing.Code, missing.Body.String())
	}
}

func TestRegistryHTTPPublicationLifecycleAndListing(t *testing.T) {
	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion, Kind: api.KindTool,
		Metadata: api.Metadata{Name: "status.read", Version: "1"},
		Spec:     api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: api.ExecutorBinding{Kind: "runtime-capability", Capability: "status.read"}},
	}
	agent := api.Agent{
		APIVersion: api.APIVersion, Kind: api.KindAgent,
		Metadata: api.Metadata{Name: "operator", Version: "1"},
		Spec:     api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "runtime", ModelProfileRef: "primary"}, Instruction: "Read status.", Tools: []api.ResourceRef{{Kind: api.KindTool, Name: "status.read", Version: "1"}}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	server, err := registryhttp.New(catalog)
	if err != nil {
		t.Fatal(err)
	}
	// Draft must not resolve or list.
	draft := httptest.NewRecorder()
	server.ServeHTTP(draft, httptest.NewRequest(http.MethodGet, "/v1/registry/agents/operator/1/resolved", nil))
	if draft.Code != http.StatusNotFound {
		t.Fatalf("draft should be 404, got %d: %s", draft.Code, draft.Body.String())
	}
	listDraft := httptest.NewRecorder()
	server.ServeHTTP(listDraft, httptest.NewRequest(http.MethodGet, "/v1/registry/agents", nil))
	if listDraft.Code != http.StatusOK || !json.Valid(listDraft.Body.Bytes()) {
		t.Fatalf("listing draft failed: %d %s", listDraft.Code, listDraft.Body.String())
	}
	var draftList map[string][]api.Agent
	if err := json.Unmarshal(listDraft.Body.Bytes(), &draftList); err != nil || len(draftList["agents"]) != 0 {
		t.Fatalf("draft listing should be empty, got %s err=%v", listDraft.Body.String(), err)
	}
	// Publish via HTTP.
	pubTool := httptest.NewRecorder()
	server.ServeHTTP(pubTool, httptest.NewRequest(http.MethodPost, "/v1/registry/Tool/status.read/1/publish", nil))
	if pubTool.Code != http.StatusOK {
		t.Fatalf("publish tool %d: %s", pubTool.Code, pubTool.Body.String())
	}
	pubAgent := httptest.NewRecorder()
	server.ServeHTTP(pubAgent, httptest.NewRequest(http.MethodPost, "/v1/registry/Agent/operator/1/publish", nil))
	if pubAgent.Code != http.StatusOK {
		t.Fatalf("publish agent %d: %s", pubAgent.Code, pubAgent.Body.String())
	}
	resolved := httptest.NewRecorder()
	server.ServeHTTP(resolved, httptest.NewRequest(http.MethodGet, "/v1/registry/agents/operator/1/resolved", nil))
	if resolved.Code != http.StatusOK {
		t.Fatalf("published should be 200, got %d: %s", resolved.Code, resolved.Body.String())
	}
	listPublished := httptest.NewRecorder()
	server.ServeHTTP(listPublished, httptest.NewRequest(http.MethodGet, "/v1/registry/agents", nil))
	var publishedList map[string][]api.Agent
	if err := json.Unmarshal(listPublished.Body.Bytes(), &publishedList); err != nil || len(publishedList["agents"]) != 1 {
		t.Fatalf("published listing should have 1, got %s err=%v", listPublished.Body.String(), err)
	}
	// Retire.
	retire := httptest.NewRecorder()
	server.ServeHTTP(retire, httptest.NewRequest(http.MethodPost, "/v1/registry/Agent/operator/1/retire", nil))
	if retire.Code != http.StatusOK {
		t.Fatalf("retire %d: %s", retire.Code, retire.Body.String())
	}
	afterRetire := httptest.NewRecorder()
	server.ServeHTTP(afterRetire, httptest.NewRequest(http.MethodGet, "/v1/registry/agents/operator/1/resolved", nil))
	if afterRetire.Code != http.StatusNotFound {
		t.Fatalf("retired should be 404, got %d: %s", afterRetire.Code, afterRetire.Body.String())
	}
}

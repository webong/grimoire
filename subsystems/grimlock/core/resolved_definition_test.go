package core_test

import (
	"encoding/json"
	"errors"
	"testing"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/webong/grimoire/subsystems/grimlock/core"
)

func TestCatalogResolvesDigestVerifiedImmutableDefinition(t *testing.T) {
	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion,
		Kind:       api.KindTool,
		Metadata:   api.Metadata{Name: "knowledge.search", Version: "1.0.0"},
		Spec: api.ToolSpec{
			Effect:       api.EffectRead,
			InputSchema:  []byte(`{"type":"object","properties":{"query":{"type":"string"}}}`),
			OutputSchema: []byte(`{"type":"object"}`),
			Executor:     api.ExecutorBinding{Kind: "runtime-capability", Capability: "knowledge.search"},
		},
	}
	skill := api.Skill{
		APIVersion: api.APIVersion,
		Kind:       api.KindSkill,
		Metadata:   api.Metadata{Name: "research-playbook", Version: "2.0.0"},
		Spec: api.SkillSpec{
			ContentRef:  "registry://skills/research-playbook/2.0.0",
			Digest:      "6c2f0fe2",
			References:  []string{"registry://skills/research-playbook/2.0.0/sources"},
			Description: "Research with cited sources.",
		},
	}
	agent := api.Agent{
		APIVersion: api.APIVersion,
		Kind:       api.KindAgent,
		Metadata:   api.Metadata{Name: "researcher", Version: "3.1.0"},
		Spec: api.AgentSpec{
			Execution:   api.ExecutionSpec{Adapter: "runtime", ModelProfileRef: "research-primary"},
			Instruction: "Return source-backed findings.",
			Tools:       []api.ResourceRef{{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}},
			Skills:      []api.ResourceRef{{Kind: api.KindSkill, Name: skill.Metadata.Name, Version: skill.Metadata.Version}},
			Policy:      api.PolicyMetadata{RequiredPermissions: []string{"knowledge.read"}, RequiredCapabilities: []string{"knowledge.search"}},
		},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterSkill(skill); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindSkill, Name: skill.Metadata.Name, Version: skill.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: agent.Metadata.Name, Version: agent.Metadata.Version}); err != nil {
		t.Fatal(err)
	}

	// The registry owns copies after publication; caller-side mutation cannot
	// change a published version.
	agent.Spec.Tools[0].Name = "mutated"
	tool.Spec.InputSchema[2] = 'x'
	skill.Spec.References[0] = "mutated"
	agent.Spec.Policy.RequiredPermissions[0] = "mutated"

	ref := api.ResourceRef{Kind: api.KindAgent, Name: "researcher", Version: "3.1.0"}
	definition, err := catalog.ResolveAgentDefinition(ref)
	if err != nil {
		t.Fatal(err)
	}
	if err := definition.Validate(); err != nil {
		t.Fatalf("resolved definition must verify: %v", err)
	}
	if definition.Digest == "" || definition.Tools[0].Metadata.Name != "knowledge.search" || definition.Skills[0].Spec.References[0] != "registry://skills/research-playbook/2.0.0/sources" || definition.Agent.Spec.Policy.RequiredPermissions[0] != "knowledge.read" {
		t.Fatalf("unexpected resolved definition: %+v", definition)
	}

	definition.Tools[0].Spec.InputSchema[2] = 'x'
	if err := definition.Validate(); err == nil {
		t.Fatal("changed definition must fail digest verification")
	}

	fresh, err := catalog.ResolveAgentDefinition(ref)
	if err != nil {
		t.Fatal(err)
	}
	if err := fresh.Validate(); err != nil || fresh.Digest != definition.Digest {
		t.Fatalf("registry resolution must be immutable, definition=%+v err=%v", fresh, err)
	}
}

func TestCatalogResolutionRequiresEveryDeclaredDefinition(t *testing.T) {
	catalog := core.NewCatalog()
	agent := api.Agent{
		APIVersion: api.APIVersion,
		Kind:       api.KindAgent,
		Metadata:   api.Metadata{Name: "missing-tool", Version: "1.0.0"},
		Spec: api.AgentSpec{
			Execution:   api.ExecutionSpec{Adapter: "runtime", ModelProfileRef: "primary"},
			Instruction: "Test missing references.",
			Tools:       []api.ResourceRef{{Kind: api.KindTool, Name: "not-registered", Version: "1.0.0"}},
		},
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: agent.Metadata.Name, Version: agent.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	_, err := catalog.ResolveAgentDefinition(api.ResourceRef{Kind: api.KindAgent, Name: "missing-tool", Version: "1.0.0"})
	if !errors.Is(err, core.ErrNotFound) {
		t.Fatalf("expected unresolved declaration to fail, got %v", err)
	}
}

func TestCatalogPublicationLifecycleFiltersLookup(t *testing.T) {
	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion, Kind: api.KindTool,
		Metadata: api.Metadata{Name: "status.read", Version: "1.0.0"},
		Spec: api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: api.ExecutorBinding{Kind: "runtime-capability", Capability: "status.read"}},
	}
	agent := api.Agent{
		APIVersion: api.APIVersion, Kind: api.KindAgent,
		Metadata: api.Metadata{Name: "operator", Version: "1.0.0"},
		Spec: api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "runtime", ModelProfileRef: "primary"}, Instruction: "Read status.", Tools: []api.ResourceRef{{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	ref := api.ResourceRef{Kind: api.KindAgent, Name: agent.Metadata.Name, Version: agent.Metadata.Version}
	if _, err := catalog.ResolveAgentDefinition(ref); !errors.Is(err, core.ErrNotFound) {
		t.Fatalf("draft agent must not resolve, got %v", err)
	}
	if got := catalog.ListPublishedAgents(); len(got) != 0 {
		t.Fatalf("draft agent must not be listed, got %v", got)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(ref); err != nil {
		t.Fatal(err)
	}
	if _, err := catalog.ResolveAgentDefinition(ref); err != nil {
		t.Fatalf("published agent must resolve, got %v", err)
	}
	if got := catalog.ListPublishedAgents(); len(got) != 1 || got[0].Metadata.Key() != agent.Metadata.Key() {
		t.Fatalf("published agent must be listed, got %v", got)
	}
	if err := catalog.Retire(ref); err != nil {
		t.Fatal(err)
	}
	if _, err := catalog.ResolveAgentDefinition(ref); !errors.Is(err, core.ErrNotFound) {
		t.Fatalf("retired agent must not resolve, got %v", err)
	}
	if got := catalog.ListPublishedAgents(); len(got) != 0 {
		t.Fatalf("retired agent must not be listed, got %v", got)
	}
	if _, err := catalog.PublishedAgent(ref); !errors.Is(err, core.ErrNotFound) {
		t.Fatalf("retired lookup must be not found, got %v", err)
	}
}

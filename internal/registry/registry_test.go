package registry_test

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"

	"github.com/webong/grimoire/internal/registry"
	"github.com/webong/grimoire/internal/registry/packstore"
)

func TestRegistryPublishesResolvesAndMaterializesBundle(t *testing.T) {
	ctx := context.Background()
	store, err := packstore.Open(filepath.Join(t.TempDir(), "registry.grim"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = store.Close() })
	reg, err := registry.Open(store)
	if err != nil {
		t.Fatal(err)
	}

	runtimeArtifact, err := reg.PutArtifact(ctx, []byte("runtime executable"))
	if err != nil {
		t.Fatal(err)
	}
	skillArtifact, err := reg.PutArtifact(ctx, []byte("skill instructions"))
	if err != nil {
		t.Fatal(err)
	}

	toolRef := registry.Ref{Kind: registry.KindTool, Name: "document-reader", Version: "2.0.0"}
	importAndPublish(t, ctx, reg, registry.Tool{
		APIVersion: registry.APIVersion, Kind: registry.KindTool,
		Metadata: registry.Metadata{Name: toolRef.Name, Version: toolRef.Version},
		Spec:     registry.ToolSpec{Effect: "read", InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`)},
	})

	skillRef := registry.Ref{Kind: registry.KindSkill, Name: "summarize", Version: "1.2.0"}
	importAndPublish(t, ctx, reg, registry.Skill{
		APIVersion: registry.APIVersion, Kind: registry.KindSkill,
		Metadata: registry.Metadata{Name: skillRef.Name, Version: skillRef.Version},
		Spec: registry.SkillSpec{
			Artifact: registry.Artifact{Digest: string(skillArtifact), MediaType: "text/markdown"},
			Requires: registry.SkillRequirements{Capabilities: []string{"text", "tool-calling"}, Tools: []registry.Ref{toolRef}},
		},
	})

	runtimeRef := registry.Ref{Kind: registry.KindRuntime, Name: "laravel-ai", Version: "1.0.0"}
	importAndPublish(t, ctx, reg, registry.Runtime{
		APIVersion: registry.APIVersion, Kind: registry.KindRuntime,
		Metadata: registry.Metadata{Name: runtimeRef.Name, Version: runtimeRef.Version},
		Spec: registry.RuntimeSpec{
			Protocol:     "grimoire-runtime/v1alpha1",
			Artifact:     registry.Artifact{Digest: string(runtimeArtifact), MediaType: "application/octet-stream"},
			Entrypoint:   registry.Entrypoint{Kind: "process", Command: []string{"php", "runtime.php"}},
			Capabilities: []string{"text", "tool-calling", "streaming"},
		},
	})

	resolution, err := reg.Resolve(ctx, registry.ResolveRequest{Runtime: runtimeRef, Skills: []registry.Ref{skillRef}})
	if err != nil {
		t.Fatal(err)
	}
	if err := resolution.Validate(); err != nil {
		t.Fatal(err)
	}
	if len(resolution.Tools) != 1 || resolution.Tools[0].Ref != toolRef {
		t.Fatalf("skill dependency was not locked: %+v", resolution.Tools)
	}

	destination := filepath.Join(t.TempDir(), "sandbox")
	if err := reg.Materialize(ctx, resolution, destination); err != nil {
		t.Fatal(err)
	}
	assertFile(t, filepath.Join(destination, "resolution.lock"))
	assertFile(t, filepath.Join(destination, "runtime", "artifact"))
	assertFile(t, filepath.Join(destination, "skills", "summarize@1.2.0", "artifact"))
	assertFile(t, filepath.Join(destination, "tools", "document-reader@2.0.0", "manifest.json"))
	if info, err := os.Stat(filepath.Join(destination, "scratch")); err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("scratch mode = %v, %v", info, err)
	}
	if info, err := os.Stat(filepath.Join(destination, "runtime", "artifact")); err != nil || info.Mode().Perm()&0o222 != 0 {
		t.Fatalf("runtime artifact is writable: %v, %v", info, err)
	}
}

func TestResolutionRejectsMissingRuntimeCapability(t *testing.T) {
	ctx := context.Background()
	store, err := packstore.Open(filepath.Join(t.TempDir(), "registry.grim"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = store.Close() })
	reg, _ := registry.Open(store)
	runtimeArtifact, _ := reg.PutArtifact(ctx, []byte("runtime"))
	skillArtifact, _ := reg.PutArtifact(ctx, []byte("skill"))
	runtimeRef := registry.Ref{Kind: registry.KindRuntime, Name: "basic", Version: "1.0.0"}
	skillRef := registry.Ref{Kind: registry.KindSkill, Name: "browser", Version: "1.0.0"}
	importAndPublish(t, ctx, reg, registry.Runtime{
		APIVersion: registry.APIVersion, Kind: registry.KindRuntime, Metadata: registry.Metadata{Name: "basic", Version: "1.0.0"},
		Spec: registry.RuntimeSpec{Protocol: "grimoire-runtime/v1alpha1", Artifact: registry.Artifact{Digest: string(runtimeArtifact)}, Entrypoint: registry.Entrypoint{Kind: "process", Command: []string{"run"}}, Capabilities: []string{"text"}},
	})
	importAndPublish(t, ctx, reg, registry.Skill{
		APIVersion: registry.APIVersion, Kind: registry.KindSkill, Metadata: registry.Metadata{Name: "browser", Version: "1.0.0"},
		Spec: registry.SkillSpec{Artifact: registry.Artifact{Digest: string(skillArtifact)}, Requires: registry.SkillRequirements{Capabilities: []string{"browser"}}},
	})
	if _, err := reg.Resolve(ctx, registry.ResolveRequest{Runtime: runtimeRef, Skills: []registry.Ref{skillRef}}); err == nil {
		t.Fatal("expected capability mismatch")
	}
}

func importAndPublish(t *testing.T, ctx context.Context, reg *registry.Registry, value any) registry.Reference {
	t.Helper()
	raw, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	reference, err := reg.ImportManifest(ctx, raw)
	if err != nil {
		t.Fatal(err)
	}
	reference, err = reg.Publish(ctx, reference.Ref)
	if err != nil {
		t.Fatal(err)
	}
	return reference
}

func assertFile(t *testing.T, path string) {
	t.Helper()
	if info, err := os.Stat(path); err != nil || !info.Mode().IsRegular() {
		t.Fatalf("expected file %s: %v", path, err)
	}
}

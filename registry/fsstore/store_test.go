package fsstore_test

import (
	"context"
	"errors"
	"testing"

	"github.com/webong/grimoire/registry"
	"github.com/webong/grimoire/registry/fsstore"
)

func TestFilesystemStorePersistsObjectsAndCASReferences(t *testing.T) {
	ctx := context.Background()
	root := t.TempDir()
	store, err := fsstore.Open(root)
	if err != nil {
		t.Fatal(err)
	}
	digest, err := store.PutObject(ctx, []byte("runtime artifact"))
	if err != nil {
		t.Fatal(err)
	}
	if got, err := store.GetObject(ctx, digest); err != nil || string(got) != "runtime artifact" {
		t.Fatalf("get object = %q, %v", got, err)
	}
	ref := registry.Ref{Kind: registry.KindRuntime, Name: "test-runtime", Version: "1.0.0"}
	created, err := store.PutReference(ctx, registry.Reference{Ref: ref, Digest: digest, Status: registry.PublicationDraft}, nil)
	if err != nil {
		t.Fatal(err)
	}
	stale := "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
	if _, err := store.PutReference(ctx, created, &stale); !errors.Is(err, registry.ErrConflict) {
		t.Fatalf("stale write error = %v", err)
	}
	created.Status = registry.PublicationPublished
	updated, err := store.PutReference(ctx, created, &created.Revision)
	if err != nil {
		t.Fatal(err)
	}
	if updated.Revision == created.Revision {
		t.Fatal("reference revision did not change")
	}

	reopened, err := fsstore.Open(root)
	if err != nil {
		t.Fatal(err)
	}
	loaded, err := reopened.GetReference(ctx, ref)
	if err != nil {
		t.Fatal(err)
	}
	if loaded.Status != registry.PublicationPublished || loaded.Digest != digest {
		t.Fatalf("unexpected persisted reference: %+v", loaded)
	}
}

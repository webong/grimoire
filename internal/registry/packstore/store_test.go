package packstore_test

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"testing"

	"github.com/webong/grimoire/internal/registry"
	"github.com/webong/grimoire/internal/registry/packstore"
)

func TestStorePersistsObjectsAndReferences(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "catalog.grim")
	store, err := packstore.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	digest, err := store.PutObject(ctx, []byte("runtime artifact"))
	if err != nil {
		t.Fatal(err)
	}
	ref := registry.Ref{Kind: registry.KindRuntime, Name: "test-runtime", Version: "1.0.0"}
	created, err := store.PutReference(ctx, registry.Reference{
		Ref: ref, Digest: digest, Status: registry.PublicationDraft,
	}, nil)
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
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}

	reopened, err := packstore.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = reopened.Close() })
	got, err := reopened.GetObject(ctx, digest)
	if err != nil || string(got) != "runtime artifact" {
		t.Fatalf("get object = %q, %v", got, err)
	}
	loaded, err := reopened.GetReference(ctx, ref)
	if err != nil {
		t.Fatal(err)
	}
	if loaded.Status != registry.PublicationPublished || loaded.Digest != digest {
		t.Fatalf("unexpected persisted reference: %+v", loaded)
	}
}

func TestOpenRejectsConcurrentWriter(t *testing.T) {
	path := filepath.Join(t.TempDir(), "catalog.grim")
	store, err := packstore.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = store.Close() })
	if second, err := packstore.Open(path); err == nil {
		_ = second.Close()
		t.Fatal("second open unexpectedly acquired the database lock")
	}
}

func TestOpenRecoversIncompleteTrailingFrame(t *testing.T) {
	ctx := context.Background()
	path := filepath.Join(t.TempDir(), "catalog.grim")
	store, err := packstore.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	digest, err := store.PutObject(ctx, []byte("durable"))
	if err != nil {
		t.Fatal(err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
	validInfo, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	file, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := file.Write([]byte{1, 2, 3, 4, 5}); err != nil {
		file.Close()
		t.Fatal(err)
	}
	if err := file.Close(); err != nil {
		t.Fatal(err)
	}

	recovered, err := packstore.Open(path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = recovered.Close() })
	got, err := recovered.GetObject(ctx, digest)
	if err != nil || string(got) != "durable" {
		t.Fatalf("recovered object = %q, %v", got, err)
	}
	recoveredInfo, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if recoveredInfo.Size() != validInfo.Size() {
		t.Fatalf("recovered size = %d, want %d", recoveredInfo.Size(), validInfo.Size())
	}
}

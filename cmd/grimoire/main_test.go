package main

import (
	"context"
	"os"
	"path/filepath"
	"testing"
)

func TestInitCreatesSingleBinaryDatabase(t *testing.T) {
	path := filepath.Join(t.TempDir(), "catalog.grim")
	if err := run(context.Background(), []string{"init", "-db", path}); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if len(raw) != 16 {
		t.Fatalf("database header length = %d, want 16", len(raw))
	}
	if got := string(raw[:8]); got != "GRIMDB01" {
		t.Fatalf("database magic = %q", got)
	}
}

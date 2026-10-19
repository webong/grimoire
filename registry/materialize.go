package registry

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
)

// Materialize writes a verified, read-only resolution bundle and a writable
// scratch directory. Artifacts remain opaque; a runtime host decides how to
// interpret them after admission.
func (r *Registry) Materialize(ctx context.Context, resolution Resolution, destination string) error {
	if err := resolution.Validate(); err != nil {
		return err
	}
	if destination == "" {
		return errors.New("sandbox destination is required")
	}
	absolute, err := filepath.Abs(destination)
	if err != nil {
		return err
	}
	if _, err := os.Stat(absolute); err == nil {
		return fmt.Errorf("sandbox destination already exists: %s", absolute)
	} else if !errors.Is(err, os.ErrNotExist) {
		return err
	}
	parent := filepath.Dir(absolute)
	if err := os.MkdirAll(parent, 0o755); err != nil {
		return err
	}
	temporary, err := os.MkdirTemp(parent, ".grimoire-sandbox-*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(temporary)

	lock, err := json.MarshalIndent(resolution, "", "  ")
	if err != nil {
		return err
	}
	if err := os.WriteFile(filepath.Join(temporary, "resolution.lock"), lock, 0o444); err != nil {
		return err
	}
	if err := r.materializeLocked(ctx, temporary, "runtime", resolution.Runtime); err != nil {
		return err
	}
	for _, item := range resolution.Skills {
		if err := r.materializeLocked(ctx, temporary, filepath.Join("skills", item.Ref.Name+"@"+item.Ref.Version), item); err != nil {
			return err
		}
	}
	for _, item := range resolution.Tools {
		if err := r.materializeLocked(ctx, temporary, filepath.Join("tools", item.Ref.Name+"@"+item.Ref.Version), item); err != nil {
			return err
		}
	}
	for _, item := range resolution.Policies {
		if err := r.materializeLocked(ctx, temporary, filepath.Join("policies", item.Ref.Name+"@"+item.Ref.Version), item); err != nil {
			return err
		}
	}
	if err := os.Mkdir(filepath.Join(temporary, "scratch"), 0o700); err != nil {
		return err
	}
	return os.Rename(temporary, absolute)
}

func (r *Registry) materializeLocked(ctx context.Context, root, relative string, locked LockedResource) error {
	if !validSegment(locked.Ref.Name) || !validSegment(locked.Ref.Version) {
		return errors.New("locked resource contains unsafe path segments")
	}
	directory := filepath.Join(root, relative)
	if err := os.MkdirAll(directory, 0o755); err != nil {
		return err
	}
	manifest, err := r.store.GetObject(ctx, locked.ManifestDigest)
	if err != nil {
		return err
	}
	decoded, canonical, err := DecodeManifest(manifest)
	if err != nil {
		return err
	}
	if decoded.Ref() != locked.Ref || DigestBytes(canonical) != locked.ManifestDigest {
		return fmt.Errorf("locked manifest %s failed verification", locked.Ref.Key())
	}
	if err := os.WriteFile(filepath.Join(directory, "manifest.json"), canonical, 0o444); err != nil {
		return err
	}
	if locked.ArtifactDigest != "" {
		artifact, err := r.store.GetObject(ctx, locked.ArtifactDigest)
		if err != nil {
			return err
		}
		if DigestBytes(artifact) != locked.ArtifactDigest {
			return errors.New("artifact digest mismatch")
		}
		if err := os.WriteFile(filepath.Join(directory, "artifact"), artifact, 0o444); err != nil {
			return err
		}
	}
	return nil
}

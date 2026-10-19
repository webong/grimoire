// Package fsstore provides a filesystem-backed Grimoire object and reference store.
package fsstore

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"

	"github.com/webong/grimoire/registry"
)

type Store struct {
	root string
	mu   sync.Mutex
}

func Open(root string) (*Store, error) {
	if strings.TrimSpace(root) == "" {
		return nil, errors.New("store root is required")
	}
	absolute, err := filepath.Abs(root)
	if err != nil {
		return nil, err
	}
	for _, directory := range []string{"objects/sha256", "refs", "resolutions", "indexes"} {
		if err := os.MkdirAll(filepath.Join(absolute, directory), 0o755); err != nil {
			return nil, err
		}
	}
	return &Store{root: absolute}, nil
}

func (s *Store) PutObject(ctx context.Context, raw []byte) (registry.Digest, error) {
	if err := ctx.Err(); err != nil {
		return "", err
	}
	digest := registry.DigestBytes(raw)
	path, err := s.objectPath(digest)
	if err != nil {
		return "", err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	if existing, err := os.ReadFile(path); err == nil {
		if registry.DigestBytes(existing) != digest {
			return "", errors.New("stored object does not match its digest")
		}
		return digest, nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return "", err
	}
	if err := atomicWrite(path, raw, 0o444); err != nil {
		return "", err
	}
	return digest, nil
}

func (s *Store) GetObject(ctx context.Context, digest registry.Digest) ([]byte, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	path, err := s.objectPath(digest)
	if err != nil {
		return nil, err
	}
	raw, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, registry.ErrNotFound
	}
	if err != nil {
		return nil, err
	}
	if registry.DigestBytes(raw) != digest {
		return nil, errors.New("stored object does not match its digest")
	}
	return raw, nil
}

func (s *Store) GetReference(ctx context.Context, ref registry.Ref) (registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return registry.Reference{}, err
	}
	path, err := s.referencePath(ref)
	if err != nil {
		return registry.Reference{}, err
	}
	raw, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return registry.Reference{}, registry.ErrNotFound
	}
	if err != nil {
		return registry.Reference{}, err
	}
	var value registry.Reference
	if err := json.Unmarshal(raw, &value); err != nil {
		return registry.Reference{}, err
	}
	return value, nil
}

func (s *Store) PutReference(ctx context.Context, value registry.Reference, expectedRevision *string) (registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return registry.Reference{}, err
	}
	path, err := s.referencePath(value.Ref)
	if err != nil {
		return registry.Reference{}, err
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	currentRaw, readErr := os.ReadFile(path)
	if expectedRevision == nil {
		if readErr == nil {
			return registry.Reference{}, registry.ErrConflict
		}
		if !errors.Is(readErr, os.ErrNotExist) {
			return registry.Reference{}, readErr
		}
	} else {
		if errors.Is(readErr, os.ErrNotExist) {
			return registry.Reference{}, registry.ErrConflict
		}
		if readErr != nil {
			return registry.Reference{}, readErr
		}
		var current registry.Reference
		if err := json.Unmarshal(currentRaw, &current); err != nil {
			return registry.Reference{}, err
		}
		if current.Revision != *expectedRevision {
			return registry.Reference{}, registry.ErrConflict
		}
	}
	value.Revision = ""
	payload, err := json.Marshal(value)
	if err != nil {
		return registry.Reference{}, err
	}
	value.Revision = string(registry.DigestBytes(payload))
	payload, err = json.Marshal(value)
	if err != nil {
		return registry.Reference{}, err
	}
	if err := atomicWrite(path, payload, 0o644); err != nil {
		return registry.Reference{}, err
	}
	return value, nil
}

func (s *Store) ListReferences(ctx context.Context, query registry.ReferenceQuery) ([]registry.Reference, error) {
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	base := filepath.Join(s.root, "refs")
	if query.Kind != "" {
		base = filepath.Join(base, strings.ToLower(string(query.Kind)))
	}
	result := []registry.Reference{}
	err := filepath.WalkDir(base, func(path string, entry os.DirEntry, walkErr error) error {
		if walkErr != nil {
			if errors.Is(walkErr, os.ErrNotExist) {
				return nil
			}
			return walkErr
		}
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".json") {
			return nil
		}
		if err := ctx.Err(); err != nil {
			return err
		}
		raw, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		var value registry.Reference
		if err := json.Unmarshal(raw, &value); err != nil {
			return err
		}
		if query.Kind != "" && value.Ref.Kind != query.Kind {
			return nil
		}
		if query.Status != "" && value.Status != query.Status {
			return nil
		}
		result = append(result, value)
		return nil
	})
	if err != nil && !errors.Is(err, os.ErrNotExist) {
		return nil, err
	}
	sort.Slice(result, func(i, j int) bool { return result[i].Ref.Key() < result[j].Ref.Key() })
	return result, nil
}

func (s *Store) objectPath(digest registry.Digest) (string, error) {
	parsed, err := registry.ParseDigest(string(digest))
	if err != nil {
		return "", err
	}
	hex := strings.TrimPrefix(string(parsed), "sha256:")
	return filepath.Join(s.root, "objects", "sha256", hex[:2], hex[2:]), nil
}

func (s *Store) referencePath(ref registry.Ref) (string, error) {
	if err := ref.Validate(ref.Kind); err != nil {
		return "", err
	}
	switch ref.Kind {
	case registry.KindRuntime, registry.KindSkill, registry.KindTool, registry.KindPolicy:
	default:
		return "", fmt.Errorf("unsupported resource kind %q", ref.Kind)
	}
	return filepath.Join(s.root, "refs", strings.ToLower(string(ref.Kind)), ref.Name, ref.Version+".json"), nil
}

func atomicWrite(path string, raw []byte, mode os.FileMode) error {
	directory := filepath.Dir(path)
	if err := os.MkdirAll(directory, 0o755); err != nil {
		return err
	}
	temporary, err := os.CreateTemp(directory, ".write-*")
	if err != nil {
		return err
	}
	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)
	if _, err := temporary.Write(raw); err != nil {
		temporary.Close()
		return err
	}
	if err := temporary.Sync(); err != nil {
		temporary.Close()
		return err
	}
	if err := temporary.Chmod(mode); err != nil {
		temporary.Close()
		return err
	}
	if err := temporary.Close(); err != nil {
		return err
	}
	return os.Rename(temporaryPath, path)
}

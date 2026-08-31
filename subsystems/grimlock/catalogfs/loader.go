// Package catalogfs loads versioned Grimlock resources from JSON manifests.
package catalogfs

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

func Load(catalog *core.Catalog, directory string) error {
	if catalog == nil {
		return fmt.Errorf("catalog is required")
	}
	entries, err := os.ReadDir(directory)
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		path := filepath.Join(directory, entry.Name())
		raw, err := os.ReadFile(path)
		if err != nil {
			return err
		}
		var envelope struct {
			Kind api.ResourceKind `json:"kind"`
		}
		if err := json.Unmarshal(raw, &envelope); err != nil {
			return fmt.Errorf("%s: %w", path, err)
		}
		if err := register(catalog, envelope.Kind, raw); err != nil {
			return fmt.Errorf("%s: %w", path, err)
		}
	}
	return nil
}

func register(catalog *core.Catalog, kind api.ResourceKind, raw []byte) error {
	switch kind {
	case api.KindAgent:
		var value api.Agent
		return decodeAndRegister(raw, &value, catalog.RegisterAgent)
	case api.KindTool:
		var value api.Tool
		return decodeAndRegister(raw, &value, catalog.RegisterTool)
	case api.KindSkill:
		var value api.Skill
		return decodeAndRegister(raw, &value, catalog.RegisterSkill)
	case api.KindClient:
		var value api.Client
		return decodeAndRegister(raw, &value, catalog.RegisterClient)
	case api.KindDevice:
		var value api.Device
		return decodeAndRegister(raw, &value, catalog.RegisterDevice)
	case api.KindConnector:
		var value api.Connector
		return decodeAndRegister(raw, &value, catalog.RegisterConnector)
	default:
		return fmt.Errorf("unsupported resource kind %q", kind)
	}
}

func decodeAndRegister[T any](raw []byte, value *T, register func(T) error) error {
	if err := json.Unmarshal(raw, value); err != nil {
		return err
	}
	return register(*value)
}

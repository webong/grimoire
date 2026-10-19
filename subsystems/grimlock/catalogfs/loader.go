// Package catalogfs loads versioned Grimlock resources from JSON manifests.
package catalogfs

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/webong/grimoire/subsystems/grimlock/core"
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
		if err := decodeAndRegister(raw, &value, catalog.RegisterAgent); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: value.Metadata.Name, Version: value.Metadata.Version})
	case api.KindTool:
		var value api.Tool
		if err := decodeAndRegister(raw, &value, catalog.RegisterTool); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: value.Metadata.Name, Version: value.Metadata.Version})
	case api.KindSkill:
		var value api.Skill
		if err := decodeAndRegister(raw, &value, catalog.RegisterSkill); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindSkill, Name: value.Metadata.Name, Version: value.Metadata.Version})
	case api.KindClient:
		var value api.Client
		if err := decodeAndRegister(raw, &value, catalog.RegisterClient); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindClient, Name: value.Metadata.Name, Version: value.Metadata.Version})
	case api.KindDevice:
		var value api.Device
		if err := decodeAndRegister(raw, &value, catalog.RegisterDevice); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindDevice, Name: value.Metadata.Name, Version: value.Metadata.Version})
	case api.KindConnector:
		var value api.Connector
		if err := decodeAndRegister(raw, &value, catalog.RegisterConnector); err != nil {
			return err
		}
		return catalog.Publish(api.ResourceRef{Kind: api.KindConnector, Name: value.Metadata.Name, Version: value.Metadata.Version})
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

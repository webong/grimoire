// Package core contains Grimlock's engine-neutral management services.
package core

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"sync"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
)

var ErrNotFound = errors.New("grimlock resource not found")

type CatalogResource struct {
	Kind     api.ResourceKind
	Metadata api.Metadata
	Digest   string
	Spec     json.RawMessage
}

// Catalog keeps immutable resource versions. Re-registering a version is
// refused so an execution can always be tied to its exact definition.
type Catalog struct {
	mu         sync.RWMutex
	agents     map[string]api.Agent
	tools      map[string]api.Tool
	skills     map[string]api.Skill
	clients    map[string]api.Client
	devices    map[string]api.Device
	connectors map[string]api.Connector
}

func NewCatalog() *Catalog {
	return &Catalog{
		agents: map[string]api.Agent{}, tools: map[string]api.Tool{}, skills: map[string]api.Skill{},
		clients: map[string]api.Client{}, devices: map[string]api.Device{}, connectors: map[string]api.Connector{},
	}
}

func (c *Catalog) RegisterAgent(agent api.Agent) error {
	if err := agent.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := agent.Metadata.Key()
	if _, exists := c.agents[key]; exists {
		return fmt.Errorf("agent %q is already registered", key)
	}
	c.agents[key] = agent
	return nil
}

func (c *Catalog) RegisterTool(tool api.Tool) error {
	if err := tool.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := tool.Metadata.Key()
	if _, exists := c.tools[key]; exists {
		return fmt.Errorf("tool %q is already registered", key)
	}
	c.tools[key] = tool
	return nil
}

func (c *Catalog) RegisterSkill(skill api.Skill) error {
	if err := skill.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := skill.Metadata.Key()
	if _, exists := c.skills[key]; exists {
		return fmt.Errorf("skill %q is already registered", key)
	}
	c.skills[key] = skill
	return nil
}

func (c *Catalog) RegisterClient(client api.Client) error {
	if err := client.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := client.Metadata.Key()
	if _, exists := c.clients[key]; exists {
		return fmt.Errorf("client %q is already registered", key)
	}
	c.clients[key] = client
	return nil
}

func (c *Catalog) RegisterDevice(device api.Device) error {
	if err := device.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := device.Metadata.Key()
	if _, exists := c.devices[key]; exists {
		return fmt.Errorf("device %q is already registered", key)
	}
	c.devices[key] = device
	return nil
}

func (c *Catalog) RegisterConnector(connector api.Connector) error {
	if err := connector.Validate(); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := connector.Metadata.Key()
	if _, exists := c.connectors[key]; exists {
		return fmt.Errorf("connector %q is already registered", key)
	}
	c.connectors[key] = connector
	return nil
}

func (c *Catalog) Agent(ref api.ResourceRef) (api.Agent, error) {
	if err := ref.Validate(api.KindAgent); err != nil {
		return api.Agent{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.agents[ref.Key()]
	if !ok {
		return api.Agent{}, fmt.Errorf("agent %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) Tool(ref api.ResourceRef) (api.Tool, error) {
	if err := ref.Validate(api.KindTool); err != nil {
		return api.Tool{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.tools[ref.Key()]
	if !ok {
		return api.Tool{}, fmt.Errorf("tool %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) Client(ref api.ResourceRef) (api.Client, error) {
	if err := ref.Validate(api.KindClient); err != nil {
		return api.Client{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.clients[ref.Key()]
	if !ok {
		return api.Client{}, fmt.Errorf("client %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) Device(ref api.ResourceRef) (api.Device, error) {
	if err := ref.Validate(api.KindDevice); err != nil {
		return api.Device{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.devices[ref.Key()]
	if !ok {
		return api.Device{}, fmt.Errorf("device %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) Connector(ref api.ResourceRef) (api.Connector, error) {
	if err := ref.Validate(api.KindConnector); err != nil {
		return api.Connector{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.connectors[ref.Key()]
	if !ok {
		return api.Connector{}, fmt.Errorf("connector %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

// Resources returns immutable snapshots suitable for synchronizing a durable
// catalogue. The digest covers the stored spec, not host-specific metadata.
func (c *Catalog) Resources() []CatalogResource {
	c.mu.RLock()
	defer c.mu.RUnlock()

	resources := make([]CatalogResource, 0, len(c.agents)+len(c.tools)+len(c.skills)+len(c.clients)+len(c.devices)+len(c.connectors))
	appendResource := func(kind api.ResourceKind, metadata api.Metadata, spec any) {
		raw, err := json.Marshal(spec)
		if err != nil {
			return
		}
		sum := sha256.Sum256(raw)
		resources = append(resources, CatalogResource{Kind: kind, Metadata: metadata, Digest: hex.EncodeToString(sum[:]), Spec: raw})
	}
	for _, value := range c.agents {
		appendResource(api.KindAgent, value.Metadata, value.Spec)
	}
	for _, value := range c.tools {
		appendResource(api.KindTool, value.Metadata, value.Spec)
	}
	for _, value := range c.skills {
		appendResource(api.KindSkill, value.Metadata, value.Spec)
	}
	for _, value := range c.clients {
		appendResource(api.KindClient, value.Metadata, value.Spec)
	}
	for _, value := range c.devices {
		appendResource(api.KindDevice, value.Metadata, value.Spec)
	}
	for _, value := range c.connectors {
		appendResource(api.KindConnector, value.Metadata, value.Spec)
	}
	return resources
}

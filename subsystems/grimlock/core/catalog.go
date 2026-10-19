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

type PublicationStatus string

const (
	PublicationDraft     PublicationStatus = "draft"
	PublicationPublished PublicationStatus = "published"
	PublicationRetired   PublicationStatus = "retired"
)

func (s PublicationStatus) Validate() error {
	switch s {
	case PublicationDraft, PublicationPublished, PublicationRetired:
		return nil
	default:
		return errors.New("invalid publication status")
	}
}

type CatalogResource struct {
	Kind     api.ResourceKind
	Metadata api.Metadata
	Digest   string
	Spec     json.RawMessage
	Status   PublicationStatus
}

// Catalog keeps immutable registry versions. Re-registering a version is
// refused so downstream runtimes can always resolve an exact registered definition.
// Each version carries a publication lifecycle state. Only published versions are
// exposed through lookup and resolution. Retired is terminal.
type Catalog struct {
	mu                sync.RWMutex
	agents            map[string]api.Agent
	tools             map[string]api.Tool
	skills            map[string]api.Skill
	clients           map[string]api.Client
	devices           map[string]api.Device
	connectors        map[string]api.Connector
	agentStatuses     map[string]PublicationStatus
	toolStatuses      map[string]PublicationStatus
	skillStatuses     map[string]PublicationStatus
	clientStatuses    map[string]PublicationStatus
	deviceStatuses    map[string]PublicationStatus
	connectorStatuses map[string]PublicationStatus
}

func NewCatalog() *Catalog {
	return &Catalog{
		agents: map[string]api.Agent{}, tools: map[string]api.Tool{}, skills: map[string]api.Skill{},
		clients: map[string]api.Client{}, devices: map[string]api.Device{}, connectors: map[string]api.Connector{},
		agentStatuses: map[string]PublicationStatus{}, toolStatuses: map[string]PublicationStatus{}, skillStatuses: map[string]PublicationStatus{},
		clientStatuses: map[string]PublicationStatus{}, deviceStatuses: map[string]PublicationStatus{}, connectorStatuses: map[string]PublicationStatus{},
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
	c.agents[key] = agent.Clone()
	c.agentStatuses[key] = PublicationDraft
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
	c.tools[key] = tool.Clone()
	c.toolStatuses[key] = PublicationDraft
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
	c.skills[key] = skill.Clone()
	c.skillStatuses[key] = PublicationDraft
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
	c.clientStatuses[key] = PublicationDraft
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
	c.deviceStatuses[key] = PublicationDraft
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
	c.connectorStatuses[key] = PublicationDraft
	return nil
}

func (c *Catalog) Publish(ref api.ResourceRef) error {
	if err := ref.Validate(ref.Kind); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := ref.Key()
	switch ref.Kind {
	case api.KindAgent:
		status, ok := c.agentStatuses[key]
		if !ok {
			return fmt.Errorf("agent %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("agent %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		if _, exists := c.agents[key]; !exists {
			return fmt.Errorf("agent %s: %w", key, ErrNotFound)
		}
		c.agentStatuses[key] = PublicationPublished
	case api.KindTool:
		status, ok := c.toolStatuses[key]
		if !ok {
			return fmt.Errorf("tool %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("tool %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		c.toolStatuses[key] = PublicationPublished
	case api.KindSkill:
		status, ok := c.skillStatuses[key]
		if !ok {
			return fmt.Errorf("skill %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("skill %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		c.skillStatuses[key] = PublicationPublished
	case api.KindClient:
		status, ok := c.clientStatuses[key]
		if !ok {
			return fmt.Errorf("client %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("client %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		c.clientStatuses[key] = PublicationPublished
	case api.KindDevice:
		status, ok := c.deviceStatuses[key]
		if !ok {
			return fmt.Errorf("device %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("device %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		c.deviceStatuses[key] = PublicationPublished
	case api.KindConnector:
		status, ok := c.connectorStatuses[key]
		if !ok {
			return fmt.Errorf("connector %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return fmt.Errorf("connector %s is retired", key)
		}
		if status == PublicationPublished {
			return nil
		}
		c.connectorStatuses[key] = PublicationPublished
	default:
		return fmt.Errorf("unsupported resource kind %q", ref.Kind)
	}
	return nil
}

func (c *Catalog) Retire(ref api.ResourceRef) error {
	if err := ref.Validate(ref.Kind); err != nil {
		return err
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	key := ref.Key()
	switch ref.Kind {
	case api.KindAgent:
		status, ok := c.agentStatuses[key]
		if !ok {
			return fmt.Errorf("agent %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("agent %s is not published", key)
		}
		c.agentStatuses[key] = PublicationRetired
	case api.KindTool:
		status, ok := c.toolStatuses[key]
		if !ok {
			return fmt.Errorf("tool %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("tool %s is not published", key)
		}
		c.toolStatuses[key] = PublicationRetired
	case api.KindSkill:
		status, ok := c.skillStatuses[key]
		if !ok {
			return fmt.Errorf("skill %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("skill %s is not published", key)
		}
		c.skillStatuses[key] = PublicationRetired
	case api.KindClient:
		status, ok := c.clientStatuses[key]
		if !ok {
			return fmt.Errorf("client %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("client %s is not published", key)
		}
		c.clientStatuses[key] = PublicationRetired
	case api.KindDevice:
		status, ok := c.deviceStatuses[key]
		if !ok {
			return fmt.Errorf("device %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("device %s is not published", key)
		}
		c.deviceStatuses[key] = PublicationRetired
	case api.KindConnector:
		status, ok := c.connectorStatuses[key]
		if !ok {
			return fmt.Errorf("connector %s: %w", key, ErrNotFound)
		}
		if status == PublicationRetired {
			return nil
		}
		if status != PublicationPublished {
			return fmt.Errorf("connector %s is not published", key)
		}
		c.connectorStatuses[key] = PublicationRetired
	default:
		return fmt.Errorf("unsupported resource kind %q", ref.Kind)
	}
	return nil
}

func (c *Catalog) PublicationStatus(ref api.ResourceRef) (PublicationStatus, error) {
	if err := ref.Validate(ref.Kind); err != nil {
		return "", err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	key := ref.Key()
	switch ref.Kind {
	case api.KindAgent:
		status, ok := c.agentStatuses[key]
		if !ok {
			return "", fmt.Errorf("agent %s: %w", key, ErrNotFound)
		}
		return status, nil
	case api.KindTool:
		status, ok := c.toolStatuses[key]
		if !ok {
			return "", fmt.Errorf("tool %s: %w", key, ErrNotFound)
		}
		return status, nil
	case api.KindSkill:
		status, ok := c.skillStatuses[key]
		if !ok {
			return "", fmt.Errorf("skill %s: %w", key, ErrNotFound)
		}
		return status, nil
	case api.KindClient:
		status, ok := c.clientStatuses[key]
		if !ok {
			return "", fmt.Errorf("client %s: %w", key, ErrNotFound)
		}
		return status, nil
	case api.KindDevice:
		status, ok := c.deviceStatuses[key]
		if !ok {
			return "", fmt.Errorf("device %s: %w", key, ErrNotFound)
		}
		return status, nil
	case api.KindConnector:
		status, ok := c.connectorStatuses[key]
		if !ok {
			return "", fmt.Errorf("connector %s: %w", key, ErrNotFound)
		}
		return status, nil
	default:
		return "", fmt.Errorf("unsupported resource kind %q", ref.Kind)
	}
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
	return value.Clone(), nil
}

func (c *Catalog) PublishedAgent(ref api.ResourceRef) (api.Agent, error) {
	if err := ref.Validate(api.KindAgent); err != nil {
		return api.Agent{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.agents[ref.Key()]
	if !ok {
		return api.Agent{}, fmt.Errorf("agent %s: %w", ref.Key(), ErrNotFound)
	}
	if c.agentStatuses[ref.Key()] != PublicationPublished {
		return api.Agent{}, fmt.Errorf("agent %s: %w", ref.Key(), ErrNotFound)
	}
	return value.Clone(), nil
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
	return value.Clone(), nil
}

func (c *Catalog) PublishedTool(ref api.ResourceRef) (api.Tool, error) {
	if err := ref.Validate(api.KindTool); err != nil {
		return api.Tool{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.tools[ref.Key()]
	if !ok {
		return api.Tool{}, fmt.Errorf("tool %s: %w", ref.Key(), ErrNotFound)
	}
	if c.toolStatuses[ref.Key()] != PublicationPublished {
		return api.Tool{}, fmt.Errorf("tool %s: %w", ref.Key(), ErrNotFound)
	}
	return value.Clone(), nil
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

func (c *Catalog) PublishedClient(ref api.ResourceRef) (api.Client, error) {
	if err := ref.Validate(api.KindClient); err != nil {
		return api.Client{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.clients[ref.Key()]
	if !ok {
		return api.Client{}, fmt.Errorf("client %s: %w", ref.Key(), ErrNotFound)
	}
	if c.clientStatuses[ref.Key()] != PublicationPublished {
		return api.Client{}, fmt.Errorf("client %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) Skill(ref api.ResourceRef) (api.Skill, error) {
	if err := ref.Validate(api.KindSkill); err != nil {
		return api.Skill{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.skills[ref.Key()]
	if !ok {
		return api.Skill{}, fmt.Errorf("skill %s: %w", ref.Key(), ErrNotFound)
	}
	return value.Clone(), nil
}

func (c *Catalog) PublishedSkill(ref api.ResourceRef) (api.Skill, error) {
	if err := ref.Validate(api.KindSkill); err != nil {
		return api.Skill{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.skills[ref.Key()]
	if !ok {
		return api.Skill{}, fmt.Errorf("skill %s: %w", ref.Key(), ErrNotFound)
	}
	if c.skillStatuses[ref.Key()] != PublicationPublished {
		return api.Skill{}, fmt.Errorf("skill %s: %w", ref.Key(), ErrNotFound)
	}
	return value.Clone(), nil
}

// ResolveAgentDefinition returns the immutable artifact a runtime consumes.
// It resolves every declared tool and skill to its exact registered version;
// it does not create sessions, runs, leases, or executable callbacks.
// Only published agent, tool, and skill versions are resolvable; drafts and
// retired versions behave as not found.
func (c *Catalog) ResolveAgentDefinition(ref api.ResourceRef) (api.ResolvedAgentDefinition, error) {
	if err := ref.Validate(api.KindAgent); err != nil {
		return api.ResolvedAgentDefinition{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()

	agent, ok := c.agents[ref.Key()]
	if !ok {
		return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s: %w", ref.Key(), ErrNotFound)
	}
	if c.agentStatuses[ref.Key()] != PublicationPublished {
		return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s: %w", ref.Key(), ErrNotFound)
	}
	tools := make([]api.Tool, len(agent.Spec.Tools))
	for index, toolRef := range agent.Spec.Tools {
		tool, ok := c.tools[toolRef.Key()]
		if !ok {
			return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s tool %s: %w", ref.Key(), toolRef.Key(), ErrNotFound)
		}
		if c.toolStatuses[toolRef.Key()] != PublicationPublished {
			return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s tool %s: %w", ref.Key(), toolRef.Key(), ErrNotFound)
		}
		tools[index] = tool.Clone()
	}
	skills := make([]api.Skill, len(agent.Spec.Skills))
	for index, skillRef := range agent.Spec.Skills {
		skill, ok := c.skills[skillRef.Key()]
		if !ok {
			return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s skill %s: %w", ref.Key(), skillRef.Key(), ErrNotFound)
		}
		if c.skillStatuses[skillRef.Key()] != PublicationPublished {
			return api.ResolvedAgentDefinition{}, fmt.Errorf("agent %s skill %s: %w", ref.Key(), skillRef.Key(), ErrNotFound)
		}
		skills[index] = skill.Clone()
	}
	return api.NewResolvedAgentDefinition(agent.Clone(), tools, skills)
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

func (c *Catalog) PublishedDevice(ref api.ResourceRef) (api.Device, error) {
	if err := ref.Validate(api.KindDevice); err != nil {
		return api.Device{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.devices[ref.Key()]
	if !ok {
		return api.Device{}, fmt.Errorf("device %s: %w", ref.Key(), ErrNotFound)
	}
	if c.deviceStatuses[ref.Key()] != PublicationPublished {
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

func (c *Catalog) PublishedConnector(ref api.ResourceRef) (api.Connector, error) {
	if err := ref.Validate(api.KindConnector); err != nil {
		return api.Connector{}, err
	}
	c.mu.RLock()
	defer c.mu.RUnlock()
	value, ok := c.connectors[ref.Key()]
	if !ok {
		return api.Connector{}, fmt.Errorf("connector %s: %w", ref.Key(), ErrNotFound)
	}
	if c.connectorStatuses[ref.Key()] != PublicationPublished {
		return api.Connector{}, fmt.Errorf("connector %s: %w", ref.Key(), ErrNotFound)
	}
	return value, nil
}

func (c *Catalog) ListPublishedAgents() []api.Agent {
	c.mu.RLock()
	defer c.mu.RUnlock()
	result := make([]api.Agent, 0, len(c.agents))
	for key, agent := range c.agents {
		if c.agentStatuses[key] == PublicationPublished {
			result = append(result, agent.Clone())
		}
	}
	return result
}

func (c *Catalog) ListPublishedTools() []api.Tool {
	c.mu.RLock()
	defer c.mu.RUnlock()
	result := make([]api.Tool, 0, len(c.tools))
	for key, tool := range c.tools {
		if c.toolStatuses[key] == PublicationPublished {
			result = append(result, tool.Clone())
		}
	}
	return result
}

func (c *Catalog) ListPublishedSkills() []api.Skill {
	c.mu.RLock()
	defer c.mu.RUnlock()
	result := make([]api.Skill, 0, len(c.skills))
	for key, skill := range c.skills {
		if c.skillStatuses[key] == PublicationPublished {
			result = append(result, skill.Clone())
		}
	}
	return result
}

// Resources returns immutable snapshots suitable for synchronizing a durable
// catalogue. The digest covers the stored spec, not host-specific metadata.
func (c *Catalog) Resources() []CatalogResource {
	c.mu.RLock()
	defer c.mu.RUnlock()

	resources := make([]CatalogResource, 0, len(c.agents)+len(c.tools)+len(c.skills)+len(c.clients)+len(c.devices)+len(c.connectors))
	appendResource := func(kind api.ResourceKind, metadata api.Metadata, spec any, status PublicationStatus) {
		raw, err := json.Marshal(spec)
		if err != nil {
			return
		}
		sum := sha256.Sum256(raw)
		resources = append(resources, CatalogResource{Kind: kind, Metadata: metadata, Digest: hex.EncodeToString(sum[:]), Spec: raw, Status: status})
	}
	for _, value := range c.agents {
		appendResource(api.KindAgent, value.Metadata, value.Spec, c.agentStatuses[value.Metadata.Key()])
	}
	for _, value := range c.tools {
		appendResource(api.KindTool, value.Metadata, value.Spec, c.toolStatuses[value.Metadata.Key()])
	}
	for _, value := range c.skills {
		appendResource(api.KindSkill, value.Metadata, value.Spec, c.skillStatuses[value.Metadata.Key()])
	}
	for _, value := range c.clients {
		appendResource(api.KindClient, value.Metadata, value.Spec, c.clientStatuses[value.Metadata.Key()])
	}
	for _, value := range c.devices {
		appendResource(api.KindDevice, value.Metadata, value.Spec, c.deviceStatuses[value.Metadata.Key()])
	}
	for _, value := range c.connectors {
		appendResource(api.KindConnector, value.Metadata, value.Spec, c.connectorStatuses[value.Metadata.Key()])
	}
	return resources
}

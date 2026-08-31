// Package v1alpha1 defines Grimlock's portable management contract.
//
// The types deliberately carry descriptors and references, never a model
// provider client, host application's credentials, or an execution engine.
package v1alpha1

import (
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"
)

const APIVersion = "grimlock/v1alpha1"

type ResourceKind string

const (
	KindAgent     ResourceKind = "Agent"
	KindTool      ResourceKind = "Tool"
	KindSkill     ResourceKind = "Skill"
	KindClient    ResourceKind = "Client"
	KindDevice    ResourceKind = "Device"
	KindConnector ResourceKind = "Connector"
)

type Effect string

const (
	EffectRead     Effect = "read"
	EffectWrite    Effect = "write"
	EffectExternal Effect = "external"
)

type ApprovalMode string

const (
	ApprovalDefault  ApprovalMode = "default"
	ApprovalRequired ApprovalMode = "required"
	ApprovalNever    ApprovalMode = "never"
)

type IdempotencyMode string

const (
	IdempotencyNone              IdempotencyMode = "none"
	IdempotencyCallerKeyRequired IdempotencyMode = "caller-key-required"
)

type Metadata struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}

func (m Metadata) Validate() error {
	if strings.TrimSpace(m.Name) == "" {
		return errors.New("metadata.name is required")
	}
	if strings.TrimSpace(m.Version) == "" {
		return errors.New("metadata.version is required")
	}
	return nil
}

func (m Metadata) Key() string { return m.Name + "@" + m.Version }

type ResourceRef struct {
	Kind    ResourceKind `json:"kind"`
	Name    string       `json:"name"`
	Version string       `json:"version"`
}

func (r ResourceRef) Validate(expected ResourceKind) error {
	if r.Kind != expected {
		return fmt.Errorf("resource kind must be %q", expected)
	}
	return Metadata{Name: r.Name, Version: r.Version}.Validate()
}

func (r ResourceRef) Key() string { return r.Name + "@" + r.Version }

type Agent struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       AgentSpec    `json:"spec"`
}

type AgentSpec struct {
	Execution   ExecutionSpec  `json:"execution"`
	Instruction string         `json:"instruction"`
	Tools       []ResourceRef  `json:"tools,omitempty"`
	Skills      []ResourceRef  `json:"skills,omitempty"`
	Budgets     BudgetSpec     `json:"budgets,omitempty"`
	Delegation  DelegationSpec `json:"delegation,omitempty"`
}

type ExecutionSpec struct {
	Adapter         string `json:"adapter"`
	ModelProfileRef string `json:"modelProfileRef"`
}

type BudgetSpec struct {
	MaxToolCalls   int `json:"maxToolCalls,omitempty"`
	TimeoutSeconds int `json:"timeoutSeconds,omitempty"`
}

type DelegationSpec struct {
	AllowedAgents []ResourceRef `json:"allowedAgents,omitempty"`
}

func (a Agent) Validate() error {
	if a.APIVersion != APIVersion || a.Kind != KindAgent {
		return errors.New("invalid agent apiVersion or kind")
	}
	if err := a.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(a.Spec.Execution.Adapter) == "" || strings.TrimSpace(a.Spec.Execution.ModelProfileRef) == "" {
		return errors.New("agent execution adapter and modelProfileRef are required")
	}
	for _, ref := range a.Spec.Tools {
		if err := ref.Validate(KindTool); err != nil {
			return fmt.Errorf("agent tool: %w", err)
		}
	}
	for _, ref := range a.Spec.Skills {
		if err := ref.Validate(KindSkill); err != nil {
			return fmt.Errorf("agent skill: %w", err)
		}
	}
	return nil
}

type Tool struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       ToolSpec     `json:"spec"`
}

type ToolSpec struct {
	Description  string          `json:"description,omitempty"`
	Effect       Effect          `json:"effect"`
	InputSchema  json.RawMessage `json:"inputSchema"`
	OutputSchema json.RawMessage `json:"outputSchema"`
	Idempotency  IdempotencyMode `json:"idempotency,omitempty"`
	Approval     ApprovalMode    `json:"approval,omitempty"`
	Executor     ExecutorBinding `json:"executor"`
}

type ExecutorBinding struct {
	Kind       string `json:"kind"`
	Binding    string `json:"binding,omitempty"`
	Capability string `json:"capability,omitempty"`
}

func (t Tool) Validate() error {
	if t.APIVersion != APIVersion || t.Kind != KindTool {
		return errors.New("invalid tool apiVersion or kind")
	}
	if err := t.Metadata.Validate(); err != nil {
		return err
	}
	switch t.Spec.Effect {
	case EffectRead, EffectWrite, EffectExternal:
	default:
		return errors.New("tool effect must be read, write, or external")
	}
	if !isJSONObject(t.Spec.InputSchema) || !isJSONObject(t.Spec.OutputSchema) {
		return errors.New("tool inputSchema and outputSchema must be JSON objects")
	}
	if t.Spec.Idempotency != "" && t.Spec.Idempotency != IdempotencyNone && t.Spec.Idempotency != IdempotencyCallerKeyRequired {
		return errors.New("invalid tool idempotency mode")
	}
	if t.Spec.Approval != "" && t.Spec.Approval != ApprovalDefault && t.Spec.Approval != ApprovalRequired && t.Spec.Approval != ApprovalNever {
		return errors.New("invalid tool approval mode")
	}
	if strings.TrimSpace(t.Spec.Executor.Kind) == "" {
		return errors.New("tool executor kind is required")
	}
	return nil
}

func (t Tool) RequiresApproval() bool {
	if t.Spec.Approval == ApprovalRequired {
		return true
	}
	if t.Spec.Approval == ApprovalNever {
		return false
	}
	return t.Spec.Effect != EffectRead
}

type Skill struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       SkillSpec    `json:"spec"`
}

type SkillSpec struct {
	Description string   `json:"description,omitempty"`
	ContentRef  string   `json:"contentRef"`
	Digest      string   `json:"digest"`
	References  []string `json:"references,omitempty"`
}

// Client identifies an application, IDE, device bridge, or other caller. It
// contains descriptive metadata only; authentication material lives behind an
// opaque credential reference on a Connector or Device endpoint.
type Client struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       ClientSpec   `json:"spec"`
}

type ClientSpec struct {
	ClientKind   string   `json:"clientKind"`
	DisplayName  string   `json:"displayName,omitempty"`
	Platform     string   `json:"platform,omitempty"`
	AppVersion   string   `json:"appVersion,omitempty"`
	Capabilities []string `json:"capabilities,omitempty"`
}

func (c Client) Validate() error {
	if c.APIVersion != APIVersion || c.Kind != KindClient {
		return errors.New("invalid client apiVersion or kind")
	}
	if err := c.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(c.Spec.ClientKind) == "" {
		return errors.New("client clientKind is required")
	}
	return nil
}

// Device is a caller-owned target such as a browser, display, native host, or
// service. Execution adapters bind a device to host-specific capabilities.
type Device struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       DeviceSpec   `json:"spec"`
}

type DeviceSpec struct {
	ClientRef    *ResourceRef `json:"clientRef,omitempty"`
	DeviceKind   string       `json:"deviceKind"`
	DisplayName  string       `json:"displayName,omitempty"`
	Capabilities []string     `json:"capabilities,omitempty"`
	Endpoints    []Endpoint   `json:"endpoints,omitempty"`
}

type Endpoint struct {
	Name          string `json:"name"`
	Protocol      string `json:"protocol"`
	URL           string `json:"url,omitempty"`
	CredentialRef string `json:"credentialRef,omitempty"`
}

func (d Device) Validate() error {
	if d.APIVersion != APIVersion || d.Kind != KindDevice {
		return errors.New("invalid device apiVersion or kind")
	}
	if err := d.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(d.Spec.DeviceKind) == "" {
		return errors.New("device deviceKind is required")
	}
	if d.Spec.ClientRef != nil {
		if err := d.Spec.ClientRef.Validate(KindClient); err != nil {
			return fmt.Errorf("device clientRef: %w", err)
		}
	}
	for _, endpoint := range d.Spec.Endpoints {
		if strings.TrimSpace(endpoint.Name) == "" || strings.TrimSpace(endpoint.Protocol) == "" {
			return errors.New("device endpoint name and protocol are required")
		}
	}
	return nil
}

// Connector describes an external MCP, A2A, ACP, HTTP, or native boundary.
// CredentialRef is an opaque handle; it is never a secret value.
type Connector struct {
	APIVersion string        `json:"apiVersion"`
	Kind       ResourceKind  `json:"kind"`
	Metadata   Metadata      `json:"metadata"`
	Spec       ConnectorSpec `json:"spec"`
}

type ConnectorSpec struct {
	Protocol          string       `json:"protocol"`
	Transport         string       `json:"transport"`
	Direction         string       `json:"direction"`
	Status            string       `json:"status"`
	Endpoint          string       `json:"endpoint,omitempty"`
	Handler           string       `json:"handler,omitempty"`
	AllowedOperations []string     `json:"allowedOperations"`
	CredentialRef     string       `json:"credentialRef,omitempty"`
	ClientRef         *ResourceRef `json:"clientRef,omitempty"`
	DeviceRef         *ResourceRef `json:"deviceRef,omitempty"`
}

func (c Connector) Validate() error {
	if c.APIVersion != APIVersion || c.Kind != KindConnector {
		return errors.New("invalid connector apiVersion or kind")
	}
	if err := c.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(c.Spec.Protocol) == "" || strings.TrimSpace(c.Spec.Transport) == "" {
		return errors.New("connector protocol and transport are required")
	}
	if c.Spec.Direction != "inbound" && c.Spec.Direction != "outbound" && c.Spec.Direction != "bidirectional" {
		return errors.New("connector direction is invalid")
	}
	if c.Spec.Status != "active" && c.Spec.Status != "paused" && c.Spec.Status != "disabled" {
		return errors.New("connector status is invalid")
	}
	if strings.TrimSpace(c.Spec.Endpoint) == "" && strings.TrimSpace(c.Spec.Handler) == "" {
		return errors.New("connector endpoint or handler is required")
	}
	if c.Spec.ClientRef != nil {
		if err := c.Spec.ClientRef.Validate(KindClient); err != nil {
			return fmt.Errorf("connector clientRef: %w", err)
		}
	}
	if c.Spec.DeviceRef != nil {
		if err := c.Spec.DeviceRef.Validate(KindDevice); err != nil {
			return fmt.Errorf("connector deviceRef: %w", err)
		}
	}
	return nil
}

func (s Skill) Validate() error {
	if s.APIVersion != APIVersion || s.Kind != KindSkill {
		return errors.New("invalid skill apiVersion or kind")
	}
	if err := s.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(s.Spec.ContentRef) == "" || strings.TrimSpace(s.Spec.Digest) == "" {
		return errors.New("skill contentRef and digest are required")
	}
	return nil
}

type Session struct {
	ID        string      `json:"id"`
	SubjectID string      `json:"subjectId"`
	AgentRef  ResourceRef `json:"agentRef"`
	CreatedAt time.Time   `json:"createdAt"`
}

type Run struct {
	ID        string    `json:"id"`
	SessionID string    `json:"sessionId"`
	Status    RunStatus `json:"status"`
	CreatedAt time.Time `json:"createdAt"`
}

type RunStatus string

const (
	RunStatusRunning         RunStatus = "running"
	RunStatusWaitingApproval RunStatus = "waiting_approval"
	RunStatusCompleted       RunStatus = "completed"
	RunStatusFailed          RunStatus = "failed"
	RunStatusCancelled       RunStatus = "cancelled"
)

type Approval struct {
	ID             string         `json:"id"`
	RunID          string         `json:"runId"`
	ToolRef        ResourceRef    `json:"toolRef"`
	ArgumentDigest string         `json:"argumentDigest"`
	Status         ApprovalStatus `json:"status"`
	ExpiresAt      time.Time      `json:"expiresAt"`
}

type ApprovalStatus string

const (
	ApprovalPending  ApprovalStatus = "pending"
	ApprovalApproved ApprovalStatus = "approved"
	ApprovalRejected ApprovalStatus = "rejected"
	ApprovalExpired  ApprovalStatus = "expired"
)

type Event struct {
	APIVersion  string          `json:"apiVersion"`
	RunID       string          `json:"runId"`
	Sequence    uint64          `json:"sequence"`
	Type        string          `json:"type"`
	TraceID     string          `json:"traceId,omitempty"`
	ParentRunID string          `json:"parentRunId,omitempty"`
	OccurredAt  time.Time       `json:"occurredAt"`
	Data        json.RawMessage `json:"data"`
}

func isJSONObject(raw json.RawMessage) bool {
	var value map[string]any
	return json.Unmarshal(raw, &value) == nil && value != nil
}

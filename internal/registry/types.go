// Package registry implements the internal engine used by the Grimoire binary.
package registry

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"regexp"
	"sort"
	"strings"
)

const APIVersion = "grimoire/v1alpha1"

type ResourceKind string

const (
	KindRuntime ResourceKind = "Runtime"
	KindSkill   ResourceKind = "Skill"
	KindTool    ResourceKind = "Tool"
	KindPolicy  ResourceKind = "Policy"
)

var segmentPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9._-]*$`)

type Metadata struct {
	Name    string `json:"name"`
	Version string `json:"version"`
}

func (m Metadata) Validate() error {
	if !validSegment(m.Name) {
		return errors.New("metadata.name must be a safe registry segment")
	}
	if !validSegment(m.Version) {
		return errors.New("metadata.version must be a safe registry segment")
	}
	return nil
}

type Ref struct {
	Kind    ResourceKind `json:"kind"`
	Name    string       `json:"name"`
	Version string       `json:"version"`
}

func (r Ref) Validate(expected ResourceKind) error {
	if r.Kind != expected {
		return fmt.Errorf("resource kind must be %q", expected)
	}
	return (Metadata{Name: r.Name, Version: r.Version}).Validate()
}

func (r Ref) Key() string { return string(r.Kind) + "/" + r.Name + "@" + r.Version }

type Artifact struct {
	Digest    string `json:"digest"`
	MediaType string `json:"mediaType,omitempty"`
}

func (a Artifact) Validate() error {
	if _, err := ParseDigest(a.Digest); err != nil {
		return fmt.Errorf("artifact digest: %w", err)
	}
	return nil
}

type Entrypoint struct {
	Kind    string   `json:"kind"`
	Command []string `json:"command"`
}

type SandboxRequirements struct {
	Network    string `json:"network,omitempty"`
	Filesystem string `json:"filesystem,omitempty"`
}

type RuntimeSpec struct {
	Protocol     string              `json:"protocol"`
	Artifact     Artifact            `json:"artifact"`
	Entrypoint   Entrypoint          `json:"entrypoint"`
	Capabilities []string            `json:"capabilities,omitempty"`
	Sandbox      SandboxRequirements `json:"sandbox,omitempty"`
}

type Runtime struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       RuntimeSpec  `json:"spec"`
}

func (r Runtime) Validate() error {
	if r.APIVersion != APIVersion || r.Kind != KindRuntime {
		return errors.New("invalid runtime apiVersion or kind")
	}
	if err := r.Metadata.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(r.Spec.Protocol) == "" {
		return errors.New("runtime protocol is required")
	}
	if err := r.Spec.Artifact.Validate(); err != nil {
		return err
	}
	if strings.TrimSpace(r.Spec.Entrypoint.Kind) == "" || len(r.Spec.Entrypoint.Command) == 0 {
		return errors.New("runtime entrypoint kind and command are required")
	}
	return validateStrings("runtime capability", r.Spec.Capabilities)
}

type SkillRequirements struct {
	Capabilities []string `json:"capabilities,omitempty"`
	Tools        []Ref    `json:"tools,omitempty"`
}

type SkillSpec struct {
	Description string            `json:"description,omitempty"`
	Artifact    Artifact          `json:"artifact"`
	Requires    SkillRequirements `json:"requires,omitempty"`
	Permissions []string          `json:"permissions,omitempty"`
}

type Skill struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       SkillSpec    `json:"spec"`
}

func (s Skill) Validate() error {
	if s.APIVersion != APIVersion || s.Kind != KindSkill {
		return errors.New("invalid skill apiVersion or kind")
	}
	if err := s.Metadata.Validate(); err != nil {
		return err
	}
	if err := s.Spec.Artifact.Validate(); err != nil {
		return err
	}
	if err := validateStrings("skill capability", s.Spec.Requires.Capabilities); err != nil {
		return err
	}
	if err := validateStrings("skill permission", s.Spec.Permissions); err != nil {
		return err
	}
	for _, ref := range s.Spec.Requires.Tools {
		if err := ref.Validate(KindTool); err != nil {
			return fmt.Errorf("skill tool: %w", err)
		}
	}
	return nil
}

type ToolSpec struct {
	Description  string          `json:"description,omitempty"`
	Effect       string          `json:"effect"`
	InputSchema  json.RawMessage `json:"inputSchema"`
	OutputSchema json.RawMessage `json:"outputSchema"`
	Artifact     *Artifact       `json:"artifact,omitempty"`
}

type Tool struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       ToolSpec     `json:"spec"`
}

func (t Tool) Validate() error {
	if t.APIVersion != APIVersion || t.Kind != KindTool {
		return errors.New("invalid tool apiVersion or kind")
	}
	if err := t.Metadata.Validate(); err != nil {
		return err
	}
	if t.Spec.Effect != "read" && t.Spec.Effect != "write" && t.Spec.Effect != "external" {
		return errors.New("tool effect must be read, write, or external")
	}
	if !isJSONObject(t.Spec.InputSchema) || !isJSONObject(t.Spec.OutputSchema) {
		return errors.New("tool inputSchema and outputSchema must be JSON objects")
	}
	if t.Spec.Artifact != nil {
		return t.Spec.Artifact.Validate()
	}
	return nil
}

type PolicySpec struct {
	Rules json.RawMessage `json:"rules"`
}

type Policy struct {
	APIVersion string       `json:"apiVersion"`
	Kind       ResourceKind `json:"kind"`
	Metadata   Metadata     `json:"metadata"`
	Spec       PolicySpec   `json:"spec"`
}

func (p Policy) Validate() error {
	if p.APIVersion != APIVersion || p.Kind != KindPolicy {
		return errors.New("invalid policy apiVersion or kind")
	}
	if err := p.Metadata.Validate(); err != nil {
		return err
	}
	if len(p.Spec.Rules) == 0 || !json.Valid(p.Spec.Rules) {
		return errors.New("policy rules must be valid JSON")
	}
	return nil
}

type Manifest struct {
	APIVersion string          `json:"apiVersion"`
	Kind       ResourceKind    `json:"kind"`
	Metadata   Metadata        `json:"metadata"`
	Spec       json.RawMessage `json:"spec"`
}

func (m Manifest) Ref() Ref {
	return Ref{Kind: m.Kind, Name: m.Metadata.Name, Version: m.Metadata.Version}
}

func DecodeManifest(raw []byte) (Manifest, []byte, error) {
	canonical, err := CanonicalJSON(raw)
	if err != nil {
		return Manifest{}, nil, err
	}
	var manifest Manifest
	if err := json.Unmarshal(canonical, &manifest); err != nil {
		return Manifest{}, nil, err
	}
	if err := validateTypedManifest(manifest, canonical); err != nil {
		return Manifest{}, nil, err
	}
	return manifest, canonical, nil
}

func validateTypedManifest(manifest Manifest, raw []byte) error {
	switch manifest.Kind {
	case KindRuntime:
		var value Runtime
		if err := json.Unmarshal(raw, &value); err != nil {
			return err
		}
		return value.Validate()
	case KindSkill:
		var value Skill
		if err := json.Unmarshal(raw, &value); err != nil {
			return err
		}
		return value.Validate()
	case KindTool:
		var value Tool
		if err := json.Unmarshal(raw, &value); err != nil {
			return err
		}
		return value.Validate()
	case KindPolicy:
		var value Policy
		if err := json.Unmarshal(raw, &value); err != nil {
			return err
		}
		return value.Validate()
	default:
		return fmt.Errorf("unsupported resource kind %q", manifest.Kind)
	}
}

type Digest string

func DigestBytes(raw []byte) Digest {
	sum := sha256.Sum256(raw)
	return Digest("sha256:" + hex.EncodeToString(sum[:]))
}

func ParseDigest(value string) (Digest, error) {
	if !strings.HasPrefix(value, "sha256:") || len(value) != len("sha256:")+64 {
		return "", errors.New("digest must use sha256:<64 lowercase hex characters>")
	}
	encoded := strings.TrimPrefix(value, "sha256:")
	decoded, err := hex.DecodeString(encoded)
	if err != nil || len(decoded) != sha256.Size || encoded != strings.ToLower(encoded) {
		return "", errors.New("digest must use sha256:<64 lowercase hex characters>")
	}
	return Digest(value), nil
}

func CanonicalJSON(raw []byte) ([]byte, error) {
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var value any
	if err := decoder.Decode(&value); err != nil {
		return nil, err
	}
	if err := ensureEOF(decoder); err != nil {
		return nil, err
	}
	return json.Marshal(value)
}

func ensureEOF(decoder *json.Decoder) error {
	var extra any
	if err := decoder.Decode(&extra); err == io.EOF {
		return nil
	} else if err != nil {
		return err
	}
	return errors.New("JSON document contains multiple values")
}

func validSegment(value string) bool {
	return value != "." && value != ".." && segmentPattern.MatchString(value)
}

func validateStrings(label string, values []string) error {
	seen := map[string]struct{}{}
	for _, value := range values {
		value = strings.TrimSpace(value)
		if value == "" {
			return fmt.Errorf("%s cannot be empty", label)
		}
		if _, exists := seen[value]; exists {
			return fmt.Errorf("%s %q is duplicated", label, value)
		}
		seen[value] = struct{}{}
	}
	return nil
}

func isJSONObject(raw json.RawMessage) bool {
	var value map[string]any
	return json.Unmarshal(raw, &value) == nil && value != nil
}

func sortRefs(refs []Ref) {
	sort.Slice(refs, func(i, j int) bool { return refs[i].Key() < refs[j].Key() })
}

package v1alpha1

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
)

const KindResolvedAgentDefinition = "ResolvedAgentDefinition"

// ResolvedAgentDefinition is the immutable registry artifact consumed by runtime systems.
// It intentionally contains definitions only: no
// session, run, provider client, credential value, callback, or runtime event.
//
// The digest covers the agent and every resolved declaration. A runtime consumer must
// verify it before caching or executing the definition.
type ResolvedAgentDefinition struct {
	APIVersion string  `json:"apiVersion"`
	Kind       string  `json:"kind"`
	Agent      Agent   `json:"agent"`
	Tools      []Tool  `json:"tools"`
	Skills     []Skill `json:"skills"`
	Digest     string  `json:"digest"`
}

// NewResolvedAgentDefinition validates and freezes the exact declarations
// referenced by an agent. Tool and skill order follows the agent manifest.
func NewResolvedAgentDefinition(agent Agent, tools []Tool, skills []Skill) (ResolvedAgentDefinition, error) {
	definition := ResolvedAgentDefinition{
		APIVersion: APIVersion,
		Kind:       KindResolvedAgentDefinition,
		Agent:      agent.Clone(),
		Tools:      cloneTools(tools),
		Skills:     cloneSkills(skills),
	}
	if err := definition.validateContents(); err != nil {
		return ResolvedAgentDefinition{}, err
	}
	digest, err := definitionDigest(definition)
	if err != nil {
		return ResolvedAgentDefinition{}, err
	}
	definition.Digest = digest
	return definition, nil
}

// Validate verifies both the definition shape and its immutable digest.
func (d ResolvedAgentDefinition) Validate() error {
	if err := d.validateContents(); err != nil {
		return err
	}
	if d.Digest == "" {
		return errors.New("resolved definition digest is required")
	}
	expected, err := definitionDigest(d)
	if err != nil {
		return err
	}
	if d.Digest != expected {
		return errors.New("resolved definition digest does not match contents")
	}
	return nil
}

func (d ResolvedAgentDefinition) validateContents() error {
	if d.APIVersion != APIVersion || d.Kind != KindResolvedAgentDefinition {
		return errors.New("invalid resolved definition apiVersion or kind")
	}
	if err := d.Agent.Validate(); err != nil {
		return fmt.Errorf("resolved definition agent: %w", err)
	}
	if len(d.Tools) != len(d.Agent.Spec.Tools) {
		return errors.New("resolved definition tools do not match agent declaration")
	}
	if len(d.Skills) != len(d.Agent.Spec.Skills) {
		return errors.New("resolved definition skills do not match agent declaration")
	}
	for index, tool := range d.Tools {
		if err := tool.Validate(); err != nil {
			return fmt.Errorf("resolved definition tool: %w", err)
		}
		if !sameRef(d.Agent.Spec.Tools[index], KindTool, tool.Metadata) {
			return errors.New("resolved definition tool does not match agent declaration")
		}
	}
	for index, skill := range d.Skills {
		if err := skill.Validate(); err != nil {
			return fmt.Errorf("resolved definition skill: %w", err)
		}
		if !sameRef(d.Agent.Spec.Skills[index], KindSkill, skill.Metadata) {
			return errors.New("resolved definition skill does not match agent declaration")
		}
	}
	return nil
}

func definitionDigest(d ResolvedAgentDefinition) (string, error) {
	payload := struct {
		APIVersion string  `json:"apiVersion"`
		Kind       string  `json:"kind"`
		Agent      Agent   `json:"agent"`
		Tools      []Tool  `json:"tools"`
		Skills     []Skill `json:"skills"`
	}{
		APIVersion: d.APIVersion,
		Kind:       d.Kind,
		Agent:      d.Agent,
		Tools:      d.Tools,
		Skills:     d.Skills,
	}
	raw, err := json.Marshal(payload)
	if err != nil {
		return "", err
	}
	sum := sha256.Sum256(raw)
	return hex.EncodeToString(sum[:]), nil
}

func sameRef(ref ResourceRef, kind ResourceKind, metadata Metadata) bool {
	return ref.Kind == kind && ref.Name == metadata.Name && ref.Version == metadata.Version
}

// Clone returns a distinct snapshot safe for callers to inspect or modify.
// The associated ResolvedAgentDefinition digest will detect any modification.
func (a Agent) Clone() Agent {
	copy := a
	copy.Spec.Tools = append([]ResourceRef(nil), a.Spec.Tools...)
	copy.Spec.Skills = append([]ResourceRef(nil), a.Spec.Skills...)
	copy.Spec.Delegation.AllowedAgents = append([]ResourceRef(nil), a.Spec.Delegation.AllowedAgents...)
	copy.Spec.Policy.RequiredPermissions = append([]string(nil), a.Spec.Policy.RequiredPermissions...)
	copy.Spec.Policy.RequiredCapabilities = append([]string(nil), a.Spec.Policy.RequiredCapabilities...)
	return copy
}

// Clone returns a distinct tool declaration, including its JSON schemas.
func (t Tool) Clone() Tool {
	copy := t
	copy.Spec.InputSchema = append(json.RawMessage(nil), t.Spec.InputSchema...)
	copy.Spec.OutputSchema = append(json.RawMessage(nil), t.Spec.OutputSchema...)
	return copy
}

// Clone returns a distinct skill declaration, including its references.
func (s Skill) Clone() Skill {
	copy := s
	copy.Spec.References = append([]string(nil), s.Spec.References...)
	return copy
}

func cloneTools(tools []Tool) []Tool {
	result := make([]Tool, len(tools))
	for index, tool := range tools {
		result[index] = tool.Clone()
	}
	return result
}

func cloneSkills(skills []Skill) []Skill {
	result := make([]Skill, len(skills))
	for index, skill := range skills {
		result[index] = skill.Clone()
	}
	return result
}

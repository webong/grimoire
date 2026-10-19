package registry

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sort"
)

type Registry struct {
	store Store
}

func Open(store Store) (*Registry, error) {
	if store == nil {
		return nil, errors.New("registry store is required")
	}
	return &Registry{store: store}, nil
}

func (r *Registry) PutArtifact(ctx context.Context, raw []byte) (Digest, error) {
	if len(raw) == 0 {
		return "", errors.New("artifact cannot be empty")
	}
	return r.store.PutObject(ctx, raw)
}

func (r *Registry) ImportManifest(ctx context.Context, raw []byte) (Reference, error) {
	manifest, canonical, err := DecodeManifest(raw)
	if err != nil {
		return Reference{}, err
	}
	digest, err := r.store.PutObject(ctx, canonical)
	if err != nil {
		return Reference{}, err
	}
	ref := manifest.Ref()
	existing, err := r.store.GetReference(ctx, ref)
	if err == nil {
		if existing.Digest != digest {
			return Reference{}, fmt.Errorf("%s already points to different content: %w", ref.Key(), ErrConflict)
		}
		return existing, nil
	}
	if !errors.Is(err, ErrNotFound) {
		return Reference{}, err
	}
	return r.store.PutReference(ctx, Reference{
		Ref: ref, Digest: digest, Status: PublicationDraft,
	}, nil)
}

func (r *Registry) Publish(ctx context.Context, ref Ref) (Reference, error) {
	current, err := r.store.GetReference(ctx, ref)
	if err != nil {
		return Reference{}, err
	}
	if current.Status == PublicationRetired {
		return Reference{}, fmt.Errorf("%s is retired", ref.Key())
	}
	if current.Status == PublicationPublished {
		return current, nil
	}
	if err := r.validateDependencies(ctx, current); err != nil {
		return Reference{}, err
	}
	current.Status = PublicationPublished
	return r.store.PutReference(ctx, current, &current.Revision)
}

func (r *Registry) Retire(ctx context.Context, ref Ref) (Reference, error) {
	current, err := r.store.GetReference(ctx, ref)
	if err != nil {
		return Reference{}, err
	}
	if current.Status == PublicationRetired {
		return current, nil
	}
	if current.Status != PublicationPublished {
		return Reference{}, fmt.Errorf("%s is not published", ref.Key())
	}
	current.Status = PublicationRetired
	return r.store.PutReference(ctx, current, &current.Revision)
}

func (r *Registry) Manifest(ctx context.Context, ref Ref, publishedOnly bool) (Manifest, Reference, []byte, error) {
	reference, err := r.store.GetReference(ctx, ref)
	if err != nil {
		return Manifest{}, Reference{}, nil, err
	}
	if publishedOnly && reference.Status != PublicationPublished {
		return Manifest{}, Reference{}, nil, ErrNotFound
	}
	raw, err := r.store.GetObject(ctx, reference.Digest)
	if err != nil {
		return Manifest{}, Reference{}, nil, err
	}
	manifest, canonical, err := DecodeManifest(raw)
	if err != nil {
		return Manifest{}, Reference{}, nil, err
	}
	if manifest.Ref() != ref {
		return Manifest{}, Reference{}, nil, errors.New("reference does not match stored manifest")
	}
	return manifest, reference, canonical, nil
}

type ResolveRequest struct {
	Runtime  Ref   `json:"runtime"`
	Skills   []Ref `json:"skills,omitempty"`
	Tools    []Ref `json:"tools,omitempty"`
	Policies []Ref `json:"policies,omitempty"`
}

type LockedResource struct {
	Ref            Ref    `json:"ref"`
	ManifestDigest Digest `json:"manifestDigest"`
	ArtifactDigest Digest `json:"artifactDigest,omitempty"`
}

type Resolution struct {
	APIVersion string           `json:"apiVersion"`
	Kind       string           `json:"kind"`
	Runtime    LockedResource   `json:"runtime"`
	Skills     []LockedResource `json:"skills"`
	Tools      []LockedResource `json:"tools"`
	Policies   []LockedResource `json:"policies"`
	Digest     Digest           `json:"digest"`
}

func (r *Registry) Resolve(ctx context.Context, request ResolveRequest) (Resolution, error) {
	if err := request.Runtime.Validate(KindRuntime); err != nil {
		return Resolution{}, err
	}
	runtimeLock, runtimeManifest, err := r.lock(ctx, request.Runtime)
	if err != nil {
		return Resolution{}, err
	}
	var runtimeValue Runtime
	if err := json.Unmarshal(runtimeManifest, &runtimeValue); err != nil {
		return Resolution{}, err
	}
	capabilities := make(map[string]struct{}, len(runtimeValue.Spec.Capabilities))
	for _, capability := range runtimeValue.Spec.Capabilities {
		capabilities[capability] = struct{}{}
	}

	skillRefs, err := uniqueRefs(request.Skills, KindSkill)
	if err != nil {
		return Resolution{}, err
	}
	toolRefs, err := uniqueRefs(request.Tools, KindTool)
	if err != nil {
		return Resolution{}, err
	}
	policyRefs, err := uniqueRefs(request.Policies, KindPolicy)
	if err != nil {
		return Resolution{}, err
	}

	skills := make([]LockedResource, 0, len(skillRefs))
	for _, ref := range skillRefs {
		locked, raw, err := r.lock(ctx, ref)
		if err != nil {
			return Resolution{}, err
		}
		var skill Skill
		if err := json.Unmarshal(raw, &skill); err != nil {
			return Resolution{}, err
		}
		for _, capability := range skill.Spec.Requires.Capabilities {
			if _, ok := capabilities[capability]; !ok {
				return Resolution{}, fmt.Errorf("runtime %s lacks capability %q required by skill %s", request.Runtime.Key(), capability, ref.Key())
			}
		}
		toolRefs = append(toolRefs, skill.Spec.Requires.Tools...)
		skills = append(skills, locked)
	}
	toolRefs, err = uniqueRefs(toolRefs, KindTool)
	if err != nil {
		return Resolution{}, err
	}

	tools, err := r.lockAll(ctx, toolRefs)
	if err != nil {
		return Resolution{}, err
	}
	policies, err := r.lockAll(ctx, policyRefs)
	if err != nil {
		return Resolution{}, err
	}

	resolution := Resolution{
		APIVersion: APIVersion,
		Kind:       "Resolution",
		Runtime:    runtimeLock,
		Skills:     skills,
		Tools:      tools,
		Policies:   policies,
	}
	resolution.sort()
	digest, err := resolution.digest()
	if err != nil {
		return Resolution{}, err
	}
	resolution.Digest = digest
	encoded, err := json.Marshal(resolution)
	if err != nil {
		return Resolution{}, err
	}
	if _, err := r.store.PutObject(ctx, encoded); err != nil {
		return Resolution{}, err
	}
	return resolution, nil
}

func (r Resolution) Validate() error {
	if r.APIVersion != APIVersion || r.Kind != "Resolution" {
		return errors.New("invalid resolution apiVersion or kind")
	}
	if r.Digest == "" {
		return errors.New("resolution digest is required")
	}
	copy := r
	copy.Skills = append([]LockedResource{}, r.Skills...)
	copy.Tools = append([]LockedResource{}, r.Tools...)
	copy.Policies = append([]LockedResource{}, r.Policies...)
	copy.sort()
	expected, err := copy.digest()
	if err != nil {
		return err
	}
	if expected != r.Digest {
		return errors.New("resolution digest does not match contents")
	}
	return nil
}

func (r *Registry) lockAll(ctx context.Context, refs []Ref) ([]LockedResource, error) {
	result := make([]LockedResource, 0, len(refs))
	for _, ref := range refs {
		locked, _, err := r.lock(ctx, ref)
		if err != nil {
			return nil, err
		}
		result = append(result, locked)
	}
	return result, nil
}

func (r *Registry) lock(ctx context.Context, ref Ref) (LockedResource, []byte, error) {
	manifest, reference, raw, err := r.Manifest(ctx, ref, true)
	if err != nil {
		return LockedResource{}, nil, fmt.Errorf("resolve %s: %w", ref.Key(), err)
	}
	artifact, err := artifactDigest(manifest, raw)
	if err != nil {
		return LockedResource{}, nil, err
	}
	return LockedResource{Ref: ref, ManifestDigest: reference.Digest, ArtifactDigest: artifact}, raw, nil
}

func (r *Registry) validateDependencies(ctx context.Context, reference Reference) error {
	raw, err := r.store.GetObject(ctx, reference.Digest)
	if err != nil {
		return err
	}
	manifest, _, err := DecodeManifest(raw)
	if err != nil {
		return err
	}
	artifact, err := artifactDigest(manifest, raw)
	if err != nil {
		return err
	}
	if artifact != "" {
		if _, err := r.store.GetObject(ctx, artifact); err != nil {
			return fmt.Errorf("%s artifact %s: %w", reference.Ref.Key(), artifact, err)
		}
	}
	if manifest.Kind == KindSkill {
		var skill Skill
		if err := json.Unmarshal(raw, &skill); err != nil {
			return err
		}
		for _, ref := range skill.Spec.Requires.Tools {
			tool, err := r.store.GetReference(ctx, ref)
			if err != nil {
				return fmt.Errorf("%s requires %s: %w", reference.Ref.Key(), ref.Key(), err)
			}
			if tool.Status != PublicationPublished {
				return fmt.Errorf("%s requires unpublished %s", reference.Ref.Key(), ref.Key())
			}
		}
	}
	return nil
}

func artifactDigest(manifest Manifest, raw []byte) (Digest, error) {
	var value struct {
		Spec struct {
			Artifact *Artifact `json:"artifact"`
		} `json:"spec"`
	}
	if err := json.Unmarshal(raw, &value); err != nil {
		return "", err
	}
	if value.Spec.Artifact == nil {
		return "", nil
	}
	digest, err := ParseDigest(value.Spec.Artifact.Digest)
	if err != nil {
		return "", fmt.Errorf("%s artifact: %w", manifest.Ref().Key(), err)
	}
	return digest, nil
}

func uniqueRefs(refs []Ref, kind ResourceKind) ([]Ref, error) {
	seen := map[string]Ref{}
	for _, ref := range refs {
		if err := ref.Validate(kind); err != nil {
			return nil, err
		}
		seen[ref.Key()] = ref
	}
	result := make([]Ref, 0, len(seen))
	for _, ref := range seen {
		result = append(result, ref)
	}
	sortRefs(result)
	return result, nil
}

func (r *Resolution) sort() {
	if r.Skills == nil {
		r.Skills = []LockedResource{}
	}
	if r.Tools == nil {
		r.Tools = []LockedResource{}
	}
	if r.Policies == nil {
		r.Policies = []LockedResource{}
	}
	sort.Slice(r.Skills, func(i, j int) bool { return r.Skills[i].Ref.Key() < r.Skills[j].Ref.Key() })
	sort.Slice(r.Tools, func(i, j int) bool { return r.Tools[i].Ref.Key() < r.Tools[j].Ref.Key() })
	sort.Slice(r.Policies, func(i, j int) bool { return r.Policies[i].Ref.Key() < r.Policies[j].Ref.Key() })
}

func (r Resolution) digest() (Digest, error) {
	r.Digest = ""
	raw, err := json.Marshal(r)
	if err != nil {
		return "", err
	}
	return DigestBytes(raw), nil
}

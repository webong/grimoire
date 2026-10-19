# Grimlock

Grimlock is Grimoire's versioned agent registry. The registry owns immutable agent,
tool, skill, capability, connector, policy-metadata, and runtime-requirement
definitions. Runtime systems consume these definitions and own all execution.

Its registry contract is versioned as `grimlock/v1alpha1`. Schema identifiers
use URNs, so the contract does not pretend an unowned web domain exists.

Registry-facing implementation:

- `api/`: Go types for resource manifests and `ResolvedAgentDefinition`.
- `spec/`: language-neutral JSON Schemas.
- `catalogfs/` and `core/catalog.go`: immutable registration, `draft`/`published`/`retired` lifecycle, and `published`-only lookup/resolution/listing.
- `transport/registryhttp/`: registry-only `published` listing, lifecycle (`publish`/`retire`), and resolved-definition lookup.

`core/service.go`, `state.go`, `executors.go`, SQLite runtime state, the
legacy HTTP control-plane surface, and `adapters/laravel-ai` execution tools
remain temporary migration sources for Grimoire-owned runtime implementations
such as Gouge and the planned Go SDK and Pi integration. Do not add execution
behavior to Grimlock.

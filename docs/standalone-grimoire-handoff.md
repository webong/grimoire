# Grimoire standalone handoff

## Objective

Keep the Grimoire registry as a standalone service. It must expose generic persistence and definition-resolution contracts without depending on any consumer chat application or runtime implementation, including Grimoire-owned implementations.

## Completed in this handoff

- `cmd/grimlockd/main.go` is now registry-only: it loads catalog manifests and serves the registry HTTP transport.
- The executable no longer initializes SQLite runtime storage, registers executors, or serves the legacy execution API.
- Public architecture and boundary documentation uses generic consumer/runtime language.
- Product-specific names were removed from Grimoire documentation, public comments, examples, and adapter test metadata.
- The extraction tracker wording was corrected to refer to runtime state once.

## Remaining work

Legacy runtime packages and persistence tables still exist in the repository as migration sources. They should be extracted into Grimoire-owned runtime implementations or retired only after those implementations have replacement contracts and compatibility coverage.

The next agent should keep the public registry API generic, document any remaining migration seams, and avoid adding runtime execution behavior back into the registry service. Grimoire project ownership of Gouge, the planned Go SDK, and the planned Pi implementation does not change that process boundary.

## Guardrails

- Do not introduce dependencies on consumer product names or consumer-specific runtime behavior.
- Preserve the dirty worktree and unrelated user changes.
- Do not reset, revert, or commit without explicit instruction.
- Run tests or validation only when explicitly requested.

## Runtime family continuation

The [runtime family plan](runtime-family.md) now records the Gouge, native Go SDK, and Pi implementation boundaries, minimum lifecycle semantics, and extraction sequence. The [tracker](figurate-extraction-tracker.md) distinguishes implemented registry behavior from remaining runtime work.

The Gouge host-dependency extraction is now implemented with runtime-only contracts, native Laravel AI execution, and isolated session/run storage. The Go JSON digest still needs specified cross-language serialization and shared vectors before PHP/TypeScript verification is portable. Full registry/runtime conformance is not claimed by this extraction.

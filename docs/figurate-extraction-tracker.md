# Embedded registry tracker

Status: `[x]` implemented, `[~]` transitional, `[ ]` planned.

| Status | Workstream | Evidence or next condition |
| --- | --- | --- |
| [x] | Binary database | One append-only `.grim` file with checksummed frames, `fsync`, exclusive locking, and torn-tail recovery. |
| [x] | Internal storage boundary | The binary engine separates immutable objects from compare-and-swap references. |
| [x] | Registry resources | Runtime, Skill, Tool, and Policy manifests with validation. |
| [x] | Publication lifecycle | Draft, published, and terminal retired references. |
| [x] | Resolution | Exact published resources, automatic skill tool dependencies, runtime capability checks, and deterministic lock digest. |
| [x] | Materialization | Verified read-only manifests/artifacts plus writable scratch directory. |
| [ ] | Cross-language canonicalization | Publish a canonical format and shared vectors before non-Go writers are supported. |
| [ ] | Persistent stdio protocol | Add a long-lived JSON transport for hosts that need many low-latency operations. |
| [ ] | Compaction and indexes | Rewrite live records and persist a rebuildable startup index. |
| [ ] | Verification | Add a command that checks every frame, reference, manifest, and reachable artifact. |
| [~] | Legacy extraction | Inventory reusable runtime-host behavior; keep registry execution-free. |
| [~] | Gouge retirement | Treat `packages/gouge` as migration source, extract generic Laravel runtime behavior, and retire the product name. |

## Next vertical slice

1. Add `verify` and `compact` commands for the single-file database.
2. Add JSON Schemas and shared manifest fixtures for the new registry API.
3. Add a persistent stdio JSON protocol over the binary engine.
4. Extract one minimal runtime host that consumes a materialized lockfile.

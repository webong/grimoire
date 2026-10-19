# Embedded registry tracker

Status: `[x]` implemented, `[~]` transitional, `[ ]` planned.

| Status | Workstream | Evidence or next condition |
| --- | --- | --- |
| [x] | Storage boundary | `registry.Store` separates immutable objects from compare-and-swap references. |
| [x] | Filesystem backend | Atomic object/reference writes, digest verification, reopen coverage, and process-local concurrency protection. |
| [x] | Registry resources | Runtime, Skill, Tool, and Policy manifests with validation. |
| [x] | Publication lifecycle | Draft, published, and terminal retired references. |
| [x] | Resolution | Exact published resources, automatic skill tool dependencies, runtime capability checks, and deterministic lock digest. |
| [x] | Materialization | Verified read-only manifests/artifacts plus writable scratch directory. |
| [ ] | Object-store backend | Implement conditional reference writes with native ETags/generations. |
| [ ] | Cross-language canonicalization | Publish a canonical format and shared vectors before non-Go writers are supported. |
| [ ] | Multi-process filesystem writer | Add advisory locking or require the optional single-writer daemon. |
| [ ] | Discovery indexes | Add rebuildable indexes without making them authoritative. |
| [ ] | Journal and verification | Append-only reference history and full-store integrity verification. |
| [ ] | Garbage collection | Mark reachable objects from refs/resolutions, then safely sweep. |
| [~] | Legacy extraction | Inventory reusable runtime-host behavior; keep registry execution-free. |
| [~] | Gouge retirement | Treat `packages/gouge` as migration source, extract generic Laravel runtime behavior, and retire the product name. |

## Next vertical slice

1. Add a small CLI for object import, manifest import, publication, resolution,
   verification, and materialization.
2. Add JSON Schemas and shared manifest fixtures for the new registry API.
3. Implement an S3-compatible store using conditional reference writes.
4. Extract one minimal runtime host that consumes a materialized lockfile.

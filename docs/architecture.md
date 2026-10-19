# Grimoire architecture

Grimoire is an embeddable registry and sandbox materializer. It stores and
resolves immutable runtimes, skills, tools, policies, and bundles over a small
object/reference interface. A filesystem is sufficient; an object-store backend
can provide the same semantics.

```text
publisher ──put/publish──> Grimoire store <──resolve── host application
                                │                         │
                         immutable objects          resolution lock
                         mutable references              │
                                                   materialized sandbox
                                                        │
                                                   runtime process
```

## Ownership boundary

Grimoire owns:

- manifest validation and canonicalization;
- content-addressed object storage;
- immutable resource versions and publication references;
- dependency and capability resolution;
- resolution lockfiles and their digests;
- artifact verification; and
- safe sandbox materialization.

A runtime host owns process or container launch, provider calls, sessions,
execution events, approvals, cancellation, credentials, and effects. Grimoire
may store policies and tool effect declarations, but it does not enforce them
against a live run.

## Objects and references

Objects are immutable byte sequences addressed by SHA-256. Manifests are
canonicalized JSON; artifacts remain opaque bytes. References map an exact
`kind/name@version` to an object digest and publication status.

Only references change. A published version never changes content, and a
retired version cannot be republished. Compare-and-swap revisions protect
reference updates from stale writers.

Indexes and discovery projections are caches. They must be rebuildable from
objects and references.

## Resolution

A request selects one exact published runtime and zero or more exact skills,
tools, and policies. Skill tool dependencies are added automatically. Resolution
fails if an artifact is missing, a dependency is unpublished, or the runtime
lacks a skill's required capability.

The result locks every manifest and artifact digest. Sorting makes equivalent
requests produce the same resolution digest. The lockfile is the portable
boundary between the registry and runtime hosts.

## Sandbox

Materialization verifies all locked digests, writes manifests and artifacts as
read-only files, and creates a separate writable `scratch` directory. Artifacts
are not unpacked or executed by the registry. Archive expansion and process
isolation belong to a runtime host with an explicit admission policy.

## Runtime implementations

Runtime implementations are registry content, not Grimoire-owned product
families. A Laravel AI runtime, Pi harness, ADK-Go engine, or remote executor is
represented by a `Runtime` manifest and opaque artifact. Gouge is retained only
as migration source while reusable Laravel behavior is identified.

## Migration

The new target lives in `registry`. Historical session/run storage, SQLite
tables, HTTP execution endpoints, Grimrock engines, and Gouge code remain in the
repository temporarily so behavior can be inventoried without coupling it to
the embedded registry.

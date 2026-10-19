# Grimoire architecture

Grimoire is a registry appliance packaged as one executable. It opens a local
`.grim` file directly, performs an operation, flushes the result, and exits. It
needs neither a daemon nor an application-language SDK.

```text
publisher ──JSON/CLI──> grimoire binary <──JSON/CLI── host application
                              │
                        catalog.grim
                   objects + reference log
                              │
                        resolution lock
                              │
                     materialized sandbox
                              │
                       runtime process
```

## Public boundary

The supported boundary is the `grimoire` command, its JSON documents, and the
binary database format. The Go code under `internal/registry` is an
implementation detail. This lets a PHP, Python, JavaScript, Go, or native host
ship the same executable beside its application and use the same registry.

The current CLI starts once per operation. A persistent `serve --stdio` mode is
the natural next transport for hosts that need many low-latency operations. It
will preserve the command semantics and keep the database embedded in the
Grimoire process.

## Ownership boundary

Grimoire owns:

- manifest validation and canonicalization;
- content-addressed object storage;
- resource publication and retirement;
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

Only references change. Each change is appended as a new checksummed record. A
published version never changes content, and a retired version cannot be
republished. Revision tokens protect updates from stale writers.

## Resolution and sandboxes

A request selects one exact published runtime and zero or more exact skills,
tools, and policies. Skill tool dependencies are added automatically. Resolution
fails if an artifact is missing, a dependency is unpublished, or the runtime
lacks a required capability.

The result locks every manifest and artifact digest. Sorting makes equivalent
requests produce the same resolution digest. Materialization verifies those
digests, writes manifests and artifacts as read-only files, and creates a
separate writable `scratch` directory. Grimoire does not unpack or execute
artifacts.

## Runtime implementations

Runtime implementations are registry content. A Laravel AI runtime, Pi harness,
ADK-Go engine, or remote executor is represented by a `Runtime` manifest and an
opaque artifact. Gouge remains only as migration source while reusable Laravel
behavior is identified.

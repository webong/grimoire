# Grimoire

Grimoire is a self-contained registry executable for runtimes, skills, tools,
policies, and resolved bundles. Like SQLite, it has no server to install: the
`grimoire` binary reads and writes one portable database file. Applications in
any language can invoke the binary and exchange JSON at the boundary.

The database stores immutable content by SHA-256 and appends catalog changes as
versioned records. Resolution produces a deterministic lockfile; materialization
turns that lockfile into a verified sandbox for a separate runtime host.
Grimoire stores and assembles executable content, but does not execute it.

## Current slice

The binary currently provides:

- one append-only `.grim` database file;
- exclusive cross-process writer locking;
- checksummed records and recovery from an incomplete trailing write;
- typed `Runtime`, `Skill`, `Tool`, and `Policy` manifests;
- immutable artifacts and manifests addressed as `sha256:<digest>`;
- draft, published, and retired catalog states with compare-and-swap updates;
- exact dependency and capability resolution;
- deterministic resolution lockfiles; and
- sandbox materialization with read-only content and writable scratch space.

Build and use the executable:

```sh
go build -o grimoire ./cmd/grimoire

./grimoire init -db catalog.grim
./grimoire put-artifact -db catalog.grim -file runtime.tar
./grimoire import -db catalog.grim -file runtime.json
./grimoire publish -db catalog.grim -kind Runtime -name local -version 1.0.0
./grimoire resolve -db catalog.grim -request request.json -out resolution.lock
./grimoire materialize -db catalog.grim -resolution resolution.lock -out sandbox
```

The Go packages under `internal/registry` implement the executable. They are not
a supported application integration API. A long-lived stdio protocol can be
added to avoid one process start per operation while preserving the same binary
deployment model.

Run the complete test suite with:

```sh
go test ./...
```

## Migration source

`subsystems/grimlock`, `subsystems/grimrock`, and `packages/gouge` predate this
model. They remain temporarily as migration and compatibility source. Gouge is
no longer a target product or architectural boundary; reusable Laravel AI
behavior can become an ordinary registered runtime.

See [the architecture](docs/architecture.md) and
[binary format and transaction semantics](docs/embedded-registry.md).

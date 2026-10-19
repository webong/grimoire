# Grimoire

Grimoire is an embeddable, content-addressed registry for runtimes, skills,
tools, policies, and resolved bundles. It behaves like a small DBMS over files
or object storage: immutable objects are the source of truth, mutable references
publish or retire named versions, and indexes are disposable caches.

Grimoire does not require a database server and does not execute registered
artifacts. A host embeds the registry, resolves an exact bundle, materializes a
verified sandbox, and hands that sandbox to a runtime process.

## Current embedded slice

The `registry` package currently provides:

- typed `Runtime`, `Skill`, `Tool`, and `Policy` manifests;
- canonical JSON objects addressed as `sha256:<digest>`;
- immutable name/version references with `draft`, `published`, and `retired`
  lifecycle states;
- a storage interface suitable for filesystem and object-store backends;
- a filesystem backend with atomic writes and compare-and-swap references;
- exact runtime/skill/tool/policy resolution with capability checks;
- deterministic resolution lockfiles; and
- sandbox materialization with read-only manifests/artifacts and writable
  scratch space.

```go
store, err := fsstore.Open("./grimoire-data")
if err != nil {
    return err
}

catalog, err := registry.Open(store)
if err != nil {
    return err
}

resolution, err := catalog.Resolve(ctx, registry.ResolveRequest{
    Runtime: registry.Ref{Kind: registry.KindRuntime, Name: "laravel-ai", Version: "1.0.0"},
    Skills: []registry.Ref{{Kind: registry.KindSkill, Name: "summarize", Version: "1.2.0"}},
})
if err != nil {
    return err
}

return catalog.Materialize(ctx, resolution, "./sandbox")
```

The `cmd/grimoire` CLI exposes the same embedded engine:

```sh
go run ./cmd/grimoire init -store .grimoire
go run ./cmd/grimoire put-artifact -store .grimoire -file runtime.tar
go run ./cmd/grimoire import -store .grimoire -file runtime.json
go run ./cmd/grimoire publish -store .grimoire -kind Runtime -name local -version 1.0.0
go run ./cmd/grimoire resolve -store .grimoire -request request.json -out resolution.lock
go run ./cmd/grimoire materialize -store .grimoire -resolution resolution.lock -out sandbox
```

The on-disk format separates immutable content from named references:

```text
grimoire-data/
├── objects/sha256/
├── refs/runtime/
├── refs/skill/
├── refs/tool/
├── refs/policy/
├── resolutions/
└── indexes/
```

Run the complete test suite with:

```sh
go test ./...
```

## Migration source

`subsystems/grimlock`, `subsystems/grimrock`, and `packages/gouge` predate the
embedded registry model. They remain temporarily as migration and compatibility
source. Gouge is no longer a target product or architectural boundary; reusable
Laravel AI behavior can become an ordinary registered runtime implementation.

See [the embedded architecture](docs/architecture.md) and
[storage/transaction semantics](docs/embedded-registry.md).

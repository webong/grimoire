# Grimoire

Grimoire owns the shared agent definition contract and its official runtime implementations. They live in one repository while retaining clear package and deployment boundaries:

- **Grimlock** is the portable registry for immutable agent, tool, skill, connector, capability, and policy definitions.
- **Runtime implementations** execute those definitions while keeping their native SDK semantics.
- **Grimrock** is historical execution-plane code being used as migration input for those runtime implementations.

Grimoire ownership does not make its registry service an agent runtime. Runtime packages consume immutable Grimoire definitions across an explicit boundary; the registry process never creates sessions, invokes models, or executes tools.

## Runtime implementations

- **Gouge** is Grimoire's Laravel AI implementation. It packages consumer-independent Laravel-native agent execution and runtime storage as the `webong/gouge` Composer package in `packages/gouge`.
- **Go SDK** is the planned native Go implementation. The current ADK-Go code is migration input for it, not the final public SDK boundary.
- **Pi** is a planned implementation for the [Pi agent harness](https://pi.dev/), using its SDK or RPC mode to consume the same resolved definitions and runtime-facing lifecycle contract.

These implementations preserve their native SDK semantics. The target is shared definition resolution and runtime conformance; the Go and Pi implementations and cross-runtime conformance suite are not yet delivered. See the [runtime family and extraction sequence](docs/runtime-family.md).

## Registry and runtimes

Grimoire deliberately separates its registry service from its execution implementations:

- **Go runtimes** can run long-lived, effect-aware workflows and interaction tools.
- **Gouge** runs Laravel-native agents with queues, broadcasts, conversations, and first-party Laravel tooling through Laravel AI.
- **Pi** can preserve the harness's native extensions, skills, session behavior, and event flow behind the same Grimoire contracts.

The runtimes share manifests, policies, lifecycle events, and conformance tests; no SDK is embedded inside another. They meet at the Grimoire registry and runtime-facing contracts.

See [the Grimoire architecture and Grimlock extraction plan](docs/architecture.md).

## Current slice

The repository still contains an end-to-end historical slice while registry and runtime responsibilities are separated:

- `subsystems/grimlock/api/v1alpha1`: the Go representation of the portable registry contract.
- `subsystems/grimlock/spec/schemas/v1alpha1`: JSON Schemas for agents, tools, skills, sessions, runs, approvals, and events.
- `subsystems/grimlock/core`: the immutable catalog plus historical policy and execution code being split at the runtime boundary.
- `subsystems/grimlock/storage/sqlite`: durable registry storage plus historical runtime tables being separated.
- `subsystems/grimlock/transport/httpapi`: historical runtime transport retained during extraction.
- `subsystems/grimlock/adapters/laravel-ai`: migration source for registry persistence and Gouge-owned Laravel AI execution behavior.
- `packages/gouge`: Grimoire's Laravel AI implementation, tracked directly in this repository and not loaded by the Grimoire registry service.
- `subsystems/grimrock/engines/adkgo`: migration source for the planned Go SDK.
- `cmd/grimlockd`: the registry-only executable. It loads a file catalog and serves `transport/registryhttp`; it does not initialize runtime storage or serve execution endpoints.

Run the conformance slice with:

```sh
go test ./...
```

Run the Laravel migration/Eloquent integration test against an installed Laravel host:

```sh
GRIMOIRE_LARAVEL_AUTOLOAD=/path/to/laravel/vendor/autoload.php \
php -d assert.exception=1 subsystems/grimlock/adapters/laravel-ai/tests/migration_test.php /tmp/grimoire.sqlite
```

Start the registry with a file-backed catalog (the SQLite database above belongs to the legacy integration slice and is not read by this executable):

```sh
go run ./cmd/grimlockd -catalog examples/catalog
```

## Communication integration

Figurate owns A2A, ACP, and A2UI communication extensions, including transport authentication, task/message correlation, durable coordination, outboxes, lifecycle projection, and UI validation/rendering contracts. Grimoire runtimes expose runtime-owned execution APIs and events. Figurate owns the bridge that translates its Work Exchange into those APIs and maps runtime events back into consumer updates. Grimoire contains no Figurate bridge or Work Exchange dependency. See [communication ownership and cleanup](docs/communication-ownership.md).

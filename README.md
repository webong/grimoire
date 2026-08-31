# Grimoire

Grimoire is the home for shared agent systems. It contains independent subsystems with clear ownership boundaries:

- **Grimlock** is the portable control plane for managed agents, tools, skills, clients, devices, connectors, sessions, and approvals.
- **Grimrock** is the execution plane. It gives engines a shared run/event boundary while allowing each engine to keep its native SDK semantics.

## Grimlock

It deliberately separates the portable management layer from execution engines:

- **Google ADK for Go** runs long-lived, effect-aware workflows and interaction tools.
- **Laravel AI SDK** runs Laravel-native agents with queues, broadcasts, conversations, and first-party Laravel tooling.

The two runtimes share manifests, policies, events, and conformance tests; neither SDK is embedded inside the other. Their adapters meet at the Grimlock control-plane and Grimrock execution contracts.

See [the Grimoire architecture and Grimlock extraction plan](docs/architecture.md).

## Current slice

The first end-to-end extraction is present:

- `subsystems/grimlock/api/v1alpha1`: the Go representation of the portable contract.
- `subsystems/grimlock/spec/schemas/v1alpha1`: JSON Schemas for agents, tools, skills, sessions, runs, approvals, and events.
- `subsystems/grimlock/core`: in-memory immutable catalog, policy gates, approval workflow, event ledger, and idempotent tool execution.
- `subsystems/grimlock/storage/sqlite`: durable Go access to the same schema created by the Illuminate migration.
- `subsystems/grimlock/transport/httpapi`: sessions, runs, policy-filtered tools, approvals, and cursor/SSE events.
- `subsystems/grimlock/adapters/laravel-ai`: Grimlock's canonical Composer package for Laravel AI hosts, with managed skills and client/device/connector registries.
- `subsystems/grimrock/engines/adkgo`: a Google ADK-Go v2 engine that adapts admitted Grimlock tools and emits normalized execution events.
- `cmd/grimlockd`: a runnable HTTP control-plane process backed by an Illuminate-migrated SQLite database.

Run the conformance slice with:

```sh
go test ./...
```

Run the Laravel migration/Eloquent integration test against an installed Laravel host:

```sh
GRIMOIRE_LARAVEL_AUTOLOAD=/path/to/laravel/vendor/autoload.php \
php -d assert.exception=1 subsystems/grimlock/adapters/laravel-ai/tests/migration_test.php /tmp/grimoire.sqlite
```

Then start the control plane against that database:

```sh
go run ./cmd/grimlockd -database /tmp/grimoire.sqlite -catalog examples/catalog
```

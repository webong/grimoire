# Registry and runtime boundary

This document is authoritative for the Grimoire process boundary. Grimoire owns both the registry contract and official runtime implementations, but the registry service never executes agents.

```text
Consumers                      Grimoire registry         Grimoire runtimes
------------                   -----------------         -----------------
chat + coordination            agent registry            Gouge (Laravel AI)
threads, posts, channels       versioned manifests       sessions and runs
actors and routing             tools and skills          model/provider calls
delivery + audit               capabilities, policies     tool execution and approvals
runtime selection              publish, lookup, resolve  delegation and runtime events
```

A consumer sends a selected agent reference and context to a runtime implementation. The runtime resolves that reference from the Grimoire registry, executes it, and returns normalized lifecycle events. The registry service never receives a chat turn, creates a session, calls a model, or invokes a tool.

The official implementation family is Gouge for Laravel AI, a planned native Go SDK, and a planned implementation for the [Pi agent harness](https://pi.dev/) through its SDK or RPC mode. Repository ownership is shared; runtime state and deployment are not.

## Keep in the registry

| Area | Current location | Direction |
| --- | --- | --- |
| Versioned `Agent`, `Tool`, `Skill`, `Connector`, capability, and runtime-requirement manifests | `subsystems/grimlock/api`, `spec`, `catalogfs`, `core/catalog.go` | Keep; evolve into the public registry contract. |
| Immutable registration, lookup, manifest loading, digesting, and resolved definitions | `core/catalog.go`, `catalogfs` | Keep; registry is Grimoire's source of truth. |
| Static policy and permission metadata | `ToolSpec` effect/idempotency/approval fields | Keep as declarations; runtime systems enforce them during execution. |
| Publication lifecycle and durable registry storage | `catalog_entries` migration and registry models | Keep, but restrict it to immutable published definitions. |

## Move to runtime systems

| Area | Current location | Why |
| --- | --- | --- |
| Session and run lifecycle | `api.Session`, `api.Run`, `core/service.go`, SQLite store | Runtime systems own runtime state. |
| Tool admission, approval decisions, idempotency reservations, and execution leases | `core/service.go`, `core/state.go`, `core/policy.go`, `core/executors.go` | These are run-time decisions and enforcement. |
| Runtime event ledger and cancellation | `api.Event`, HTTP events endpoint, SQLite state | Runtime systems are the source of runtime events. Consumers map them into product events. |
| Engine abstraction and ADK-Go implementation | `subsystems/grimrock/**` | Extract into the Grimoire-owned Go SDK. `Grimrock` is migration source material, not the final SDK boundary. |
| Child-agent selection and delegation | legacy `AgentSpec.Delegation` | Move to runtime systems. The field remains only for `v1alpha1` migration compatibility. |
| Laravel AI tool proxies, connector invocation, and control-plane call tools | execution-oriented parts of `adapters/laravel-ai` | Move into Gouge, Grimoire's Laravel AI implementation. |
| Legacy execution HTTP transport | `subsystems/grimlock/transport/httpapi` | Move behind a runtime service. `cmd/grimlockd` remains registry-only and already uses `transport/registryhttp`. |

## Remove or deprecate from the registry surface

| Area | Current location | Disposition |
| --- | --- | --- |
| The notion that Grimlock starts/executes admitted runs | docs and `core.Service` | Deprecated immediately; remove once runtime migration is complete. |
| Session/run/approval/event JSON schemas | `spec/schemas/v1alpha1` | Move to a separately versioned Grimoire runtime contract, then retire from the registry contract. |
| `Grimrock` as a Grimoire product name | `subsystems/grimrock` | Deprecate; its code migrates to runtime layers. |
| Consumer-specific Laravel AI execution integration | `adapters/laravel-ai` | Split registry persistence from execution features; move reusable Laravel AI execution into Gouge and keep consumer bindings outside the registry service. |

## Integration contracts

### Grimoire → Runtime

A runtime consumes an immutable `ResolvedAgentDefinition` by exact `Agent` name and version. It contains the requested agent manifest and the exact versions of its declared tools and skills, plus a stable digest. It contains no session, run, user, provider client, credential value, or executable callback.

### Consumers → Runtime

Consumers choose a runtime and send work using a runtime-facing contract. The payload contains consumer-owned context and an exact Grimoire agent reference. Consumers do not construct agent classes or tool proxies.

### Runtime → Consumers

Runtime systems emit normalized execution lifecycle events. Consumers store and present them as their own message/event records; they remain the source of truth for conversation and coordination data.

The shared runtime contract is an extraction target, not a finished cross-runtime API. See [runtime-family.md](runtime-family.md) for minimum semantics, native extensions, and release criteria. Registry and runtime contract versions must evolve independently.

## Communication integration

Figurate owns A2A, ACP, and A2UI communication extensions, including transport authentication, task/message correlation, durable coordination, outboxes, lifecycle projection, and UI validation/rendering contracts. Grimoire runtimes expose runtime-owned execution APIs and events. Figurate owns the bridge that translates its Work Exchange into those APIs and maps runtime events back into consumer updates. Grimoire contains no Figurate bridge or Work Exchange dependency. See [communication ownership and cleanup](communication-ownership.md).

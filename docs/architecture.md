# Grimoire architecture

Grimoire owns the agent-definition contract and its official runtime implementations. The Grimoire registry is the source of truth for definitions; it does not run agents.

The system has three explicit owners:

```text
Consumers                        Grimoire runtimes                 Grimoire registry
-------------                   -----------------                 -----------------
chat, coordination               sessions, runs, cancellation      versioned agent definitions
routing and actors                model/provider invocation        tools, skills, capabilities
delivery and audit records        agent loops and sandboxes         policy metadata and requirements
                                 tool execution and delegation    publication, lookup, resolution
                                                             immutable definition digests
```

## Runtime implementations

Grimoire owns multiple runtime implementations without embedding them in the registry service:

- **Gouge** (`packages/gouge`) implements the runtime contract with Laravel AI.
- **Go SDK** is the planned native Go implementation; the current ADK-Go execution code is migration input for it.
- **Pi** is a planned implementation for the [Pi agent harness](https://pi.dev/), integrated through its SDK or RPC mode while preserving Pi-native extensions, skills, sessions, and events.

Each implementation is independently versioned and deployed. It resolves immutable definitions from the registry, owns its native session and execution state, and emits the shared lifecycle contract back to consumers.

This is the target architecture. Gouge now has consumer-independent execution contracts and storage; the Go SDK and Pi implementation are planned. The [runtime family plan](runtime-family.md) defines the extraction sequence and the compatibility work required before claiming conformance.

## Grimoire's public contract

Grimoire owns immutable, versioned manifests for:

- `Agent`: instructions, configuration, tool and skill references, policy metadata, and runtime requirements.
- `Tool`: capability declaration, schemas, side-effect classification, idempotency and approval requirements.
- `Skill`: immutable instruction bundle metadata, content reference, and digest.
- `Connector` and related capability declarations: protocol and opaque credential-reference metadata only.

The registry accepts an immutable definition once under an exact name/version and resolves it into a `ResolvedAgentDefinition` for runtime systems. A resolved definition includes the agent and the exact declared versions of its tools and skills plus a stable digest. Each version carries a `draft`/`published`/`retired` publication lifecycle; only `published` versions are exposed via `ResolveAgentDefinition`, `ListPublished*`, and the registry HTTP lifecycle/listing endpoints (`transport/registryhttp`).

The Grimoire registry service deliberately does **not** own:

- sessions, runs, runtime events, cancellations, or approval decisions;
- model/provider clients, agent loops, sandboxes, tool invocation, or sub-agent delegation;
- credentials values, external product data, or chat/conversation delivery.

Static policy is stored in a definition; enforcement occurs in runtime systems.

## Integration flow

1. A consumer chooses an exact Grimoire agent reference for an actor or workflow.
2. A runtime system resolves that reference from Grimoire and verifies the returned definition digest.
3. The runtime executes the definition with its own session, run, provider, sandbox, tool, and delegation state.
4. The runtime returns lifecycle events to the consumer.
5. The consumer persists and presents those events in its own product model.

The complete migration map is in [registry-runtime-boundary.md](registry-runtime-boundary.md).

## Migration status

The repository currently contains historical execution code under `subsystems/grimlock/core`, `storage`, `transport/httpapi`, and `subsystems/grimrock`. It is retained temporarily as migration source material for Gouge and the planned Go SDK and Pi implementations. `cmd/grimlockd` already serves only the registry transport, although shared packages and the root Go module still contain migration dependencies. New registry-service work must remain registry-only; execution work belongs in an independently versioned Grimoire runtime implementation.

## Communication integration

Figurate owns A2A, ACP, and A2UI communication extensions, including transport authentication, task/message correlation, durable coordination, outboxes, lifecycle projection, and UI validation/rendering contracts. Grimoire runtimes expose runtime-owned execution APIs and events. Figurate owns the bridge that translates its Work Exchange into those APIs and maps runtime events back into consumer updates. Grimoire contains no Figurate bridge or Work Exchange dependency. See [communication ownership and cleanup](communication-ownership.md).

# Grimoire runtime family

Status: target architecture and extraction plan. This document refines the [registry boundary](registry-runtime-boundary.md); it does not declare a released runtime protocol or working Go/Pi packages.

Grimoire owns the definition contract, registry service, shared runtime contract, and official runtime implementations. These are separate release and execution boundaries. Consumers select a runtime; the registry publishes and resolves definitions without selecting providers, starting workers, or receiving turns.

## Implementation and packaging boundaries

| Component | Current state | Target responsibility |
| --- | --- | --- |
| Grimlock registry | `cmd/grimlockd` loads a catalog and serves registry HTTP. Legacy execution types still share packages and the root Go module. | Publish and resolve immutable definitions through an execution-free service and portable contract. |
| Gouge / Laravel AI | Directly tracked `webong/gouge` Composer package at `packages/gouge`, with extracted host integration still present. | Laravel-native execution, queues, provider integration, runtime persistence, and policy enforcement. |
| Native Go SDK | Planned; `subsystems/grimrock` and Grimlock execution code are migration inputs. | A native Go execution API and runtime state interfaces. ADK-Go can be an engine implementation without defining the public SDK. |
| Pi implementation | Planned; no package or supported Pi version is pinned here. | A Grimoire adapter around Pi's native harness, with definition resolution, policy enforcement, and normalized lifecycle output. |
| Consumer bindings | Gouge currently includes product-specific bindings. | Optional integration modules that translate a consumer's work contract into runtime requests and runtime events back into product records. |

Only Gouge currently has an established runtime package identity. Go/Pi package names and repository locations remain open. Runtime implementations may share conformance fixtures without sharing storage tables, provider clients, or native session representations.

The registry contract, runtime contract, and each runtime package must have independent versions. Each runtime release must declare the registry/runtime contract versions and optional capabilities it supports. An unsupported required capability must fail before model or tool invocation; optional native behavior must never silently weaken a declared policy.

## Definition resolution and execution

The following are requirements for the extracted runtime contract, not new registry endpoints:

1. A consumer supplies an exact agent name/version, its own correlation identifier, work input, and runtime context. The runtime authenticates the caller and authorizes execution using host-supplied identity and credentials.
2. The runtime resolves the published `ResolvedAgentDefinition`, verifies its shape and digest, and checks runtime requirements. It binds the accepted run to that immutable definition and its digest.
3. The runtime maps tool declarations to explicitly configured implementations and verifies referenced skill content. A manifest never supplies an executable callback or a credential value. Missing tools, incompatible capabilities, or unverifiable content reject admission before execution.
4. The runtime owns its session/run identifiers, native conversation state, model calls, approval decisions, tool admission, idempotency records, cancellation, and execution events.
5. The consumer receives correlated lifecycle events and maps them into its product model. Product identifiers are opaque context to the portable runtime contract; native session files or database rows are not a consumer integration API.

Cache immutable content by digest. For new work, resolve publication eligibility before admission; a cached artifact alone does not establish that a version is still published. An accepted run keeps its pinned definition. Publication retirement prevents new admission after retirement is observed; it is not a cancellation signal for work already accepted. Stronger revocation would require an explicit runtime operation. Offline admission is outside the initial contract.

Definition digests detect content changes; they do not authenticate the registry. Transport authentication and consumer/service identity remain deployment concerns outside the definition payload.

### Digest compatibility prerequisite

The current digest in `api/v1alpha1/resolved_definition.go` hashes a Go `encoding/json` serialization of the envelope without its `digest` field. Tool and skill order follows the agent's references. This is not yet a specified language-neutral canonicalization algorithm: PHP and TypeScript must not assume their default JSON encoders produce identical bytes.

Before enabling independent verification in Gouge or Pi, specify exact serialization rules and publish shared vectors covering empty collections, omitted fields, Unicode/escaping, nested schemas, and numbers. Either preserve the current bytes with a documented compatible implementation, or introduce a versioned digest format and an explicit migration. Do not silently change existing definition digests.

## Shared lifecycle semantics

Extract the existing session/run/approval/event schemas into a separately versioned runtime contract before adding native implementations against them. Preserve the legacy API until compatibility coverage permits retirement. The portable surface needs these semantics:

| Concern | Required behavior |
| --- | --- |
| Admission | Return a correlated rejection without side effects, or an accepted runtime run identity bound to the exact definition digest. |
| Events | Carry contract version, stable event identity, run identity, consumer correlation, and per-run ordering. Replayed delivery must not create duplicate consumer effects. |
| Completion | Distinguish successful completion, failure, and acknowledged cancellation. Emit one authoritative terminal outcome even when completion races cancellation. |
| Cancellation | A request is not proof that execution stopped. Report native cancellation limits and wait for the runtime's terminal outcome; retain evidence of effects already performed. |
| Approval | Bind decisions to the run and exact tool invocation. Never reuse a decision for changed arguments or execute while approval remains unresolved. |
| Idempotency | Define key scope, argument matching, reservations, and replay behavior. A tool whose effect may have completed before a crash must not be blindly retried. |
| Native extensions | Advertise supported behavior separately. Preserve native details as versioned extension payloads without requiring every runtime to emulate them. |

Shared conformance means agreement on these observable behaviors, not identical token streams, model answers, session formats, or scheduling. Durable event replay and recovery behavior must be declared and tested before a release advertises them.

## Gouge extraction

`GougeServiceProvider` currently imports application events and the host work-exchange registry, mutates host configuration, and registers product listeners. `Runtime/LaravelAiRuntime.php` resolves product resources, while `Coordination/GougeBridge.php` translates host work requests. Repository extraction and Composer metadata therefore do not yet establish standalone runtime portability.

Split these responsibilities in this order:

1. Establish a runtime request/result boundary and registry resolver with digest verification, independent of application models.
2. Move product resource lookup, work-exchange registration, delivery listeners, and conversation projection behind an optional consumer binding. Its dependencies must be explicit and its provider separately activated.
3. Keep Laravel AI execution and runtime state in Gouge's core provider. A plain Laravel host must be able to boot it without product classes or consumer routes.
4. Move reusable invocation, admission, approval, and idempotency behavior from `subsystems/grimlock/adapters/laravel-ai` after contract coverage exists. Split migrations so runtime installation does not require registry or consumer tables.

The consumer binding may depend on the consumer's neutral versioned work-exchange contract. The consumer core must never acquire a dependency on Gouge or another runtime. Figurate authentication and service identity remain first-party in `mod/auth`; Gouge consumes supplied identity through the binding and does not absorb that module.

## Go SDK extraction

Extract the public runtime API and state interfaces before moving the ADK-Go implementation. Inventory `core/service.go`, policy/executor code, runtime SQLite storage, and `transport/httpapi` against the shared lifecycle semantics. Separate registry types and catalog code from execution packages so registry consumers do not inherit an agent engine dependency.

Keep ADK-Go-specific event translation and provider configuration behind its engine adapter. The native Go SDK must resolve and verify immutable definitions without importing Gouge or driving Pi as its implementation. A runtime service can wrap the SDK; `cmd/grimlockd` remains the registry executable.

## Pi integration decision

Pi documents both an embeddable SDK and JSON RPC over stdin/stdout. The planned default is an SDK-based adapter for direct control of session setup, tool registration, and event translation. RPC remains an alternative for a separately supervised Pi process; neither mode has been selected by a compatibility spike yet. See [Pi's programmatic usage documentation](https://github.com/earendil-works/pi/tree/main/packages/coding-agent) and [RPC reference](https://github.com/earendil-works/pi/blob/main/packages/coding-agent/docs/rpc.md), consulted 2026-10-10.

Before choosing and pinning a Pi release, prove that the adapter can:

- Build a session from the pinned definition while making local instructions, extensions, skills, and built-in tools explicit deployment inputs. Ambient configuration must not silently expand admitted capabilities.
- Gate every available execution path with the declared policy, including native tools. A prompt-level instruction is not an approval or permission mechanism.
- Map native session branches, streaming events, errors, and cancellation to the shared lifecycle without claiming completion when only a request was acknowledged.
- Resume with the same definition digest and reject incompatible resume state. Keep Pi session persistence runtime-owned.

If an integration mode cannot enforce required semantics, it must reject that definition. SDK/RPC transport alone does not confer Grimoire conformance.

## Extraction sequence and release evidence

1. Specify the runtime contract and digest vectors; reuse historical fixtures as migration evidence, without presenting them as completed cross-runtime coverage.
2. Separate Gouge core from its consumer binding and extract the Go runtime API/state interfaces. Keep the legacy paths until their replacements have equivalent behavior.
3. Prove Gouge on a plain Laravel host and the Go SDK with a deterministic engine; cover definition mismatch, unsupported capabilities, approval, idempotent replay, cancellation races, and recovery after ambiguous tool effects.
4. Run the Pi SDK/RPC compatibility spike, pin a release, and apply the same relevant fixtures. Document optional capability differences.
5. Integrate host work exchange through optional bindings. Verify that registry startup and consumer startup both succeed without a runtime installed.
6. Retire legacy execution APIs, schemas, and runtime tables only after their consumers have migrated and rollback/data migration requirements are documented.

No existing runtime data or migration source is removed by this plan. Registry-only behavior in `grimlockd` is already implemented; independent package boundaries and runtime portability remain extraction work.

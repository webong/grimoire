# Grimoire / Runtime extraction tracker

Ownership is fixed:

- **Grimoire** owns the definition contract, registry, and runtime implementations in one repository with explicit package boundaries.
- **Grimlock registry** is the definition source of truth and never executes agents.
- **Grimoire runtimes** (Gouge, planned native Go SDK, planned Pi implementation) execute agents and own all run-time concerns.
- **Host consumers** are chat/coordination clients that call a runtime.

Status: `[x]` verified, `[~]` transitional or migration source, `[ ]` not started.

| Status | Workstream | Acceptance evidence |
| --- | --- | --- |
| [x] | Portable definition contract | Versioned Agent, Tool, Skill, Client, Device, and Connector declarations exist with JSON schemas, declarative permission/capability metadata, and immutable catalog registration. |
| [~] | Registry persistence | `catalog_entries` is reusable registry storage. Client/device last-seen inventory and all session/run tables are runtime state and must leave the registry surface. |
| [~] | Legacy execution code classification | `core.Service`, state/policy/executor code, SQLite session/run/event tables, HTTP control-plane calls, Grimrock, and Laravel execution tools are migration sources for Grimoire-owned runtime implementations. |
| [x] | Immutable resolved definitions | `ResolvedAgentDefinition` resolves an exact agent reference to its immutable manifest plus exact tool/skill declarations and a SHA-256 digest. Catalog and HTTP lookup tests verify it. |
| [x] | Publication lifecycle | `Catalog` tracks `draft`/`published`/`retired` per version; `Publish`/`Retire`/`PublicationStatus` enforce the lifecycle, `ResolveAgentDefinition` and `ListPublished*` expose only `published` versions, durable `catalog_entries.status` column and Laravel migration persist it, `catalogfs` auto-publishes manifests, and catalog/HTTP tests verify draft filtering and retired exclusion. |
| [x] | Registry API | Registry-only HTTP now exposes `GET /v1/registry/agents|tools|skills` listing plus `POST /v1/registry/{kind}/{name}/{version}/publish|retire` lifecycle and `GET /v1/registry/agents/{name}/{version}/resolved` digest-verified lookup; legacy `transport/httpapi` session/run/approval/event endpoints remain migration sources to retire. |
| [~] | Registry executable | `cmd/grimlockd` constructs a catalog and registry HTTP server only. Shared Go packages and the root module still contain historical execution dependencies. |
| [ ] | Cross-language digest verification | Specify canonical bytes or a versioned digest format and shared Go/PHP/TypeScript vectors before claiming independent runtime verification. |
| [~] | Gouge packaging | `packages/gouge` is a directly tracked Composer package with runtime metadata. Its core provider and execution path still depend on host application classes; extract an optional consumer binding. |
| [ ] | Shared runtime contract | Extract lifecycle schemas and fixtures into a separately versioned contract; define admission, event delivery, cancellation, approvals, idempotency, and capability negotiation. |
| [ ] | Go SDK migration | Move Grimrock's engine contract, runtime state, enforcement, and events into the native Go SDK, keeping ADK-Go behind an engine adapter. |
| [ ] | Pi implementation | Prove SDK/RPC compatibility with admission and tool policy, pin a supported release, and implement the shared contract. |
| [ ] | Host runtime integration | Host consumers select an agent reference and send work to a runtime; they consume normalized runtime events without constructing agent classes or tools. |
| [ ] | Cutover cleanup | Delete deprecated registry execution code and legacy host runtime adapters after validated migration cutover. |

## Current implementation order

The registry-only API and publication lifecycle are already present. Continue with the [runtime family extraction sequence](runtime-family.md#extraction-sequence-and-release-evidence):

1. Specify the runtime contract and cross-language digest vectors.
2. Separate Gouge core from consumer bindings and extract the native Go runtime API/state interfaces.
3. Prove standalone Gouge/Go execution and Pi SDK/RPC compatibility against shared fixtures.
4. Integrate host work exchange through optional consumer bindings.
5. Retire migration-source execution code from the registry surface after cutover.

## Guardrails

- Grimoire definitions never include credential values, provider clients, executable callbacks, sessions, or user/chat data.
- Runtimes may cache a resolved definition by digest but never change it; new admission must also establish publication eligibility.
- Host consumers never directly read Grimoire persistence or runtime state.
- Do not extend execution code inside registry packages except for mechanical migration support. New execution features belong in runtime implementations.

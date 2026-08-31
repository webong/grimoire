# Grimoire architecture and Grimlock extraction plan

Grimoire is the parent project. Grimlock is its control plane; Grimrock is its execution plane.

Grimrock accepts an admitted run from Grimlock, dispatches it to a native engine, and normalizes engine events. It does not duplicate Grimlock's catalogue, policy, approval, or persistence authority.

## Decision

Create Grimlock, within Grimoire, as an engine-neutral **agent management control plane**. It owns agent definitions, tool and skill catalogues, admission and authorization policy, session/run records, approvals, credentials references, and a normalised event stream.

It does **not** own Figurate's social/domain model or Jangolova's interaction engines. It also does not try to merge Google ADK and Laravel AI SDK into one in-process runtime. They are separate execution adapters for the same control-plane contract.

This is the correct cut because the existing systems overlap at management concerns but have distinct responsibilities:

| Capability | Extract from | Keep in host application |
| --- | --- | --- |
| Agent specification, instructions, roles, constraints, versioning | Figurate sub-agent classes; Jangolova `AgentSpec` | Figurate presenter/thread selection; Jangolova interaction semantics |
| Tool descriptors, JSON schema, effects, allowlists, reauthorization, approvals | Jangolova `InteractionTools` and `CapabilityPolicy`; Figurate tool resolver | Jangolova bridge calls; Figurate business actions |
| Skill discovery, metadata, references, and content loading | Figurate `SkillRepository` | Figurate posts/filesystem locations |
| MCP, ACP, and A2A connection records and discovery | Figurate registries; Jangolova MCP/ACP adapters | Each application's channel/credential storage and UI |
| Session lifecycle, event cursors, cancellation, confirmation | Jangolova Grimlock service | Existing Laravel conversation tables and Jangolova target attachment lifecycle |
| Workflows and delegation | Figurate sub-agent dispatcher; ADK workflows | Host-specific queues, messaging, and presentation |

## Target shape

```text
                         Figurate / another product
                       users, threads, channels
                                    |
                     Laravel adapter / control-plane client
                                    |
      +-------------------- Grimlock control plane --------------------+
      | catalogue | policy | sessions/runs | approvals | event ledger   |
      |  Agent, Tool, Skill, Connector, Workflow, CredentialRef        |
      +-------------------------+--------------------------------------+
                 portable manifest | execution lease + normalised events
                       +-----------+-----------+
                       |                       |
             ADK-Go execution adapter   Laravel-AI execution adapter
                       |                       |
        Jangolova interaction tools      Laravel tools / MCP / A2A / ACP /
        browser, display, native APIs     queues, broadcasts, RAG, domain APIs
```

The control plane is the authority for *what may run*. An execution adapter is responsible for *how it runs* using its native SDK.

## Portable contract

The first public contract should be versioned JSON Schema/OpenAPI, not Go or PHP types. A manifest contains:

```yaml
apiVersion: grimlock/v1alpha1
kind: Agent
metadata:
  name: researcher
  version: 1.0.0
spec:
  execution:
    adapter: adk-go                 # or laravel-ai
    modelProfileRef: primary-chat
  instructions: Gather source-backed evidence only.
  skills:
    - ref: research-playbook@1
  tools:
    - ref: web.search@1
    - ref: jangolova.browser.dom-click@1
  budgets:
    maxToolCalls: 20
    timeoutSeconds: 180
  delegation:
    allowedAgents: [planner, browser]
```

The related first-class resources are:

- `Agent`: instructions, runtime adapter, model profile reference, declared tools/skills, budgets, delegation.
- `Tool`: stable name/version, JSON input/output schemas, effect (`read`, `write`, `external`), idempotency and approval hints, executor binding.
- `Skill`: metadata, immutable instruction/reference bundle, scope, provenance and digest. A skill is context, not executable authority.
- `Client`: a managed application, IDE, or device bridge identity.
- `Device`: a managed browser, display, native host, or service target associated with a client and capability endpoints.
- `Connector`: an MCP/A2A/ACP/native endpoint descriptor plus an opaque `CredentialRef`; secret values are never in manifests or events.
- `Policy`: admission rules, actor scope, tool budgets, network constraints and approval requirements.
- `Session` and `Run`: immutable identifiers, selected agent version, subject binding, state, event cursor, trace IDs and retention policy.
- `Approval`: an explicit, expiring decision for an identified proposed tool call; it is not a replacement for authorization.

`Tool` needs a stronger contract than either source currently exposes:

```yaml
kind: Tool
metadata: { name: jangolova.browser.dom-click, version: 1.0.0 }
spec:
  effect: write
  inputSchema: { type: object }
  outputSchema: { type: object }
  idempotency: caller-key-required
  approval: required
  executor:
    kind: interaction-capability
    binding: browser
    capability: dom.click
```

The control plane evaluates policy twice: once before advertising a tool to the model, and again immediately before dispatch. All non-read effects require a durable approval decision by default. This preserves Jangolova's strongest safety property while making it usable by Laravel agents too.

## Adapter responsibilities

### ADK-Go adapter

Extract and generalise Jangolova's existing runtime, rather than copying its interaction engine:

- Map an admitted Grimlock `Tool` to an ADK `tool.Tool`, including its JSON schema and confirmation behaviour.
- Create ADK agents/runners from a leased `Agent` version and emit normalised run events.
- Use ADK's sessions, streaming, tool confirmation, graph workflows, and parallel/loop primitives where those are the requested execution semantics.
- Keep Jangolova bindings in a separate `grimlock-jangolova` adapter that converts discovered bridge capabilities into portable `Tool` descriptors.

The current Jangolova service already demonstrates this shape: its HTTP/MCP/ACP transports share one service, the model profile keeps credential references opaque, and capability tools validate input, authorize again at execution, and confirm side effects.

### Laravel-AI adapter

Extract Figurate's product-level management features into a Composer package and adapter:

- Resolve a versioned agent manifest into a Laravel AI `Agent` with `HasTools`, `HasMiddleware`, `RemembersConversations`, structured output, queueing/streaming, and native provider tools when appropriate.
- Turn each admitted Grimlock tool into a Laravel AI tool proxy. The proxy calls the control plane for authorization/approval and delegates to a host-registered executor only after the execution lease is granted.
- Replace the fixed `SubAgentDispatcher` registry with a manifest-backed resolver and preserve trace/parent invocation IDs as run metadata.
- Replace `SkillRepository`'s direct filesystem/post aggregation with a `SkillCatalogProvider` supplied by the Figurate adapter. Figurate remains free to source skills from posts, resource paths, modules and channels.
- Keep Figurate's `Thread`, `Space`, `User`, `Channel`, outbox, queues, RAG and presenter middleware in Figurate. They are tenancy and product concerns, not Grimlock core.

Laravel AI should be the preferred adapter when the work must execute close to Laravel data, policies, queues, broadcasts or conversation storage. ADK-Go should be preferred for portable interaction-heavy agents and graph-like orchestration. A run selects one execution adapter; cross-adapter delegation goes through a child Grimlock run, never a direct SDK-to-SDK call.

## API and protocol boundary

Ship a native HTTP API first:

1. Catalogue: list/get/register immutable agent, tool and skill versions.
2. Sessions: create, inspect, close and resume a session with subject bindings.
3. Runs: create, stream/read cursor events, cancel, and fetch evidence.
4. Approvals: list and resolve a pending action using an approval ID bound to the proposed input digest.
5. Discovery: expose policy-filtered tools, agents, skills and connectors.

MCP, ACP and A2A are adapters over those application services, not competing session runtimes. Preserve Jangolova's rule that northbound transports cannot duplicate model, policy, tool-routing or approval logic.

Use a common event envelope from day one:

```json
{
  "apiVersion": "grimlock/v1alpha1",
  "runId": "run_...",
  "sequence": 42,
  "type": "tool.approval.requested",
  "traceId": "...",
  "parentRunId": "run_...",
  "occurredAt": "2026-08-26T00:00:00Z",
  "data": {}
}
```

Normalise at least `run.started`, `model.delta`, `tool.advertised`, `tool.call.requested`, `tool.approval.requested`, `tool.completed`, `run.completed`, `run.failed`, and `run.cancelled`. Preserve each SDK's raw event only as redacted adapter metadata.

## Deliberate non-goals

- No global, unscoped tool registry that gives every agent every tool.
- No raw credentials in YAML, prompts, tool results, traces or events.
- No implicit replay of writes after ambiguous transport errors.
- No copying Figurate's Eloquent models or Jangolova's engine provider into core.
- No claim that a Laravel conversation row and an ADK session are the same persistence object. They are linked through Grimlock IDs and correlated events.

## Extraction sequence

### Phase 0 — contract and conformance tests

Create Grimoire as a monorepo with a `subsystems/grimlock/` boundary containing `spec/`, `core/`, `adapters/adk-go/`, `adapters/laravel-ai/`, `adapters/jangolova/`, and `conformance/`. Define schemas, event envelopes and tool-effect behaviour. Build test fixtures for read-only visibility, policy re-check, approval, cancellation, tool error, and idempotency.

### Phase 1 — core catalogue and policy service

Implement immutable versions of Agent/Tool/Skill. Implement policy evaluation, approval records, execution leases, credential-reference resolution boundary, and an append-only event ledger. Start with Postgres and an outbox; do not migrate existing product data yet.

### Phase 2 — ADK-Go adapter

Move the generic portions of Jangolova's `internal/grimlock` into the adapter. Keep its HTTP/MCP/ACP server only until the new control-plane endpoints replace it. Move interaction capability discovery into the Jangolova adapter and prove that its existing `read`/`write`/`external` behaviour passes conformance.

### Phase 3 — Laravel-AI adapter

Publish a Laravel package that resolves agent versions, registers Laravel tool proxies and persists correlation IDs beside native conversations. Migrate Figurate's skill discovery and sub-agent registry behind provider interfaces first, then make them use the control-plane catalogue. Keep all Figurate middleware and domain tools in place.

### Phase 4 — protocol migration and orchestration

Move Figurate's MCP/ACP/A2A registries to connector providers, retain its channel-derived credentials as a provider implementation, and expose discovery through the shared policy. Add child runs for delegation and only then introduce ADK graph workflows as an optional execution strategy.

### Phase 5 — cutover

Dual-read catalogue definitions and dual-write run/audit events. Compare events and tool outcomes using Figurate's existing `DualWriteDiffTool` pattern. Switch per agent version behind a feature flag. Retire old registries only after the conformance suite and production shadow comparison agree.

## Immediate first milestone

Implement Phase 0 as the first mergeable increment:

1. `grimlock/v1alpha1` JSON schemas for Agent, Tool, Skill, Session, Run, Approval and Event.
2. A language-neutral fixture suite that both adapters must pass.
3. A Go package for ADK tool adaptation and a Laravel package for Laravel AI tool proxies, each initially backed by in-memory repositories.
4. One Jangolova browser capability and one Figurate `DiscoverSkillsTool`/sub-agent flow running through their respective adapters.

Acceptance: the same policy fixture can hide a write tool, advertise it after grant, request approval, re-authorize it with input, execute exactly once with an idempotency key, and return correlated events in either runtime.

## Evidence from the current codebases

- Jangolova already supplies the essential Go-side foundations in `internal/grimlock`: `Runtime.CreateInteractionAgentWithTools`, effect-aware `InteractionTools`, `CapabilityPolicy`, session/run/event handling, and shared HTTP/MCP/ACP application service adapters.
- Figurate already supplies the management-side foundations in `app/Ai`: `ToolResolver`, `SkillRepository`, `SubAgentDispatcher`, per-context MCP/A2A/ACP registries, Laravel AI agents, and middleware enforcing participants, safety, budgets, evidence and response quality.
- Figurate currently depends on `laravel/ai ^0.10.1`; the Laravel AI SDK provides agents, tools, conversations, middleware, streaming, queueing and provider tools. Google ADK Go v2 supplies LLM agents, tools, sessions, streaming, workflows and human-in-the-loop confirmation. See the [Laravel AI SDK documentation](https://laravel.com/framework/docs/13.x/ai-sdk) and [Google ADK Go documentation](https://adk.dev/get-started/go/).

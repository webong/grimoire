# Development

Figurate is developed as an API-first coordination platform for third-party systems.

## Product Boundary

The stable product surface is the platform contract exposed through:

- HTTP APIs
- webhooks
- WebSockets
- MCP
- A2A
- ACP

The bundled Inertia workspace and Filament control panel are first-party clients and operational tools. They must consume and demonstrate the same domain capabilities available to third-party clients; they should not become a separate source of business behavior.

## Runtime Structure

- `app`: core coordination runtime, API controllers, agents, tasks, events, and policies.
- `config`: platform and integration configuration.
- `mod`: enabled product modules.
- `ext`: optional deployment or protocol extensions.

The core domain remains independent of any single integration:

- `Space`: long-lived context.
- `Thread`: active session or workstream.
- `Post`: durable message, event, artifact, decision, or result.
- `Channel`, `ChannelRoute`, and `ChannelAddress`: external-system ingress and delivery mapping.
- `AgentTask` and `ThreadEvent`: execution state and traceability.

## Deployment Targets

- **Remote:** the primary target for third-party systems, hosted in a cloud, server, VPS, or internal network.
- **Device:** a local/private target using the same API-oriented runtime for device-bound context and actions.

Device and Remote nodes may be connected, but clients should integrate through explicit contracts instead of relying on UI implementation details.

## Development Direction

1. Define and version external API and protocol contracts.
2. Keep controllers transport-focused and put orchestration in reusable services.
3. Make authentication, authorization, tenancy, idempotency, and correlation explicit for machine clients.
4. Provide reliable inbound ingestion and outbound callback delivery.
5. Preserve every meaningful action as durable context, task state, or an auditable event.
6. Treat domain applications such as fulfillment, ticketing, and change review as consumers of Figurate rather than built-in product identities.

## Archived Summary of Early Design Exploration (2026-02-03 to 2026-02-13)
Archived summary from `PLAN.md` (2026-02-03 to 2026-02-13):

1. Product scope was defined as a cross-platform system (web + Native shell) with Signal, Studio, and Station/admin surfaces.
2. The fulfillment journey was stabilized around:
   enquiry -> quote -> booking -> assessment -> acknowledge -> billing -> tracking -> settlement.
3. Chatbox-first direction was adopted:
   one visible user conversation, with internal lifecycle handling through channel/thread orchestration.
4. Channel/thread architecture evolved to:
   channel as UX container, thread as phase/purpose context, messages as timeline, actors for routing, and per-actor memory support.
5. Orchestration direction introduced `ConversationOrchestrator` with explicit active-thread resolution, responder selection, and policy-safe transitions.
6. Tooling direction added role-aware agent tools via resolver/executor patterns for domain writes (order/assessment style actions) with auditability.
7. Data modeling explored both:
   classic request/order/assessment/process/payment relationships, and an event-style `posts`/`post_relations` approach.
8. Guardrails were repeatedly emphasized:
   deterministic transitions, idempotent behavior, auditable system events, and clear policy boundaries.
9. Runtime/deployment notes captured monolith dual-surface operation:
   server DB migrations and Native-local DB migrations loaded conditionally by environment.

## ADR Scope: AI Interoperability (A2A, A2UI, MCP)

Date range: 2026-03-02 to 2026-03-08  
Status: Implemented baseline and in active hardening

### Why this was needed

We needed our AI service to work safely with:

- other agents (A2A),
- dynamic UI payloads from agents (A2UI),
- external tools/context servers (MCP),

while keeping one consistent product experience for users.

### Decisions we made

1. We support A2A as a first-class integration path for inbound and outbound agent collaboration.
2. We keep ownership boundaries on tasks so callers can only access their own task lifecycles.
3. We model execution as traceable events, so task history and agent actions are auditable.
4. We support A2UI as a reusable rendering/runtime layer so agent-provided UI can be shown consistently.
5. We support MCP through controlled server resolution and invocation policy instead of open-ended tool execution.

### What is done

1. A2A transport endpoints, task lifecycle operations, streaming updates, and push callback flow are implemented.
2. A2A authentication and per-method access controls are in place.
3. Task ownership checks are enforced for task reads, listing, cancellation, and subscription-style flows.
4. Outbound agent delegation and remote-task reconciliation are implemented.
5. A2UI runtime pieces are in place (surface renderer, field registry, client/runtime composables, page integration).
6. MCP support for context server discovery, resolution, and tool invocation is integrated with the AI tool layer.
7. Core execution/event linkage is in place so A2A and MCP activity can be tracked end-to-end.

### What remains

1. Complete webhook trust hardening (signature, replay-window protection, and stricter deny logging).
2. Expand contract/security test coverage for lifecycle edge cases and reconnect behavior.
3. Finalize stricter outbound callback trust rules for non-local environments.
4. Continue simplifying documentation and ops playbooks so implementation details stay in code and tests.

### Product-level outcome

The platform now has a practical interoperability baseline:

- agents can coordinate with other agents,
- agent-generated UI can be rendered consistently,
- approved external tools can be invoked safely,

without changing the user-facing chat-first experience.

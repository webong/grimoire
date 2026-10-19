# Communication ownership and runtime cleanup

> Historical Gouge cleanup record. Gouge is now migration source rather than a
> target Grimoire product boundary.

This cleanup followed Figurate commit `f58f001` (2026-10-19). At that stage,
Figurate owned its independently composable `ext/a2a`, `ext/acp`, and `ext/a2ui`
extensions while the Gouge package held runtime execution. The current Grimoire
architecture retains only immutable registry and sandbox-materialization
responsibilities; runtime execution belongs to external hosts.

## Inventory and disposition

| Overlap found in Gouge | Cleanup |
| --- | --- |
| A2A Agent Card, JSON-RPC procedures, streaming endpoints, middleware, abilities, push callbacks/signatures and configuration | Removed. Consumers install Figurate's A2A extension. |
| ACP session/task API, RPC router, outbound client, channel driver and configuration | Removed. Consumers install Figurate's ACP extension. |
| A2A/ACP discovery and delegation tools with their own remote task reconciliation | Removed from the runtime tool resolver. Communication goes through the consumer's Work Exchange; it is not silently redirected to a second implementation. |
| A2UI catalog, payload contract, message projection, model-response parsing/normalization, and protocol-specific prompting | Removed. Runtime text is returned unchanged; consumer extensions validate and project any structured UI output. |
| `ThreadEventTaskService`, `TaskRecord`, `MessageTaskService`, product task/invocation endpoints | Removed. Gouge no longer maintains a duplicate communication task ledger. |
| Automatic reply enqueue, notification channels, channel delivery decorators and prompt outbox helper | Removed. Figurate chooses participants and owns durable dispatch/outboxes. |
| Runtime success handler creating reply posts and projecting protocol task updates | Replaced by opaque text output in runtime-owned events. Gouge stores execution output with invocation state for runtime event reads. |
| Protocol-specific transport tests and assertions in mixed tests | Removed with their implementation; new boundary tests check isolation and exclusion of consumer bridge dependencies. |
| Generic connector declarations in Grimlock | Retained as opaque declaration/executor boundaries; wording no longer advertises built-in A2A/ACP implementations. |

No persisted tables or historical data are dropped. The removed implementations remain recoverable from Git history. Deployments using Gouge's removed routes, notification channels, or implicit reply dispatch must switch to Figurate communication extensions and explicit Work Exchange dispatch. This is a breaking integration change, not a transparent endpoint alias.

## Consumer-owned integration

Grimoire exposes runtime-owned input, correlation, execution, storage, and event contracts. Gouge persists events in its own run records; `streamEvents` reads them without invoking product listeners or a consumer update receiver. There is no Figurate bridge or Work Exchange import in the package.

Figurate must build and register its bridge in its repository. It supplies authorized scope and exact agent references, calls the runtime, and maps runtime events to Work Exchange receipts and product output. Grimoire does not know which communication protocols the consumer uses. No Figurate repository changes are made by this cleanup.

The remaining host-model extraction is now complete at the Gouge package boundary: public DTOs no longer contain Thread/Post/User/Channel IDs, and execution/state no longer query those models. Provider listeners, application tools, MCP product endpoints, GraphQL schema, and host-table migrations have been removed. New sessions and runs use `gouge_sessions` and `gouge_runs`; existing consumer data remains untouched. See [Gouge's migration and runtime documentation](../packages/gouge/README.md).

Consumer independence does not imply full runtime conformance. Verified remote definition resolution, approval continuation, and crash reconciliation remain documented runtime-family work.

## Verification

Gouge tests boot a plain Laravel container with only runtime tables and a real pinned Laravel AI SDK fake gateway. They cover native model/tool execution, exact replay versus conflicting input, persisted history, scope/session isolation, cancellation races, queue jobs, and forbidden consumer imports. No live provider calls or consumer application boot is required.

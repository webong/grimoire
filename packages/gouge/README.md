# Gouge

Gouge is Grimoire's Laravel AI runtime implementation. It accepts runtime-owned input, executes explicitly registered agents/tools through Laravel AI, and persists scoped sessions, runs, and ordered lifecycle events. It does not require a consumer application's models, routes, authentication modules, communication protocols, or event listeners.

## Install and configure

Requires PHP 8.3+ and Laravel/Illuminate 12 or 13. Composer discovers `GougeServiceProvider`. Configure the normal Laravel AI provider credentials in the host's `ai` configuration. Gouge does not replace that configuration or Laravel AI's conversation store.

Run the package migration to create only `gouge_sessions` and `gouge_runs`. Set `gouge.connection` to select a database connection. Existing installations' historical conversation/actor tables are neither read nor dropped. This is a breaking replacement of the old embedded host API; there is no automatic data migration.

Provide trusted exact definition snapshots in `gouge.definitions`:

```php
'helper@1.0.0' => [
    'digest' => $verifiedDefinitionDigest, // 64 lowercase SHA-256 hex characters
    'instructions' => $verifiedInstructions,
    'tools' => [ApprovedLookupTool::class], // implements Laravel\Ai\Contracts\Tool
],
```

The default `ConfiguredAgentFactory` matches name, version, and digest to trusted deployment configuration. It does not fetch registry manifests, compute their canonical digest, enforce publication eligibility, or derive policies from manifests. Do not accept these registrations from untrusted callers. A registry-backed deployment must bind `Contracts\AgentFactory` to a resolver that verifies immutable definitions and admits tools/policies before returning a native Laravel AI agent. This is a runtime extension point, not a consumer communication adapter. Full cross-runtime registry conformance remains separate work.

## Execute and inspect

```php
use Webong\Gouge\Contracts\AgentRuntime;
use Webong\Gouge\Runtime\AgentTurn;

$runtime = app(AgentRuntime::class);
$turn = new AgentTurn(
    scopeId: 'tenant-17',
    sessionId: 'session-42',
    runId: 'run-99',
    agentName: 'helper',
    agentVersion: '1.0.0',
    definitionDigest: $verifiedDefinitionDigest,
    prompt: 'Summarize this request.',
    metadata: ['correlation_id' => 'opaque-consumer-reference'],
);
$runtime->submitTurn($turn); // synchronous; exceptions are recorded and rethrown
$events = $runtime->streamEvents($runtime->correlationFor($turn));
```

For queue execution, dispatch `new Webong\Gouge\Jobs\ExecuteAgentTurn($turn)`. The job contains runtime data only and defaults to one attempt. Native model/tool invocation is handled by `NativeAgentExecutor`; `AgentFactory`, `AgentExecutor`, and `RuntimeStore` are replaceable bindings. Set `gouge.provider`, `gouge.model`, and `gouge.timeout` for the default executor.

Consumers authenticate and authorize callers before invoking the runtime, including assigning the permitted `scopeId`. The scope is a storage partition, not proof of authorization. Metadata is opaque correlation context; it is not interpreted as instructions or used to locate product records. No consumer database ID is required.

## State and lifecycle

- Session identity is `(scopeId, sessionId)`. A session is pinned to its exact agent name/version/digest and permits one active run.
- Run identity is `(scopeId, runId)`. Repeating exactly the same serialized input returns without executing again; reusing the ID for changed input fails. Metadata and its serialized ordering are part of that identity check.
- The event snapshot contains ordered `turn.accepted`, `turn.started`, `turn.output`, and `turn.completed`, or a terminal failure/cancellation. Output text is preserved unchanged. `streamEvents` reads persisted events; it is not a live SSE stream or consumer delivery mechanism.
- Text and native tool-call/result history persist in the session. Native invocation ID, usage, and tool telemetry are retained with output. No consumer conversation store is installed.
- Cancellation before execution terminates the accepted run. During execution it records `turn.cancellation_requested`; the session remains busy until execution returns. The runtime then records `turn.cancelled` and discards late output. It cannot interrupt an in-flight provider/tool call or undo effects already performed.
- Completed and failed runs are not rerun, and terminal cancellation requests cannot rewrite their outcomes. Consumers should explicitly create a new run only after assessing any prior effects.

A process crash can leave an accepted/running run holding its session. There is deliberately no automatic lease expiry or replay: a tool may already have produced effects. Operational reconciliation must establish the outcome before clearing or migrating stranded state. Event snapshots are not consumer outboxes; consumers own delivery/replay receipts.

The default executor currently supports text and native tool history. Attachment persistence and approval continuation require a custom executor/state design; pending approvals fail explicitly instead of being reported as successful completion. This package does not yet implement the complete planned Grimoire runtime conformance contract.

## Consumer integration

Consumers build their own adapter against these runtime contracts. Figurate owns its Work Exchange bridge, communication extensions, identity/authorization, product projection, outboxes, and delivery. None of that integration ships in Gouge. The previous Thread/Post/User/Channel API, application tools, product MCP server, GraphQL hooks, and observers have been removed; their history remains in Git for consumer-side migration.

## Tests

Run `composer install` and `composer test` in this package. Tests boot a plain Laravel container with SQLite and the real Laravel AI SDK fake gateway, including a native tool call. An autoload guard rejects `App\` and `Figurate\` classes. No consumer application or live provider credentials are needed.

For an existing dependency installation, `GOUGE_TEST_AUTOLOAD` may point to its Composer autoloader; `GOUGE_TEST_AI_SOURCE` can point to a pinned Laravel AI source checkout. These are test-only overrides, not package runtime dependencies.

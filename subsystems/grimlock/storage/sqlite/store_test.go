package sqlite_test

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"path/filepath"
	"sync/atomic"
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	grimlocksqlite "github.com/akive/grimoire/subsystems/grimlock/storage/sqlite"
	_ "modernc.org/sqlite"
)

func TestStorePersistsApprovalEventsAndIdempotency(t *testing.T) {
	dbPath := filepath.Join(t.TempDir(), "grimoire.sqlite")
	db := openDatabase(t, dbPath)
	createSchema(t, db)

	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion, Kind: api.KindTool, Metadata: api.Metadata{Name: "message.send", Version: "1"},
		Spec: api.ToolSpec{Effect: api.EffectWrite, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Idempotency: api.IdempotencyCallerKeyRequired, Executor: api.ExecutorBinding{Kind: "fixture"}},
	}
	agent := api.Agent{
		APIVersion: api.APIVersion, Kind: api.KindAgent, Metadata: api.Metadata{Name: "operator", Version: "1"},
		Spec: api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "adk-go", ModelProfileRef: "test"}, Instruction: "Operate safely.", Tools: []api.ResourceRef{{Kind: api.KindTool, Name: "message.send", Version: "1"}}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindAgent, Name: agent.Metadata.Name, Version: agent.Metadata.Version}); err != nil {
		t.Fatal(err)
	}

	store, err := grimlocksqlite.New(db)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.SyncCatalog(t.Context(), catalog); err != nil {
		t.Fatal(err)
	}

	var sequence atomic.Uint64
	service, err := core.NewServiceWithStore(
		catalog,
		core.PolicyFuncs{AdvertiseFunc: func(context.Context, core.ToolCall) bool { return true }, AuthorizeFunc: func(context.Context, core.ToolCall) error { return nil }},
		executorFunc(func(_ context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
			return json.RawMessage(fmt.Sprintf(`{"leaseId":%q}`, lease.ID)), nil
		}),
		func(prefix string) string { return fmt.Sprintf("%s-%d", prefix, sequence.Add(1)) },
		store,
	)
	if err != nil {
		t.Fatal(err)
	}

	session, err := service.CreateSession("subject-1", api.ResourceRef{Kind: api.KindAgent, Name: "operator", Version: "1"})
	if err != nil {
		t.Fatal(err)
	}
	run, err := service.StartRun(session.ID)
	if err != nil {
		t.Fatal(err)
	}
	requested, err := service.RequestToolCall(t.Context(), core.ToolCallRequest{
		SessionID: session.ID, RunID: run.ID, ToolRef: agent.Spec.Tools[0], Arguments: json.RawMessage(`{"text":"hello"}`), IdempotencyKey: "request-1",
	})
	if !errors.Is(err, core.ErrApprovalRequired) || requested.Approval == nil {
		t.Fatalf("expected approval request, got result=%+v err=%v", requested, err)
	}
	completed, err := service.ResolveApproval(t.Context(), requested.Approval.ID, true)
	if err != nil {
		t.Fatal(err)
	}
	if len(completed.Output) == 0 {
		t.Fatal("expected persisted output")
	}

	if err := db.Close(); err != nil {
		t.Fatal(err)
	}
	db = openDatabase(t, dbPath)
	reopened, err := grimlocksqlite.New(db)
	if err != nil {
		t.Fatal(err)
	}
	events, err := reopened.Events(t.Context(), run.ID, 1)
	if err != nil {
		t.Fatal(err)
	}
	if len(events) != 3 || events[0].Type != "tool.approval.requested" || events[1].Type != "tool.approval.approved" || events[2].Type != "tool.completed" {
		t.Fatalf("unexpected durable events: %+v", events)
	}
	record, err := reopened.Idempotency(t.Context(), run.ID, agent.Spec.Tools[0], "request-1")
	if err != nil {
		t.Fatal(err)
	}
	if record.LeaseID != completed.Lease.ID || string(record.Output) != string(completed.Output) {
		t.Fatalf("unexpected durable idempotency record: %+v", record)
	}
}

func TestStoreClaimsAnIdempotencyKeyOnlyOnce(t *testing.T) {
	db := openDatabase(t, filepath.Join(t.TempDir(), "grimoire.sqlite"))
	createSchema(t, db)
	catalog := core.NewCatalog()
	tool := api.Tool{
		APIVersion: api.APIVersion, Kind: api.KindTool, Metadata: api.Metadata{Name: "status.read", Version: "1"},
		Spec: api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Idempotency: api.IdempotencyCallerKeyRequired, Executor: api.ExecutorBinding{Kind: "fixture"}},
	}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.Publish(api.ResourceRef{Kind: api.KindTool, Name: tool.Metadata.Name, Version: tool.Metadata.Version}); err != nil {
		t.Fatal(err)
	}
	store, err := grimlocksqlite.New(db)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.SyncCatalog(t.Context(), catalog); err != nil {
		t.Fatal(err)
	}
	first, claimed, err := store.ClaimIdempotency(t.Context(), core.IdempotencyRecord{
		RunID: "run-1", ToolRef: api.ResourceRef{Kind: api.KindTool, Name: "status.read", Version: "1"}, Key: "request-1", LeaseID: "lease-1", ArgumentDigest: "digest-a",
	})
	if err != nil || !claimed || first.Status != core.IdempotencyPending {
		t.Fatalf("expected initial reservation, record=%+v claimed=%t err=%v", first, claimed, err)
	}
	second, claimed, err := store.ClaimIdempotency(t.Context(), core.IdempotencyRecord{
		RunID: "run-1", ToolRef: first.ToolRef, Key: "request-1", LeaseID: "lease-2", ArgumentDigest: "digest-a",
	})
	if err != nil || claimed || second.LeaseID != "lease-1" || second.Status != core.IdempotencyPending {
		t.Fatalf("expected the original reservation, record=%+v claimed=%t err=%v", second, claimed, err)
	}
	first.Output = json.RawMessage(`{"ok":true}`)
	if err := store.CompleteIdempotency(t.Context(), first); err != nil {
		t.Fatal(err)
	}
	persisted, err := store.Idempotency(t.Context(), "run-1", first.ToolRef, "request-1")
	if err != nil || persisted.Status != core.IdempotencyCompleted || string(persisted.Output) != `{"ok":true}` {
		t.Fatalf("expected completed idempotency record, record=%+v err=%v", persisted, err)
	}
}

type executorFunc func(context.Context, core.ExecutionLease) (json.RawMessage, error)

func (f executorFunc) Execute(ctx context.Context, lease core.ExecutionLease) (json.RawMessage, error) {
	return f(ctx, lease)
}

func openDatabase(t *testing.T, path string) *sql.DB {
	t.Helper()
	db, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { _ = db.Close() })
	return db
}

func createSchema(t *testing.T, db *sql.DB) {
	t.Helper()
	statements := []string{
		`CREATE TABLE catalog_entries (id TEXT PRIMARY KEY, kind TEXT NOT NULL, name TEXT NOT NULL, version TEXT NOT NULL, digest TEXT NOT NULL, spec JSON NOT NULL, status TEXT NOT NULL DEFAULT 'draft', created_at DATETIME, updated_at DATETIME, UNIQUE(kind, name, version))`,
		`CREATE TABLE agent_sessions (id TEXT PRIMARY KEY, subject_id TEXT NOT NULL, agent_resource_id TEXT NOT NULL, status TEXT NOT NULL, state JSON, created_at DATETIME, updated_at DATETIME)`,
		`CREATE TABLE agent_runs (id TEXT PRIMARY KEY, session_id TEXT NOT NULL, agent_resource_id TEXT NOT NULL, parent_run_id TEXT, execution_adapter TEXT NOT NULL, status TEXT NOT NULL, trace_id TEXT, input JSON, started_at DATETIME, finished_at DATETIME, created_at DATETIME, updated_at DATETIME)`,
		`CREATE TABLE agent_approvals (id TEXT PRIMARY KEY, run_id TEXT NOT NULL, tool_resource_id TEXT NOT NULL, argument_digest TEXT NOT NULL, status TEXT NOT NULL, payload JSON, expires_at DATETIME NOT NULL, resolved_at DATETIME, created_at DATETIME, updated_at DATETIME)`,
		`CREATE TABLE agent_events (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT NOT NULL, sequence INTEGER NOT NULL, type TEXT NOT NULL, trace_id TEXT, parent_run_id TEXT, data JSON NOT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME, updated_at DATETIME, UNIQUE(run_id, sequence))`,
		`CREATE TABLE agent_idempotency_records (id TEXT PRIMARY KEY, run_id TEXT NOT NULL, tool_resource_id TEXT NOT NULL, idempotency_key TEXT NOT NULL, lease_id TEXT NOT NULL, argument_digest TEXT NOT NULL, status TEXT NOT NULL, output JSON, created_at DATETIME, updated_at DATETIME, UNIQUE(run_id, tool_resource_id, idempotency_key))`,
	}
	for _, statement := range statements {
		if _, err := db.Exec(statement); err != nil {
			t.Fatal(err)
		}
	}
}

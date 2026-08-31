package sqlite_test

import (
	"context"
	"database/sql"
	"encoding/json"
	"os"
	"testing"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
	grimlocksqlite "github.com/akive/grimoire/subsystems/grimlock/storage/sqlite"
	_ "modernc.org/sqlite"
)

func TestIlluminateManagedDatabaseIsUsableFromGo(t *testing.T) {
	path := os.Getenv("GRIMOIRE_MIGRATED_DB")
	if path == "" {
		t.Skip("GRIMOIRE_MIGRATED_DB is not set")
	}
	db, err := sql.Open("sqlite", path)
	if err != nil {
		t.Fatal(err)
	}
	defer db.Close()

	catalog := core.NewCatalog()
	tool := api.Tool{APIVersion: api.APIVersion, Kind: api.KindTool, Metadata: api.Metadata{Name: "shared.read", Version: "1"}, Spec: api.ToolSpec{Effect: api.EffectRead, InputSchema: json.RawMessage(`{"type":"object"}`), OutputSchema: json.RawMessage(`{"type":"object"}`), Executor: api.ExecutorBinding{Kind: "fixture"}}}
	agent := api.Agent{APIVersion: api.APIVersion, Kind: api.KindAgent, Metadata: api.Metadata{Name: "shared-agent", Version: "1"}, Spec: api.AgentSpec{Execution: api.ExecutionSpec{Adapter: "adk-go", ModelProfileRef: "test"}, Tools: []api.ResourceRef{{Kind: api.KindTool, Name: "shared.read", Version: "1"}}}}
	if err := catalog.RegisterTool(tool); err != nil {
		t.Fatal(err)
	}
	if err := catalog.RegisterAgent(agent); err != nil {
		t.Fatal(err)
	}
	store, err := grimlocksqlite.New(db)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.SyncCatalog(t.Context(), catalog); err != nil {
		t.Fatal(err)
	}
	service, err := core.NewServiceWithStore(catalog, core.ReadOnlyPolicy{}, executorFunc(func(context.Context, core.ExecutionLease) (json.RawMessage, error) {
		return json.RawMessage(`{"source":"go"}`), nil
	}), func(prefix string) string { return "01J" + prefix + "000000000000000000" }, store)
	if err != nil {
		t.Fatal(err)
	}
	session, err := service.CreateSession("php-and-go", api.ResourceRef{Kind: api.KindAgent, Name: "shared-agent", Version: "1"})
	if err != nil {
		t.Fatal(err)
	}
	run, err := service.StartRun(session.ID)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := service.RequestToolCall(t.Context(), core.ToolCallRequest{SessionID: session.ID, RunID: run.ID, ToolRef: agent.Spec.Tools[0], Arguments: json.RawMessage(`{}`)}); err != nil {
		t.Fatal(err)
	}
	var eventCount int
	if err := db.QueryRow(`SELECT COUNT(*) FROM events WHERE run_id = ?`, run.ID).Scan(&eventCount); err != nil {
		t.Fatal(err)
	}
	if eventCount != 2 {
		t.Fatalf("expected run.started and tool.completed in Illuminate's events table, got %d", eventCount)
	}
}

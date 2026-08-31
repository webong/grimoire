// Package sqlite persists Grimlock state in the schema managed by the
// Laravel adapter's Illuminate migration.
package sqlite

import (
	"context"
	"crypto/sha256"
	"database/sql"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

type Store struct {
	db *sql.DB
}

func New(db *sql.DB) (*Store, error) {
	if db == nil {
		return nil, errors.New("database is required")
	}
	return &Store{db: db}, nil
}

func (s *Store) SyncCatalog(ctx context.Context, catalog *core.Catalog) error {
	if catalog == nil {
		return errors.New("catalog is required")
	}
	now := time.Now().UTC()
	for _, resource := range catalog.Resources() {
		var existing string
		err := s.db.QueryRowContext(ctx,
			`SELECT id FROM resources WHERE kind = ? AND name = ? AND version = ?`,
			resource.Kind, resource.Metadata.Name, resource.Metadata.Version,
		).Scan(&existing)
		if err == nil {
			continue
		}
		if !errors.Is(err, sql.ErrNoRows) {
			return err
		}
		_, err = s.db.ExecContext(ctx,
			`INSERT INTO resources (id, kind, name, version, digest, spec, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
			resourceID(resource.Kind, resource.Metadata), resource.Kind, resource.Metadata.Name, resource.Metadata.Version,
			resource.Digest, string(resource.Spec), now, now,
		)
		if err != nil {
			return fmt.Errorf("insert resource %s: %w", resource.Metadata.Key(), err)
		}
	}
	return nil
}

func (s *Store) PutSession(ctx context.Context, session api.Session) error {
	agentID, err := s.lookupResourceID(ctx, session.AgentRef)
	if err != nil {
		return err
	}
	result, err := s.db.ExecContext(ctx,
		`UPDATE sessions SET subject_id = ?, agent_resource_id = ?, updated_at = ? WHERE id = ?`,
		session.SubjectID, agentID, session.CreatedAt, session.ID,
	)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected > 0 {
		return nil
	}
	_, err = s.db.ExecContext(ctx,
		`INSERT INTO sessions (id, subject_id, agent_resource_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)`,
		session.ID, session.SubjectID, agentID, "ready", session.CreatedAt, session.CreatedAt,
	)
	return err
}

func (s *Store) Session(ctx context.Context, id string) (api.Session, error) {
	var session api.Session
	var kind string
	err := s.db.QueryRowContext(ctx, `
		SELECT s.id, s.subject_id, r.kind, r.name, r.version, s.created_at
		FROM sessions s JOIN resources r ON r.id = s.agent_resource_id
		WHERE s.id = ?`, id,
	).Scan(&session.ID, &session.SubjectID, &kind, &session.AgentRef.Name, &session.AgentRef.Version, &session.CreatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return api.Session{}, fmt.Errorf("session %s: %w", id, core.ErrNotFound)
	}
	session.AgentRef.Kind = api.ResourceKind(kind)
	return session, err
}

func (s *Store) PutRun(ctx context.Context, run api.Run) error {
	var agentID string
	if err := s.db.QueryRowContext(ctx, `SELECT agent_resource_id FROM sessions WHERE id = ?`, run.SessionID).Scan(&agentID); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			return fmt.Errorf("session %s: %w", run.SessionID, core.ErrNotFound)
		}
		return err
	}
	result, err := s.db.ExecContext(ctx, `UPDATE runs SET status = ?, updated_at = ? WHERE id = ?`, run.Status, time.Now().UTC(), run.ID)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected > 0 {
		return nil
	}
	_, err = s.db.ExecContext(ctx, `
		INSERT INTO runs (id, session_id, agent_resource_id, execution_adapter, status, started_at, created_at, updated_at)
		SELECT ?, ?, ?, json_extract(spec, '$.execution.adapter'), ?, ?, ?, ? FROM resources WHERE id = ?`,
		run.ID, run.SessionID, agentID, run.Status, run.CreatedAt, run.CreatedAt, run.CreatedAt, agentID,
	)
	return err
}

func (s *Store) Run(ctx context.Context, id string) (api.Run, error) {
	var run api.Run
	err := s.db.QueryRowContext(ctx, `SELECT id, session_id, status, created_at FROM runs WHERE id = ?`, id).
		Scan(&run.ID, &run.SessionID, &run.Status, &run.CreatedAt)
	if errors.Is(err, sql.ErrNoRows) {
		return api.Run{}, fmt.Errorf("run %s: %w", id, core.ErrNotFound)
	}
	return run, err
}

func (s *Store) PutApproval(ctx context.Context, record core.ApprovalRecord) error {
	toolID, err := s.lookupResourceID(ctx, record.Approval.ToolRef)
	if err != nil {
		return err
	}
	payload, err := json.Marshal(record.Request)
	if err != nil {
		return err
	}
	var resolvedAt any
	if record.Approval.Status != api.ApprovalPending {
		resolvedAt = time.Now().UTC()
	}
	result, err := s.db.ExecContext(ctx, `
		UPDATE approvals SET status = ?, payload = ?, expires_at = ?, resolved_at = ?, updated_at = ? WHERE id = ?`,
		record.Approval.Status, string(payload), record.Approval.ExpiresAt, resolvedAt, time.Now().UTC(), record.Approval.ID,
	)
	if err != nil {
		return err
	}
	if affected, _ := result.RowsAffected(); affected > 0 {
		return nil
	}
	now := time.Now().UTC()
	_, err = s.db.ExecContext(ctx, `
		INSERT INTO approvals (id, run_id, tool_resource_id, argument_digest, status, payload, expires_at, resolved_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		record.Approval.ID, record.Approval.RunID, toolID, record.Approval.ArgumentDigest, record.Approval.Status,
		string(payload), record.Approval.ExpiresAt, resolvedAt, now, now,
	)
	return err
}

func (s *Store) Approval(ctx context.Context, id string) (core.ApprovalRecord, error) {
	var record core.ApprovalRecord
	var kind, payload string
	err := s.db.QueryRowContext(ctx, `
		SELECT a.id, a.run_id, r.kind, r.name, r.version, a.argument_digest, a.status, a.expires_at, a.payload
		FROM approvals a JOIN resources r ON r.id = a.tool_resource_id WHERE a.id = ?`, id,
	).Scan(
		&record.Approval.ID, &record.Approval.RunID, &kind, &record.Approval.ToolRef.Name, &record.Approval.ToolRef.Version,
		&record.Approval.ArgumentDigest, &record.Approval.Status, &record.Approval.ExpiresAt, &payload,
	)
	if errors.Is(err, sql.ErrNoRows) {
		return core.ApprovalRecord{}, fmt.Errorf("approval %s: %w", id, core.ErrNotFound)
	}
	if err != nil {
		return core.ApprovalRecord{}, err
	}
	record.Approval.ToolRef.Kind = api.ResourceKind(kind)
	if err := json.Unmarshal([]byte(payload), &record.Request); err != nil {
		return core.ApprovalRecord{}, fmt.Errorf("decode approval payload: %w", err)
	}
	return record, nil
}

func (s *Store) PutIdempotency(ctx context.Context, record core.IdempotencyRecord) error {
	toolID, err := s.lookupResourceID(ctx, record.ToolRef)
	if err != nil {
		return err
	}
	now := time.Now().UTC()
	_, err = s.db.ExecContext(ctx, `
		INSERT INTO idempotency_records (id, run_id, tool_resource_id, idempotency_key, lease_id, argument_digest, status, output, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		stableID("idempotency", record.RunID, record.ToolRef.Key(), record.Key), record.RunID, toolID, record.Key,
		record.LeaseID, record.ArgumentDigest, "completed", string(record.Output), now, now,
	)
	return err
}

func (s *Store) Idempotency(ctx context.Context, runID string, toolRef api.ResourceRef, key string) (core.IdempotencyRecord, error) {
	toolID, err := s.lookupResourceID(ctx, toolRef)
	if err != nil {
		return core.IdempotencyRecord{}, err
	}
	record := core.IdempotencyRecord{RunID: runID, ToolRef: toolRef, Key: key}
	var output string
	err = s.db.QueryRowContext(ctx, `
		SELECT lease_id, argument_digest, output FROM idempotency_records
		WHERE run_id = ? AND tool_resource_id = ? AND idempotency_key = ? AND status = 'completed'`,
		runID, toolID, key,
	).Scan(&record.LeaseID, &record.ArgumentDigest, &output)
	if errors.Is(err, sql.ErrNoRows) {
		return core.IdempotencyRecord{}, core.ErrNotFound
	}
	record.Output = json.RawMessage(output)
	return record, err
}

func (s *Store) AppendEvent(ctx context.Context, event api.Event) (api.Event, error) {
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return api.Event{}, err
	}
	defer func() { _ = tx.Rollback() }()
	if err := tx.QueryRowContext(ctx, `SELECT COALESCE(MAX(sequence), 0) + 1 FROM events WHERE run_id = ?`, event.RunID).Scan(&event.Sequence); err != nil {
		return api.Event{}, err
	}
	_, err = tx.ExecContext(ctx, `
		INSERT INTO events (run_id, sequence, type, trace_id, parent_run_id, data, occurred_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		event.RunID, event.Sequence, event.Type, nullString(event.TraceID), nullString(event.ParentRunID), string(event.Data),
		event.OccurredAt, event.OccurredAt, event.OccurredAt,
	)
	if err != nil {
		return api.Event{}, err
	}
	if err := tx.Commit(); err != nil {
		return api.Event{}, err
	}
	return event, nil
}

func (s *Store) Events(ctx context.Context, runID string, after uint64) ([]api.Event, error) {
	if _, err := s.Run(ctx, runID); err != nil {
		return nil, err
	}
	rows, err := s.db.QueryContext(ctx, `
		SELECT sequence, type, COALESCE(trace_id, ''), COALESCE(parent_run_id, ''), occurred_at, data
		FROM events WHERE run_id = ? AND sequence > ? ORDER BY sequence`, runID, after)
	if err != nil {
		return nil, err
	}
	defer rows.Close()
	var events []api.Event
	for rows.Next() {
		event := api.Event{APIVersion: api.APIVersion, RunID: runID}
		var data string
		if err := rows.Scan(&event.Sequence, &event.Type, &event.TraceID, &event.ParentRunID, &event.OccurredAt, &data); err != nil {
			return nil, err
		}
		event.Data = json.RawMessage(data)
		events = append(events, event)
	}
	return events, rows.Err()
}

func (s *Store) lookupResourceID(ctx context.Context, ref api.ResourceRef) (string, error) {
	var id string
	err := s.db.QueryRowContext(ctx, `SELECT id FROM resources WHERE kind = ? AND name = ? AND version = ?`, ref.Kind, ref.Name, ref.Version).Scan(&id)
	if errors.Is(err, sql.ErrNoRows) {
		return "", fmt.Errorf("resource %s: %w", ref.Key(), core.ErrNotFound)
	}
	return id, err
}

func resourceID(kind api.ResourceKind, metadata api.Metadata) string {
	return stableID(string(kind), metadata.Name, metadata.Version)
}

func stableID(parts ...string) string {
	sum := sha256.Sum256([]byte(strings.Join(parts, "\x00")))
	return strings.ToUpper(hex.EncodeToString(sum[:13]))
}

func nullString(value string) any {
	if value == "" {
		return nil
	}
	return value
}

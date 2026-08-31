package core

import (
	"context"
	"encoding/json"
	"fmt"
	"sync"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
)

type ApprovalRecord struct {
	Approval api.Approval
	Request  ToolCallRequest
}

type IdempotencyRecord struct {
	RunID          string
	ToolRef        api.ResourceRef
	Key            string
	LeaseID        string
	ArgumentDigest string
	Output         json.RawMessage
}

// StateStore is the durable boundary shared by the Go service and Laravel's
// Illuminate-managed schema.
type StateStore interface {
	PutSession(context.Context, api.Session) error
	Session(context.Context, string) (api.Session, error)
	PutRun(context.Context, api.Run) error
	Run(context.Context, string) (api.Run, error)
	PutApproval(context.Context, ApprovalRecord) error
	Approval(context.Context, string) (ApprovalRecord, error)
	PutIdempotency(context.Context, IdempotencyRecord) error
	Idempotency(context.Context, string, api.ResourceRef, string) (IdempotencyRecord, error)
	AppendEvent(context.Context, api.Event) (api.Event, error)
	Events(context.Context, string, uint64) ([]api.Event, error)
}

type MemoryStateStore struct {
	mu          sync.RWMutex
	sessions    map[string]api.Session
	runs        map[string]api.Run
	approvals   map[string]ApprovalRecord
	idempotency map[string]IdempotencyRecord
	events      map[string][]api.Event
}

func NewMemoryStateStore() *MemoryStateStore {
	return &MemoryStateStore{
		sessions:    map[string]api.Session{},
		runs:        map[string]api.Run{},
		approvals:   map[string]ApprovalRecord{},
		idempotency: map[string]IdempotencyRecord{},
		events:      map[string][]api.Event{},
	}
}

func (s *MemoryStateStore) PutSession(_ context.Context, session api.Session) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sessions[session.ID] = session
	return nil
}

func (s *MemoryStateStore) Session(_ context.Context, id string) (api.Session, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	session, ok := s.sessions[id]
	if !ok {
		return api.Session{}, fmt.Errorf("session %s: %w", id, ErrNotFound)
	}
	return session, nil
}

func (s *MemoryStateStore) PutRun(_ context.Context, run api.Run) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.runs[run.ID] = run
	return nil
}

func (s *MemoryStateStore) Run(_ context.Context, id string) (api.Run, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	run, ok := s.runs[id]
	if !ok {
		return api.Run{}, fmt.Errorf("run %s: %w", id, ErrNotFound)
	}
	return run, nil
}

func (s *MemoryStateStore) PutApproval(_ context.Context, record ApprovalRecord) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	record.Request = cloneRequest(record.Request)
	s.approvals[record.Approval.ID] = record
	return nil
}

func (s *MemoryStateStore) Approval(_ context.Context, id string) (ApprovalRecord, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	record, ok := s.approvals[id]
	if !ok {
		return ApprovalRecord{}, fmt.Errorf("approval %s: %w", id, ErrNotFound)
	}
	record.Request = cloneRequest(record.Request)
	return record, nil
}

func (s *MemoryStateStore) PutIdempotency(_ context.Context, record IdempotencyRecord) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	record.Output = append(json.RawMessage(nil), record.Output...)
	s.idempotency[idempotencyStoreKey(record.RunID, record.ToolRef, record.Key)] = record
	return nil
}

func (s *MemoryStateStore) Idempotency(_ context.Context, runID string, toolRef api.ResourceRef, key string) (IdempotencyRecord, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	record, ok := s.idempotency[idempotencyStoreKey(runID, toolRef, key)]
	if !ok {
		return IdempotencyRecord{}, ErrNotFound
	}
	record.Output = append(json.RawMessage(nil), record.Output...)
	return record, nil
}

func (s *MemoryStateStore) AppendEvent(_ context.Context, event api.Event) (api.Event, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	events := s.events[event.RunID]
	event.Sequence = uint64(len(events) + 1)
	event.Data = append(json.RawMessage(nil), event.Data...)
	s.events[event.RunID] = append(events, event)
	return event, nil
}

func (s *MemoryStateStore) Events(_ context.Context, runID string, after uint64) ([]api.Event, error) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	if _, ok := s.runs[runID]; !ok {
		return nil, fmt.Errorf("run %s: %w", runID, ErrNotFound)
	}
	values := s.events[runID]
	result := make([]api.Event, 0, len(values))
	for _, event := range values {
		if event.Sequence > after {
			event.Data = append(json.RawMessage(nil), event.Data...)
			result = append(result, event)
		}
	}
	return result, nil
}

func idempotencyStoreKey(runID string, toolRef api.ResourceRef, key string) string {
	return runID + ":" + toolRef.Key() + ":" + key
}

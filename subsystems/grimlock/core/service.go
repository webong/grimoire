package core

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"sync"
	"time"

	api "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
)

var (
	ErrApprovalRequired      = errors.New("grimlock approval is required")
	ErrApprovalRejected      = errors.New("grimlock approval was rejected")
	ErrApprovalExpired       = errors.New("grimlock approval expired")
	ErrIdempotencyKey        = errors.New("grimlock caller idempotency key is required")
	ErrIdempotencyConflict   = errors.New("grimlock idempotency key was reused with different arguments")
	ErrIdempotencyInProgress = errors.New("grimlock idempotency key is already in progress")
	ErrIdempotencyFailed     = errors.New("grimlock idempotency key has a terminal failed execution")
)

type IDGenerator func(string) string

type ToolExecutor interface {
	Execute(context.Context, ExecutionLease) (json.RawMessage, error)
}

type ExecutionLease struct {
	ID             string
	Session        api.Session
	Run            api.Run
	Tool           api.Tool
	Arguments      json.RawMessage
	IdempotencyKey string
}

type ToolCallRequest struct {
	SessionID      string          `json:"sessionId"`
	RunID          string          `json:"runId"`
	ToolRef        api.ResourceRef `json:"toolRef"`
	Arguments      json.RawMessage `json:"arguments"`
	IdempotencyKey string          `json:"idempotencyKey,omitempty"`
}

type ToolCallResult struct {
	Lease    ExecutionLease
	Approval *api.Approval
	Output   json.RawMessage
	Replayed bool
}

// Service implements the engine-neutral action boundary. SDK adapters resolve
// manifests into native tools, then submit side effects through this service.
type Service struct {
	catalog     *Catalog
	policy      Policy
	executor    ToolExecutor
	store       StateStore
	now         func() time.Time
	newID       IDGenerator
	approvalTTL time.Duration

	mu sync.Mutex
}

func NewService(catalog *Catalog, policy Policy, executor ToolExecutor, ids IDGenerator) (*Service, error) {
	return NewServiceWithStore(catalog, policy, executor, ids, NewMemoryStateStore())
}

func NewServiceWithStore(catalog *Catalog, policy Policy, executor ToolExecutor, ids IDGenerator, store StateStore) (*Service, error) {
	if catalog == nil || policy == nil || executor == nil || ids == nil || store == nil {
		return nil, errors.New("catalog, policy, executor, id generator, and state store are required")
	}
	return &Service{
		catalog: catalog, policy: policy, executor: executor, newID: ids, store: store,
		now: time.Now, approvalTTL: 10 * time.Minute,
	}, nil
}

func (s *Service) CreateSession(subjectID string, agentRef api.ResourceRef) (api.Session, error) {
	return s.CreateSessionContext(context.Background(), subjectID, agentRef)
}

func (s *Service) CreateSessionContext(ctx context.Context, subjectID string, agentRef api.ResourceRef) (api.Session, error) {
	if subjectID == "" {
		return api.Session{}, errors.New("subjectID is required")
	}
	if _, err := s.catalog.Agent(agentRef); err != nil {
		return api.Session{}, err
	}
	session := api.Session{ID: s.newID("session"), SubjectID: subjectID, AgentRef: agentRef, CreatedAt: s.now().UTC()}
	if err := s.store.PutSession(ctx, session); err != nil {
		return api.Session{}, err
	}
	return session, nil
}

func (s *Service) StartRun(sessionID string) (api.Run, error) {
	return s.StartRunContext(context.Background(), sessionID)
}

func (s *Service) StartRunContext(ctx context.Context, sessionID string) (api.Run, error) {
	if _, err := s.store.Session(ctx, sessionID); err != nil {
		return api.Run{}, err
	}
	run := api.Run{ID: s.newID("run"), SessionID: sessionID, Status: api.RunStatusRunning, CreatedAt: s.now().UTC()}
	if err := s.store.PutRun(ctx, run); err != nil {
		return api.Run{}, err
	}
	if err := s.appendEvent(ctx, run.ID, "run.started", map[string]string{"sessionId": sessionID}); err != nil {
		return api.Run{}, err
	}
	return run, nil
}

func (s *Service) AdvertisedTools(ctx context.Context, sessionID, runID string) ([]api.Tool, error) {
	session, run, agent, err := s.resolveRun(ctx, sessionID, runID)
	if err != nil {
		return nil, err
	}
	tools := make([]api.Tool, 0, len(agent.Spec.Tools))
	for _, ref := range agent.Spec.Tools {
		tool, err := s.catalog.Tool(ref)
		if err != nil {
			return nil, err
		}
		if s.policy.Advertise(ctx, ToolCall{Session: session, Run: run, Tool: tool}) {
			tools = append(tools, tool)
		}
	}
	return tools, nil
}

// RequestToolCall creates an approval for side effects, or executes a read
// operation. It accepts only a tool that is still admitted to the model.
func (s *Service) RequestToolCall(ctx context.Context, request ToolCallRequest) (ToolCallResult, error) {
	if !isJSONObject(request.Arguments) {
		return ToolCallResult{}, errors.New("tool arguments must be a JSON object")
	}
	session, run, _, err := s.resolveRun(ctx, request.SessionID, request.RunID)
	if err != nil {
		return ToolCallResult{}, err
	}
	tool, err := s.catalog.Tool(request.ToolRef)
	if err != nil {
		return ToolCallResult{}, err
	}
	if !s.isAgentTool(session.AgentRef, request.ToolRef) {
		return ToolCallResult{}, ErrDenied
	}
	if !s.policy.Advertise(ctx, ToolCall{Session: session, Run: run, Tool: tool}) {
		return ToolCallResult{}, ErrDenied
	}
	if tool.Spec.Idempotency == api.IdempotencyCallerKeyRequired && request.IdempotencyKey == "" {
		return ToolCallResult{}, ErrIdempotencyKey
	}
	if tool.RequiresApproval() {
		approval := api.Approval{
			ID: s.newID("approval"), RunID: run.ID, ToolRef: request.ToolRef, ArgumentDigest: digest(request.Arguments),
			Status: api.ApprovalPending, ExpiresAt: s.now().Add(s.approvalTTL).UTC(),
		}
		run.Status = api.RunStatusWaitingApproval
		if err := s.store.PutApproval(ctx, ApprovalRecord{Approval: approval, Request: request}); err != nil {
			return ToolCallResult{}, err
		}
		if err := s.store.PutRun(ctx, run); err != nil {
			return ToolCallResult{}, err
		}
		if err := s.appendEvent(ctx, run.ID, "tool.approval.requested", approval); err != nil {
			return ToolCallResult{}, err
		}
		return ToolCallResult{Approval: &approval}, ErrApprovalRequired
	}
	return s.execute(ctx, session, run, tool, request)
}

func (s *Service) ResolveApproval(ctx context.Context, approvalID string, approved bool) (ToolCallResult, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	record, err := s.store.Approval(ctx, approvalID)
	if err != nil {
		return ToolCallResult{}, err
	}
	approval := record.Approval
	request := record.Request
	if approval.Status != api.ApprovalPending {
		if approval.Status == api.ApprovalRejected {
			return ToolCallResult{Approval: &approval}, ErrApprovalRejected
		}
		return ToolCallResult{Approval: &approval}, ErrApprovalExpired
	}
	if s.now().After(approval.ExpiresAt) {
		approval.Status = api.ApprovalExpired
		record.Approval = approval
		if err := s.store.PutApproval(ctx, record); err != nil {
			return ToolCallResult{}, err
		}
		return ToolCallResult{Approval: &approval}, ErrApprovalExpired
	}
	if !approved {
		approval.Status = api.ApprovalRejected
		record.Approval = approval
		if err := s.store.PutApproval(ctx, record); err != nil {
			return ToolCallResult{}, err
		}
		return ToolCallResult{Approval: &approval}, ErrApprovalRejected
	}
	approval.Status = api.ApprovalApproved
	record.Approval = approval
	if err := s.store.PutApproval(ctx, record); err != nil {
		return ToolCallResult{}, err
	}
	session, err := s.store.Session(ctx, request.SessionID)
	if err != nil {
		return ToolCallResult{}, err
	}
	run, err := s.store.Run(ctx, request.RunID)
	if err != nil {
		return ToolCallResult{}, err
	}
	run.Status = api.RunStatusRunning
	if err := s.store.PutRun(ctx, run); err != nil {
		return ToolCallResult{}, err
	}
	if err := s.appendEvent(ctx, run.ID, "tool.approval.approved", approval); err != nil {
		return ToolCallResult{}, err
	}

	tool, err := s.catalog.Tool(request.ToolRef)
	if err != nil {
		return ToolCallResult{Approval: &approval}, err
	}
	result, err := s.execute(ctx, session, run, tool, request)
	result.Approval = &approval
	return result, err
}

func (s *Service) Events(runID string) ([]api.Event, error) {
	return s.EventsAfter(context.Background(), runID, 0)

}

func (s *Service) EventsAfter(ctx context.Context, runID string, after uint64) ([]api.Event, error) {
	return s.store.Events(ctx, runID, after)
}

func (s *Service) execute(ctx context.Context, session api.Session, run api.Run, tool api.Tool, request ToolCallRequest) (ToolCallResult, error) {
	call := ToolCall{Session: session, Run: run, Tool: tool, Args: request.Arguments}
	if err := s.policy.Authorize(ctx, call); err != nil {
		return ToolCallResult{}, err
	}
	lease := ExecutionLease{ID: s.newID("lease"), Session: session, Run: run, Tool: tool, Arguments: append(json.RawMessage(nil), request.Arguments...), IdempotencyKey: request.IdempotencyKey}
	var idempotency *IdempotencyRecord
	if request.IdempotencyKey != "" {
		record, claimed, err := s.store.ClaimIdempotency(ctx, IdempotencyRecord{
			RunID: run.ID, ToolRef: request.ToolRef, Key: request.IdempotencyKey,
			LeaseID: lease.ID, ArgumentDigest: digest(request.Arguments),
		})
		if err != nil {
			return ToolCallResult{}, err
		}
		if record.ArgumentDigest != digest(request.Arguments) {
			return ToolCallResult{Lease: ExecutionLease{ID: record.LeaseID}}, ErrIdempotencyConflict
		}
		if !claimed {
			return idempotencyResult(record)
		}
		idempotency = &record
	}
	output, err := s.executor.Execute(ctx, lease)
	if err != nil {
		if idempotency != nil {
			if markErr := s.store.FailIdempotency(ctx, *idempotency); markErr != nil {
				return ToolCallResult{Lease: lease}, markErr
			}
		}
		_ = s.appendEvent(ctx, run.ID, "tool.failed", map[string]string{"tool": tool.Metadata.Key()})
		return ToolCallResult{Lease: lease}, err
	}
	if !isJSONObject(output) {
		if idempotency != nil {
			if markErr := s.store.FailIdempotency(ctx, *idempotency); markErr != nil {
				return ToolCallResult{Lease: lease}, markErr
			}
		}
		return ToolCallResult{Lease: lease}, errors.New("tool executor output must be a JSON object")
	}
	if idempotency != nil {
		idempotency.Output = output
		if err := s.store.CompleteIdempotency(ctx, *idempotency); err != nil {
			return ToolCallResult{Lease: lease}, err
		}
	}
	if err := s.appendEvent(ctx, run.ID, "tool.completed", map[string]string{"tool": tool.Metadata.Key(), "leaseId": lease.ID}); err != nil {
		return ToolCallResult{Lease: lease}, err
	}
	return ToolCallResult{Lease: lease, Output: output}, nil
}

func (s *Service) resolveRun(ctx context.Context, sessionID, runID string) (api.Session, api.Run, api.Agent, error) {
	session, err := s.store.Session(ctx, sessionID)
	if err != nil {
		return api.Session{}, api.Run{}, api.Agent{}, err
	}
	run, err := s.store.Run(ctx, runID)
	if err != nil || run.SessionID != sessionID {
		return api.Session{}, api.Run{}, api.Agent{}, ErrNotFound
	}
	agent, err := s.catalog.Agent(session.AgentRef)
	return session, run, agent, err
}

func idempotencyResult(record IdempotencyRecord) (ToolCallResult, error) {
	result := ToolCallResult{Lease: ExecutionLease{ID: record.LeaseID}}
	switch record.Status {
	case IdempotencyCompleted:
		result.Output = record.Output
		result.Replayed = true
		return result, nil
	case IdempotencyPending:
		return result, ErrIdempotencyInProgress
	case IdempotencyFailed:
		return result, ErrIdempotencyFailed
	default:
		return result, errors.New("invalid grimlock idempotency record status")
	}
}

func (s *Service) isAgentTool(agentRef, toolRef api.ResourceRef) bool {
	agent, err := s.catalog.Agent(agentRef)
	if err != nil {
		return false
	}
	for _, ref := range agent.Spec.Tools {
		if ref.Key() == toolRef.Key() {
			return true
		}
	}
	return false
}

func (s *Service) appendEvent(ctx context.Context, runID, kind string, data any) error {
	raw, err := json.Marshal(data)
	if err != nil {
		return err
	}
	_, err = s.store.AppendEvent(ctx, api.Event{APIVersion: api.APIVersion, RunID: runID, Type: kind, OccurredAt: s.now().UTC(), Data: raw})
	return err
}

func cloneRequest(value ToolCallRequest) ToolCallRequest {
	value.Arguments = append(json.RawMessage(nil), value.Arguments...)
	return value
}

func digest(value []byte) string {
	sum := sha256.Sum256(value)
	return hex.EncodeToString(sum[:])
}

func isJSONObject(raw json.RawMessage) bool {
	var value map[string]any
	return json.Unmarshal(raw, &value) == nil && value != nil
}

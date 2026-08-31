package core

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sync"
)

type ExecutorRegistry struct {
	mu        sync.RWMutex
	executors map[string]ToolExecutor
}

func NewExecutorRegistry() *ExecutorRegistry {
	return &ExecutorRegistry{executors: map[string]ToolExecutor{}}
}

func (r *ExecutorRegistry) Register(kind string, executor ToolExecutor) error {
	if kind == "" || executor == nil {
		return errors.New("executor kind and implementation are required")
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if _, exists := r.executors[kind]; exists {
		return fmt.Errorf("executor %q is already registered", kind)
	}
	r.executors[kind] = executor
	return nil
}

func (r *ExecutorRegistry) Execute(ctx context.Context, lease ExecutionLease) (json.RawMessage, error) {
	r.mu.RLock()
	executor, ok := r.executors[lease.Tool.Spec.Executor.Kind]
	r.mu.RUnlock()
	if !ok {
		return nil, fmt.Errorf("executor %q: %w", lease.Tool.Spec.Executor.Kind, ErrNotFound)
	}
	return executor.Execute(ctx, lease)
}

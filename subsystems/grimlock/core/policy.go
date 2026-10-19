package core

import (
	"context"
	"errors"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
)

var ErrDenied = errors.New("grimlock policy denied tool call")

// ToolCall is the policy context. Arguments are empty during advertisement and
// present only immediately before a call is dispatched.
type ToolCall struct {
	Session api.Session
	Run     api.Run
	Tool    api.Tool
	Args    []byte
}

// Policy is deliberately invoked at both control points. Advertise governs
// model visibility; Authorize governs the actual, validated operation.
type Policy interface {
	Advertise(context.Context, ToolCall) bool
	Authorize(context.Context, ToolCall) error
}

type PolicyFuncs struct {
	AdvertiseFunc func(context.Context, ToolCall) bool
	AuthorizeFunc func(context.Context, ToolCall) error
}

func (p PolicyFuncs) Advertise(ctx context.Context, call ToolCall) bool {
	if p.AdvertiseFunc != nil {
		return p.AdvertiseFunc(ctx, call)
	}
	return call.Tool.Spec.Effect == api.EffectRead
}

func (p PolicyFuncs) Authorize(ctx context.Context, call ToolCall) error {
	if p.AuthorizeFunc != nil {
		return p.AuthorizeFunc(ctx, call)
	}
	if call.Tool.Spec.Effect == api.EffectRead {
		return nil
	}
	return ErrDenied
}

type ReadOnlyPolicy struct{}

func (ReadOnlyPolicy) Advertise(_ context.Context, call ToolCall) bool {
	return call.Tool.Spec.Effect == api.EffectRead
}

func (ReadOnlyPolicy) Authorize(_ context.Context, call ToolCall) error {
	if call.Tool.Spec.Effect == api.EffectRead {
		return nil
	}
	return ErrDenied
}

// Package v1alpha1 defines Grimrock's engine-neutral execution boundary.
package v1alpha1

import (
	"context"
	"encoding/json"
	"errors"
	"iter"
	"strings"
	"time"

	grimlockapi "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
)

const APIVersion = "grimrock/v1alpha1"

type Request struct {
	RunID     string                  `json:"runId"`
	SessionID string                  `json:"sessionId"`
	SubjectID string                  `json:"subjectId"`
	AgentRef  grimlockapi.ResourceRef `json:"agentRef"`
	Engine    string                  `json:"engine"`
	Input     json.RawMessage         `json:"input"`
}

func (r Request) Validate() error {
	if strings.TrimSpace(r.RunID) == "" || strings.TrimSpace(r.SessionID) == "" {
		return errors.New("runId and sessionId are required")
	}
	if strings.TrimSpace(r.SubjectID) == "" || strings.TrimSpace(r.Engine) == "" {
		return errors.New("subjectId and engine are required")
	}
	if err := r.AgentRef.Validate(grimlockapi.KindAgent); err != nil {
		return err
	}
	if len(r.Input) == 0 || !json.Valid(r.Input) {
		return errors.New("input must be valid JSON")
	}
	return nil
}

type Event struct {
	APIVersion string          `json:"apiVersion"`
	RunID      string          `json:"runId"`
	Type       string          `json:"type"`
	OccurredAt time.Time       `json:"occurredAt"`
	Data       json.RawMessage `json:"data"`
}

type Engine interface {
	Name() string
	Run(context.Context, Request) iter.Seq2[Event, error]
	Cancel(context.Context, string) error
}

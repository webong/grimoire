// Package httpapi exposes Grimlock's control-plane application service over
// a small historical runtime HTTP API. Consumer communication extensions live outside it.
package httpapi

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/webong/grimoire/subsystems/grimlock/core"
)

type Server struct {
	service *core.Service
	handler http.Handler
}

func New(service *core.Service) (*Server, error) {
	if service == nil {
		return nil, errors.New("service is required")
	}
	server := &Server{service: service}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", server.health)
	mux.HandleFunc("POST /v1/sessions", server.createSession)
	mux.HandleFunc("POST /v1/sessions/{sessionID}/runs", server.startRun)
	mux.HandleFunc("GET /v1/sessions/{sessionID}/runs/{runID}/tools", server.advertisedTools)
	mux.HandleFunc("POST /v1/sessions/{sessionID}/runs/{runID}/tool-calls", server.requestToolCall)
	mux.HandleFunc("POST /v1/approvals/{approvalID}/resolve", server.resolveApproval)
	mux.HandleFunc("GET /v1/runs/{runID}/events", server.events)
	server.handler = mux
	return server, nil
}

func (s *Server) ServeHTTP(response http.ResponseWriter, request *http.Request) {
	s.handler.ServeHTTP(response, request)
}

func (s *Server) health(response http.ResponseWriter, _ *http.Request) {
	writeJSON(response, http.StatusOK, map[string]string{"status": "ok"})
}

func (s *Server) createSession(response http.ResponseWriter, request *http.Request) {
	var input struct {
		SubjectID string          `json:"subjectId"`
		AgentRef  api.ResourceRef `json:"agentRef"`
	}
	if !decodeJSON(response, request, &input) {
		return
	}
	session, err := s.service.CreateSessionContext(request.Context(), input.SubjectID, input.AgentRef)
	if err != nil {
		writeError(response, err)
		return
	}
	writeJSON(response, http.StatusCreated, session)
}

func (s *Server) startRun(response http.ResponseWriter, request *http.Request) {
	run, err := s.service.StartRunContext(request.Context(), request.PathValue("sessionID"))
	if err != nil {
		writeError(response, err)
		return
	}
	writeJSON(response, http.StatusCreated, run)
}

func (s *Server) advertisedTools(response http.ResponseWriter, request *http.Request) {
	tools, err := s.service.AdvertisedTools(request.Context(), request.PathValue("sessionID"), request.PathValue("runID"))
	if err != nil {
		writeError(response, err)
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"tools": tools})
}

func (s *Server) requestToolCall(response http.ResponseWriter, request *http.Request) {
	var input struct {
		ToolRef        api.ResourceRef `json:"toolRef"`
		Arguments      json.RawMessage `json:"arguments"`
		IdempotencyKey string          `json:"idempotencyKey"`
	}
	if !decodeJSON(response, request, &input) {
		return
	}
	result, err := s.service.RequestToolCall(request.Context(), core.ToolCallRequest{
		SessionID: request.PathValue("sessionID"), RunID: request.PathValue("runID"), ToolRef: input.ToolRef,
		Arguments: input.Arguments, IdempotencyKey: input.IdempotencyKey,
	})
	if errors.Is(err, core.ErrApprovalRequired) {
		writeJSON(response, http.StatusAccepted, result)
		return
	}
	if err != nil {
		writeError(response, err)
		return
	}
	writeJSON(response, http.StatusOK, result)
}

func (s *Server) resolveApproval(response http.ResponseWriter, request *http.Request) {
	var input struct {
		Approved bool `json:"approved"`
	}
	if !decodeJSON(response, request, &input) {
		return
	}
	result, err := s.service.ResolveApproval(request.Context(), request.PathValue("approvalID"), input.Approved)
	if err != nil {
		writeError(response, err)
		return
	}
	writeJSON(response, http.StatusOK, result)
}

func (s *Server) events(response http.ResponseWriter, request *http.Request) {
	after, err := strconv.ParseUint(request.URL.Query().Get("after"), 10, 64)
	if request.URL.Query().Get("after") == "" {
		after = 0
		err = nil
	}
	if err != nil {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": "after must be an unsigned integer"})
		return
	}
	events, err := s.service.EventsAfter(request.Context(), request.PathValue("runID"), after)
	if err != nil {
		writeError(response, err)
		return
	}
	if strings.Contains(request.Header.Get("Accept"), "text/event-stream") {
		response.Header().Set("Content-Type", "text/event-stream")
		response.WriteHeader(http.StatusOK)
		for _, event := range events {
			data, _ := json.Marshal(event)
			_, _ = fmt.Fprintf(response, "id: %d\nevent: %s\ndata: %s\n\n", event.Sequence, event.Type, data)
		}
		return
	}
	writeJSON(response, http.StatusOK, map[string]any{"events": events})
}

func decodeJSON(response http.ResponseWriter, request *http.Request, destination any) bool {
	decoder := json.NewDecoder(http.MaxBytesReader(response, request.Body, 1<<20))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(destination); err != nil {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return false
	}
	return true
}

func writeError(response http.ResponseWriter, err error) {
	status := http.StatusUnprocessableEntity
	if errors.Is(err, core.ErrNotFound) {
		status = http.StatusNotFound
	} else if errors.Is(err, core.ErrDenied) {
		status = http.StatusForbidden
	} else if errors.Is(err, core.ErrApprovalRejected) || errors.Is(err, core.ErrApprovalExpired) ||
		errors.Is(err, core.ErrIdempotencyConflict) || errors.Is(err, core.ErrIdempotencyInProgress) || errors.Is(err, core.ErrIdempotencyFailed) {
		status = http.StatusConflict
	}
	writeJSON(response, status, map[string]string{"error": err.Error()})
}

func writeJSON(response http.ResponseWriter, status int, value any) {
	response.Header().Set("Content-Type", "application/json")
	response.WriteHeader(status)
	_ = json.NewEncoder(response).Encode(value)
}

// Package registryhttp exposes Grimoire's registry-only HTTP contract.
package registryhttp

import (
	"encoding/json"
	"errors"
	"net/http"
	"strings"

	api "github.com/webong/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/webong/grimoire/subsystems/grimlock/core"
)

type Server struct {
	catalog *core.Catalog
	handler http.Handler
}

func New(catalog *core.Catalog) (*Server, error) {
	if catalog == nil {
		return nil, errors.New("catalog is required")
	}
	server := &Server{catalog: catalog}
	mux := http.NewServeMux()
	mux.HandleFunc("GET /v1/registry/agents", server.listAgents)
	mux.HandleFunc("GET /v1/registry/tools", server.listTools)
	mux.HandleFunc("GET /v1/registry/skills", server.listSkills)
	mux.HandleFunc("GET /v1/registry/agents/{name}/{version}/resolved", server.resolveAgentDefinition)
	mux.HandleFunc("POST /v1/registry/{kind}/{name}/{version}/publish", server.publishResource)
	mux.HandleFunc("POST /v1/registry/{kind}/{name}/{version}/retire", server.retireResource)
	server.handler = mux
	return server, nil
}

func (s *Server) ServeHTTP(response http.ResponseWriter, request *http.Request) {
	s.handler.ServeHTTP(response, request)
}

func (s *Server) listAgents(response http.ResponseWriter, _ *http.Request) {
	agents := s.catalog.ListPublishedAgents()
	writeJSON(response, http.StatusOK, map[string]any{"agents": agents})
}

func (s *Server) listTools(response http.ResponseWriter, _ *http.Request) {
	tools := s.catalog.ListPublishedTools()
	writeJSON(response, http.StatusOK, map[string]any{"tools": tools})
}

func (s *Server) listSkills(response http.ResponseWriter, _ *http.Request) {
	skills := s.catalog.ListPublishedSkills()
	writeJSON(response, http.StatusOK, map[string]any{"skills": skills})
}

func normalizeKind(raw string) (api.ResourceKind, bool) {
	switch strings.ToLower(raw) {
	case "agent", "agents":
		return api.KindAgent, true
	case "tool", "tools":
		return api.KindTool, true
	case "skill", "skills":
		return api.KindSkill, true
	case "client", "clients":
		return api.KindClient, true
	case "device", "devices":
		return api.KindDevice, true
	case "connector", "connectors":
		return api.KindConnector, true
	default:
		// Also accept canonical case-sensitive values.
		switch api.ResourceKind(raw) {
		case api.KindAgent, api.KindTool, api.KindSkill, api.KindClient, api.KindDevice, api.KindConnector:
			return api.ResourceKind(raw), true
		default:
			return "", false
		}
	}
}

func (s *Server) publishResource(response http.ResponseWriter, request *http.Request) {
	kind, ok := normalizeKind(request.PathValue("kind"))
	if !ok {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": "unknown resource kind"})
		return
	}
	ref := api.ResourceRef{
		Kind:    kind,
		Name:    request.PathValue("name"),
		Version: request.PathValue("version"),
	}
	if ref.Name == "" || ref.Version == "" {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": "name and version are required"})
		return
	}
	if err := s.catalog.Publish(ref); err != nil {
		if errors.Is(err, core.ErrNotFound) {
			writeJSON(response, http.StatusNotFound, map[string]string{"error": err.Error()})
			return
		}
		writeJSON(response, http.StatusConflict, map[string]string{"error": err.Error()})
		return
	}
	status, _ := s.catalog.PublicationStatus(ref)
	writeJSON(response, http.StatusOK, map[string]string{"status": string(status)})
}

func (s *Server) retireResource(response http.ResponseWriter, request *http.Request) {
	kind, ok := normalizeKind(request.PathValue("kind"))
	if !ok {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": "unknown resource kind"})
		return
	}
	ref := api.ResourceRef{
		Kind:    kind,
		Name:    request.PathValue("name"),
		Version: request.PathValue("version"),
	}
	if ref.Name == "" || ref.Version == "" {
		writeJSON(response, http.StatusBadRequest, map[string]string{"error": "name and version are required"})
		return
	}
	if err := s.catalog.Retire(ref); err != nil {
		if errors.Is(err, core.ErrNotFound) {
			writeJSON(response, http.StatusNotFound, map[string]string{"error": err.Error()})
			return
		}
		writeJSON(response, http.StatusConflict, map[string]string{"error": err.Error()})
		return
	}
	status, _ := s.catalog.PublicationStatus(ref)
	writeJSON(response, http.StatusOK, map[string]string{"status": string(status)})
}

func (s *Server) resolveAgentDefinition(response http.ResponseWriter, request *http.Request) {
	definition, err := s.catalog.ResolveAgentDefinition(api.ResourceRef{
		Kind: api.KindAgent, Name: request.PathValue("name"), Version: request.PathValue("version"),
	})
	if err != nil {
		if errors.Is(err, core.ErrNotFound) {
			writeJSON(response, http.StatusNotFound, map[string]string{"error": err.Error()})
			return
		}
		writeJSON(response, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(response, http.StatusOK, definition)
}

func writeJSON(response http.ResponseWriter, status int, value any) {
	response.Header().Set("Content-Type", "application/json")
	response.WriteHeader(status)
	_ = json.NewEncoder(response).Encode(value)
}

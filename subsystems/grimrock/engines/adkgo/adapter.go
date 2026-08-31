// Package adkgo adapts admitted Grimlock agents and tools to Google ADK-Go.
package adkgo

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"github.com/google/jsonschema-go/jsonschema"
	"google.golang.org/adk/v2/agent"
	"google.golang.org/adk/v2/agent/llmagent"
	"google.golang.org/adk/v2/model"
	"google.golang.org/adk/v2/tool"
	"google.golang.org/adk/v2/tool/functiontool"

	grimlockapi "github.com/akive/grimoire/subsystems/grimlock/api/v1alpha1"
	"github.com/akive/grimoire/subsystems/grimlock/core"
)

var invalidName = regexp.MustCompile(`[^A-Za-z0-9_]`)

type Adapter struct {
	catalog *core.Catalog
	service *core.Service
}

func NewAdapter(catalog *core.Catalog, service *core.Service) (*Adapter, error) {
	if catalog == nil || service == nil {
		return nil, errors.New("catalog and service are required")
	}
	return &Adapter{catalog: catalog, service: service}, nil
}

func (a *Adapter) BuildAgent(ctx context.Context, sessionID, runID string, ref grimlockapi.ResourceRef, llm model.LLM) (agent.Agent, error) {
	manifest, err := a.catalog.Agent(ref)
	if err != nil {
		return nil, err
	}
	tools, err := a.BuildTools(ctx, sessionID, runID)
	if err != nil {
		return nil, err
	}
	return llmagent.New(llmagent.Config{
		Name:        safeName(manifest.Metadata.Name),
		Description: "Grimlock-managed agent " + manifest.Metadata.Key(),
		Instruction: manifest.Spec.Instruction,
		Model:       llm,
		Tools:       tools,
	})
}

func (a *Adapter) BuildTools(ctx context.Context, sessionID, runID string) ([]tool.Tool, error) {
	descriptors, err := a.service.AdvertisedTools(ctx, sessionID, runID)
	if err != nil {
		return nil, err
	}
	result := make([]tool.Tool, 0, len(descriptors))
	seen := map[string]struct{}{}
	for _, descriptor := range descriptors {
		name := safeName(descriptor.Metadata.Name)
		if _, exists := seen[name]; exists {
			return nil, fmt.Errorf("ADK tool name collision after normalization: %s", name)
		}
		seen[name] = struct{}{}
		inputSchema, err := decodeSchema(descriptor.Spec.InputSchema)
		if err != nil {
			return nil, fmt.Errorf("tool %s input schema: %w", descriptor.Metadata.Key(), err)
		}
		outputSchema, err := decodeSchema(descriptor.Spec.OutputSchema)
		if err != nil {
			return nil, fmt.Errorf("tool %s output schema: %w", descriptor.Metadata.Key(), err)
		}
		descriptor := descriptor
		adkTool, err := functiontool.New[map[string]any, map[string]any](functiontool.Config{
			Name: name, Description: descriptor.Spec.Description, InputSchema: inputSchema, OutputSchema: outputSchema,
		}, func(toolContext agent.Context, arguments map[string]any) (map[string]any, error) {
			raw, err := json.Marshal(arguments)
			if err != nil {
				return nil, err
			}
			call, err := a.service.RequestToolCall(toolContext, core.ToolCallRequest{
				SessionID: sessionID, RunID: runID,
				ToolRef:   grimlockapi.ResourceRef{Kind: grimlockapi.KindTool, Name: descriptor.Metadata.Name, Version: descriptor.Metadata.Version},
				Arguments: raw, IdempotencyKey: toolContext.FunctionCallID(),
			})
			if errors.Is(err, core.ErrApprovalRequired) {
				return map[string]any{"status": "approval_required", "approvalId": call.Approval.ID}, nil
			}
			if err != nil {
				return nil, err
			}
			var output map[string]any
			if err := json.Unmarshal(call.Output, &output); err != nil {
				return nil, err
			}
			return output, nil
		})
		if err != nil {
			return nil, fmt.Errorf("adapt tool %s: %w", descriptor.Metadata.Key(), err)
		}
		result = append(result, adkTool)
	}
	return result, nil
}

func decodeSchema(raw json.RawMessage) (*jsonschema.Schema, error) {
	var schema jsonschema.Schema
	if err := json.Unmarshal(raw, &schema); err != nil {
		return nil, err
	}
	return &schema, nil
}

func safeName(value string) string {
	value = invalidName.ReplaceAllString(value, "_")
	value = strings.Trim(value, "_")
	if value == "" {
		return "tool"
	}
	if value[0] >= '0' && value[0] <= '9' {
		return "tool_" + value
	}
	return value
}

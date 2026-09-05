@api @channels
Feature: Connect an external messaging service through channels
  In order to bring external conversations into a Figurate workspace
  As a third-party service
  I want to configure a channel route and address through the API

  Background:
    Given an API subject exists
    And an accessible space exists as "space_id"
    And the client "support-messaging-service" has these abilities:
      | nodes:read     |
      | nodes:write    |
      | edges:write    |
      | channels:manage |
      | forms:submit   |

  Scenario: Route an inbound support message into an addressed thread
    When the client sends a "POST" request to "/api/nodes" with JSON:
      """
      {
        "type": "thread",
        "parent": {
          "type": "space",
          "id": "{{space_id}}"
        },
        "attributes": {
          "title": "External support inbox",
          "purpose": "support",
          "phase": "active",
          "status": "open"
        }
      }
      """
    Then the response status should be 201
    And I remember response field "data.id" as "thread_id"

    When the client sends a "POST" request to "/api/channels" with JSON:
      """
      {
        "owner_type": "user",
        "owner_id": "me",
        "space_id": "{{space_id}}",
        "protocol": "generic",
        "name": "support-messaging",
        "label": "Support Messaging",
        "transport": "webhook",
        "endpoint_url": "https://messaging.example/webhooks"
      }
      """
    Then the response status should be 201
    And the response field "data.protocol" should equal "generic"
    And the response field "data.space.id" should equal "{{space_id}}"
    And I remember response field "data.id" as "channel_id"
    And I remember response field "link.id" as "channel_link_post_id"

    When the client sends a "POST" request to "/api/edges" with JSON:
      """
      {
        "source_type": "post",
        "source_id": "{{channel_link_post_id}}",
        "target_type": "thread",
        "target_id": "{{thread_id}}",
        "edge_type": "channel.link"
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "channel.link"
    And the response field "data.source.id" should equal "{{channel_link_post_id}}"
    And the response field "data.target.id" should equal "{{thread_id}}"

    When the client sends a "POST" request to "/api/posts" with JSON:
      """
      {
        "parent": {
          "type": "space",
          "id": "{{space_id}}"
        },
        "attributes": {
          "post_type": "skill",
          "tag": "support-message-normalization",
          "text": "Normalize the external target and preserve the sender identity.",
          "payload": {
            "slug": "support-message-normalization",
            "name": "Support message normalization",
            "description": "Normalize inbound support messages."
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.attributes.post_type" should equal "skill"
    And I remember response field "data.id" as "skill_post_id"

    When the client sends a "POST" request to "/api/form" with JSON:
      """
      {
        "body": {
          "type": "post",
          "id": "{{skill_post_id}}",
          "relations": [
            {
              "role": "skill",
              "target": {
                "type": "channel",
                "id": "{{channel_id}}"
              }
            }
          ]
        }
      }
      """
    Then the response status should be 200
    And the response field "data.id" should equal "{{skill_post_id}}"
    And the response field "relations.0.role" should equal "skill"
    And the response field "relations.0.target.type" should equal "channel"
    And the response field "relations.0.target.id" should equal "{{channel_id}}"
    And the response field "formed" should equal "true"
    And the response field "created" should equal "false"

    When the client sends a "POST" request to "/api/channels/{{channel_id}}/routes" with JSON:
      """
      {
        "name": "support-inbound",
        "direction": "bidirectional",
        "config": {
          "inbound": {
            "transport": "webhook",
            "auth": {
              "type": "header",
              "header": "X-Channel-Key",
              "secret": "support-route-secret"
            }
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.inbound.enabled" should equal "true"
    And the response field "data.skills.entries.0.source" should equal "post"
    And the response field "data.skills.entries.0.slug" should equal "support-message-normalization"
    And I remember response field "data.id" as "route_id"
    And I remember response field "data.inbound.url" as "inbound_url"

    When the client sends a "POST" request to "/api/channels/{{channel_id}}/routes/{{route_id}}/addresses" with JSON:
      """
      {
        "addressable_type": "thread",
        "addressable_id": "{{thread_id}}",
        "label": "Customer conversation",
        "provider": "generic",
        "target": "customer-42",
        "target_type": "conversation",
        "direction": "bidirectional"
      }
      """
    Then the response status should be 201
    And the response field "data.addressable.id" should equal "{{thread_id}}"
    And the response field "data.target" should equal "customer-42"

    Given the next request has header "X-Channel-Key" with value "support-route-secret"
    When the client sends a "POST" request to "{{inbound_url}}" with JSON:
      """
      {
        "id": "external-message-42",
        "target": "customer-42",
        "sender": "customer@example.com",
        "text": "I need help with my invoice."
      }
      """
    Then the response status should be 200

    When the client sends a "GET" request to "/api/threads/{{thread_id}}/nodes?type=post"
    Then the response status should be 200
    And the response field "data.0.type" should equal "post"
    And the response field "data.0.attributes.text" should equal "I need help with my invoice."
    And the response field "data.0.attributes.meta.external_payload.skill_context.entries.0.post_id" should equal "{{skill_post_id}}"

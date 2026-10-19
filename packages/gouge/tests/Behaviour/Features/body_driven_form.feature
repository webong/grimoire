@api @form
Feature: Form nodes from a declarative body
  In order to use one formation surface across domains
  As a third-party service
  I want the request body to determine what Figurate forms

  Background:
    Given an API subject exists
    And the client "formation-service" has these abilities:
      | forms:submit |
      | nodes:read   |
      | nodes:write  |

  Scenario: Create a Thread and Post through their resource endpoints
    When the client sends a "POST" request to "/api/spaces" with JSON:
      """
      {
        "status": "active"
      }
      """
    Then the response status should be 201
    And I remember response field "data.id" as "resource_space_id"

    When the client sends a "POST" request to "/api/threads" with JSON:
      """
      {
        "parent": {
          "type": "space",
          "id": "{{resource_space_id}}"
        },
        "attributes": {
          "title": "Direct API work",
          "purpose": "support",
          "phase": "intake",
          "status": "open"
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "thread"
    And the response field "data.attributes.title" should equal "Direct API work"
    And I remember response field "data.id" as "resource_thread_id"

    When the client sends a "POST" request to "/api/posts" with JSON:
      """
      {
        "parent": {
          "type": "thread",
          "id": "{{resource_thread_id}}"
        },
        "attributes": {
          "post_type": "support.note",
          "text": "Created without going through Form.",
          "payload": {
            "external_id": "NOTE-42"
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "post"
    And the response field "data.attributes.post_type" should equal "support.note"
    And the response field "data.attributes.text" should equal "Created without going through Form."
    And the response field "data.attributes.payload.external_id" should equal "NOTE-42"
    And I remember response field "data.id" as "resource_post_id"

    When the client sends a "GET" request to "/api/threads/{{resource_thread_id}}/nodes?type=post"
    Then the response status should be 200
    And the response field "data.0.id" should equal "{{resource_post_id}}"

  Scenario: Form a Space, Thread, and Post from their body descriptions
    When the client sends a "POST" request to "/api/form" with JSON:
      """
      {
        "body": {
          "type": "space",
          "attributes": {
            "status": "active"
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "space"
    And the response field "data.attributes.status" should equal "active"
    And the response field "formed" should equal "true"
    And the response field "created" should equal "true"
    And I remember response field "data.id" as "formed_space_id"

    When the client sends a "POST" request to "/api/form" with JSON:
      """
      {
        "body": {
          "type": "thread",
          "parent": {
            "type": "space",
            "id": "{{formed_space_id}}"
          },
          "attributes": {
            "title": "Formed support work",
            "purpose": "support",
            "phase": "intake",
            "status": "open"
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "thread"
    And the response field "data.attributes.title" should equal "Formed support work"
    And I remember response field "data.id" as "formed_thread_id"

    When the client sends a "POST" request to "/api/form" with JSON:
      """
      {
        "body": {
          "type": "post",
          "parent": {
            "type": "thread",
            "id": "{{formed_thread_id}}"
          },
          "attributes": {
            "post_type": "support.request",
            "text": "Investigate the failed invoice export.",
            "payload": {
              "external_id": "CASE-42"
            }
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "post"
    And the response field "data.attributes.post_type" should equal "support.request"
    And the response field "data.attributes.text" should equal "Investigate the failed invoice export."
    And the response field "data.attributes.payload.external_id" should equal "CASE-42"
    And I remember response field "data.id" as "formed_post_id"

    When the client sends a "POST" request to "/api/posts" with JSON:
      """
      {
        "parent": {
          "type": "space",
          "id": "{{formed_space_id}}"
        },
        "attributes": {
          "post_type": "skill",
          "tag": "invoice-investigation",
          "text": "Check the export job before retrying the invoice.",
          "payload": {
            "slug": "invoice-investigation",
            "name": "Invoice investigation"
          }
        }
      }
      """
    Then the response status should be 201
    And I remember response field "data.id" as "formed_skill_id"

    When the client sends a "POST" request to "/api/form" with JSON:
      """
      {
        "body": {
          "type": "post",
          "id": "{{formed_skill_id}}",
          "relations": [
            {
              "role": "skill",
              "target": {
                "type": "space",
                "id": "{{formed_space_id}}"
              }
            },
            {
              "role": "skill",
              "target": {
                "type": "thread",
                "id": "{{formed_thread_id}}"
              }
            },
            {
              "role": "skill",
              "target": {
                "type": "post",
                "id": "{{formed_post_id}}"
              }
            }
          ]
        }
      }
      """
    Then the response status should be 200
    And the response field "relations.0.target.type" should equal "space"
    And the response field "relations.1.target.type" should equal "thread"
    And the response field "relations.2.target.type" should equal "post"

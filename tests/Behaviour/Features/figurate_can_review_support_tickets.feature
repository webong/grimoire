@api @figurate-use-case @ticketing
Feature: Figurate can be used to review support tickets
  In order to prioritize customer support work from conversation history
  As a ticketing backend
  I want Figurate to produce review artifacts while my system owns ticket state and customer messaging

  Background:
    Given an API subject exists
    And the client "ticketing-backend" has these abilities:
      | nodes:write      |
      | forms:submit     |
      | invocations:read |

  Scenario: A billing transcript is reviewed inside the existing ticket thread
    Given an accessible automated thread exists
    And the deterministic AI provider responds with:
      """
      Severity is high. Ask billing operations to regenerate the invoice export and keep the CRM ticket status in the ticketing platform.
      """

    Given the next request has header "Idempotency-Key" with value "ticket-transcript-crm-8842"
    When the client sends a "POST" request to "/api/threads/{{thread_id}}/posts" with JSON:
      """
      {
        "type": "support.ticket.transcript",
        "text": "Customer cannot download a paid invoice after checkout.",
        "payload": {
          "source": {
            "system": "ticketing-platform",
            "external_id": "CRM-8842"
          },
          "conversation": {
            "messages": [
              {
                "sender": "customer",
                "body": "The invoice says paid but the PDF link is broken."
              },
              {
                "sender": "agent",
                "body": "I can escalate this to billing operations."
              }
            ]
          },
          "application_owned": {
            "ticket_id": "CRM-8842",
            "customer_id": "CUSTOMER-91"
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "support.ticket.transcript"
    And the response field "data.thread.id" should equal "{{thread_id}}"
    And the response field "data.payload.application_owned.ticket_id" should equal "CRM-8842"
    And I remember response field "data.id" as "ticket_source_post_id"

    Given the next request has header "Idempotency-Key" with value "ticket-review-crm-8842"
    When the client sends a "POST" request to "/api/posts/{{ticket_source_post_id}}/invocations" with JSON:
      """
      {
        "instructions": "Review severity, missing information, and next action. Do not mutate the CRM ticket."
      }
      """
    Then the response status should be 202
    And the response field "data.source_post.id" should equal "{{ticket_source_post_id}}"
    And the response field "data.thread_id" should equal "{{thread_id}}"
    And I remember response field "data.id" as "ticket_task_id"

    When the client sends a "GET" request to "/api/tasks/{{ticket_task_id}}"
    Then the response status should be 200
    And the response field "data.state" should equal "completed"
    And the response field "data.thread_id" should equal "{{thread_id}}"
    And the response field "data.artifacts.0.text" should equal "Severity is high. Ask billing operations to regenerate the invoice export and keep the CRM ticket status in the ticketing platform."
    And the response field "data.artifacts.0.source_relations.0.role" should equal "derived_from"
    And the response field "data.artifacts.0.source_relations.0.target.id" should equal "{{ticket_source_post_id}}"

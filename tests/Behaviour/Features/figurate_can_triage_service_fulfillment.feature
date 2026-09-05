@api @figurate-use-case @fulfillment
Feature: Figurate can be used to triage service fulfillment requests
  In order to decide how repair work should be routed before dispatch
  As a fulfillment backend
  I want Figurate to review intake context without owning quotes, orders, or payments

  Background:
    Given an API subject exists
    And the client "fulfillment-backend" has these abilities:
      | nodes:write      |
      | forms:submit     |
      | invocations:read |

  Scenario: Missing repair details are surfaced before dispatch
    Given an accessible space exists as "repair_space_id"
    And the deterministic AI provider responds with:
      """
      Missing access window and property photos. Route to urgent plumbing triage; keep quotes, orders, and payments in the fulfillment platform.
      """

    Given the next request has header "Idempotency-Key" with value "repair-intake-100"
    When the client sends a "POST" request to "/api/spaces/{{repair_space_id}}/posts" with JSON:
      """
      {
        "type": "service.fulfillment.request",
        "text": "Kitchen sink is leaking under the cabinet and the customer wants same-day repair.",
        "payload": {
          "source": {
            "system": "fulfillment-platform",
            "external_id": "REPAIR-100"
          },
          "request": {
            "description": "Kitchen sink leak under cabinet",
            "location": "12 Basin Road, Unit 4",
            "urgency": "same_day",
            "budget": {
              "amount": 250,
              "currency": "USD"
            },
            "timing": {
              "preferred_window": null
            },
            "evidence": [
              "customer_reported_water_pooling"
            ]
          },
          "application_owned": {
            "quote_id": "QUOTE-778",
            "order_id": "ORDER-441",
            "payment_id": "PAY-882"
          }
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "service.fulfillment.request"
    And the response field "data.payload.source.external_id" should equal "REPAIR-100"
    And the response field "data.payload.application_owned.quote_id" should equal "QUOTE-778"
    And I remember response field "data.id" as "repair_source_post_id"

    Given the next request has header "Idempotency-Key" with value "repair-review-100"
    When the client sends a "POST" request to "/api/posts/{{repair_source_post_id}}/invocations" with JSON:
      """
      {
        "instructions": "Identify missing fulfillment information and recommend routing. Do not create quotes, orders, payments, or callbacks."
      }
      """
    Then the response status should be 202
    And the response field "data.state" should equal "submitted"
    And the response field "data.source_post.id" should equal "{{repair_source_post_id}}"
    And the response field "data.prompt.text" should equal "Identify missing fulfillment information and recommend routing. Do not create quotes, orders, payments, or callbacks."
    And I remember response field "data.id" as "repair_task_id"
    And I remember response field "data.thread_id" as "repair_review_thread_id"

    When the client sends a "GET" request to "/api/tasks/{{repair_task_id}}"
    Then the response status should be 200
    And the response field "data.state" should equal "completed"
    And the response field "data.source_post.id" should equal "{{repair_source_post_id}}"
    And the response field "data.thread_id" should equal "{{repair_review_thread_id}}"
    And the response field "data.artifacts.0.text" should equal "Missing access window and property photos. Route to urgent plumbing triage; keep quotes, orders, and payments in the fulfillment platform."
    And the response field "data.artifacts.0.source_relations.0.role" should equal "derived_from"
    And the response field "data.artifacts.0.source_relations.0.target.id" should equal "{{repair_source_post_id}}"

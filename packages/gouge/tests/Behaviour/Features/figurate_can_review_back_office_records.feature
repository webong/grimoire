@api @figurate-use-case @back-office
Feature: Figurate can be used to review back-office records
  In order to review operational records from ERP, CMS, and automation systems
  As a back-office backend
  I want Figurate to use the same context and task workflow without product-specific endpoints

  Background:
    Given an API subject exists
    And the client "back-office-backend" has these abilities:
      | nodes:write      |
      | forms:submit     |
      | invocations:read |

  Scenario Outline: An operational record is reviewed without a product-specific endpoint
    Given an accessible space exists as "artifact_space_id"
    And the deterministic AI provider responds with:
      """
      <agent_response>
      """

    Given the next request has header "Idempotency-Key" with value "<source_key>"
    When the client sends a "POST" request to "/api/spaces/{{artifact_space_id}}/posts" with JSON:
      """
      {
        "type": "<post_type>",
        "text": "<source_text>",
        "payload": {
          "source": {
            "system": "<source_system>",
            "external_id": "<external_id>"
          },
          "record": <record_payload>,
          "application_owned": <application_owned_payload>
        }
      }
      """
    Then the response status should be 201
    And the response field "data.type" should equal "<post_type>"
    And the response field "data.payload.source.system" should equal "<source_system>"
    And the response field "data.payload.source.external_id" should equal "<external_id>"
    And I remember response field "data.id" as "artifact_source_post_id"

    Given the next request has header "Idempotency-Key" with value "<review_key>"
    When the client sends a "POST" request to "/api/posts/{{artifact_source_post_id}}/invocations" with JSON:
      """
      {
        "instructions": "<instructions>"
      }
      """
    Then the response status should be 202
    And the response field "data.source_post.id" should equal "{{artifact_source_post_id}}"
    And the response field "data.prompt.text" should equal "<instructions>"
    And I remember response field "data.id" as "artifact_task_id"

    When the client sends a "GET" request to "/api/tasks/{{artifact_task_id}}"
    Then the response status should be 200
    And the response field "data.state" should equal "completed"
    And the response field "data.artifacts.0.text" should equal "<agent_response>"
    And the response field "data.artifacts.0.source_relations.0.role" should equal "derived_from"
    And the response field "data.artifacts.0.source_relations.0.target.id" should equal "{{artifact_source_post_id}}"

    Examples:
      | post_type              | source_system | external_id | source_text                                   | record_payload                                                                                    | application_owned_payload                                      | instructions                                                                | agent_response                                                                                       | source_key         | review_key             |
      | erp.invoice.exception  | erp-platform  | INV-778     | Invoice INV-778 failed approval in the ERP.   | {"invoice_id":"INV-778","amount":1280,"currency":"USD","failure_reason":"missing_purchase_order"} | {"invoice_id":"INV-778","approval_state":"blocked"}            | Review the ERP exception and recommend the next operational action.         | Missing purchase order. Route to accounts payable review; keep invoice approval state in the ERP.    | erp-source-inv-778 | erp-review-inv-778     |
      | cms.publication.change | cms-platform  | REV-551     | Homepage campaign copy is waiting for review. | {"revision_id":"REV-551","page":"homepage","risk_flags":["pricing_claim","dated_offer"]}          | {"revision_id":"REV-551","publication_state":"pending_review"} | Review the CMS change for publication risk without publishing the revision. | Pricing claim and dated offer need approval; keep publication, rollback, and audit state in the CMS. | cms-source-rev-551 | cms-review-rev-551     |

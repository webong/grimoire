# Agent Invocation Use Cases

Agent invocation turns an existing Figurate `Post` into explicit, tracked agent work. Context ingestion remains side-effect free: storing a post does not automatically run an agent. The integration decides when processing should begin, supplies the instructions, and receives a task identifier that can be followed independently of the model invocation.

The same contract applies to CRM conversations, ERP records, CMS changes, service requests, and other external artifacts. Figurate stores coordination context and agent output; the consuming application continues to own its domain records and state transitions.

## Actors

- **External integration:** Authenticates with Figurate, stores source context, starts an invocation, and retains its own external identifiers.
- **Figurate API:** Authorizes access, persists context, creates the processing thread and prompt, and exposes task state.
- **Presenter agent:** Reviews the source post under the supplied instructions and writes one or more durable result posts.
- **Consuming application:** Reads the result, applies any domain-specific decision, and performs callbacks or updates in its own system.

## Preconditions

The integration must have:

- an authenticated API credential;
- access to the source `Space` or `Thread`;
- `nodes:write` to store source context;
- `forms:submit` to start an invocation;
- `invocations:read` to inspect the resulting task;
- a stable `Idempotency-Key` for each ingestion and invocation request.

`forms:submit` permits the client to request work. `invocations:read` permits it to inspect task progress and results. Keeping these abilities separate allows a submission-only client and a monitoring client to be issued independently.

## Shared Lifecycle

1. Resolve or create the `Space` that owns the work.
2. Store the source context with `POST /api/spaces/{space}/posts` or `POST /api/threads/{thread}/posts`.
3. Start processing with `POST /api/posts/{post}/invocations` and a new invocation idempotency key.
4. Receive `202 Accepted` with a stable task UUID.
5. Poll `GET /api/tasks/{task}` using a credential with `invocations:read`.
6. When the task is complete, consume its durable assistant artifacts.
7. Correlate each artifact to the original source through the task's `source_post` and the artifact's `derived_from` graph relation.
8. Apply any external state change or callback in the consuming application.

A post stored directly on a thread is processed in that thread. A post stored directly on a space receives a dedicated review thread so its prompt, agent output, and execution history remain isolated and traceable.

## Invocation Contract

Start an invocation with a credential that has `forms:submit`:

```http
POST /api/posts/01K4SOURCEPOST0000000000000/invocations
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: crm-review-CRM-8842-v1
```

```json
{
  "instructions": "Review this source context. Identify severity, required action, missing information, and the recommended next step."
}
```

`instructions` is required, must not be blank, and is limited to 20,000 characters. The source envelope supplied to the agent contains the post's public identifier, type, tag, status, occurrence time, text, and payload. Internal post metadata is excluded. An encoded source envelope larger than 64 KiB is rejected rather than silently truncated.

### Accepted Task

```http
HTTP/1.1 202 Accepted
```

```json
{
  "data": {
    "id": "0198f1d0-6f28-77ae-b521-d173991ad6d9",
    "kind": "task",
    "state": "submitted",
    "source_post": {
      "id": "01K4SOURCEPOST0000000000000",
      "type": "crm.conversation"
    },
    "space_id": "0198f1c8-52af-77fb-9664-30ec24fd63f8",
    "thread_id": "0198f1d0-65e4-7db8-891a-858b05a55e46",
    "prompt": {
      "id": "01K4PROMPTPOST0000000000000",
      "text": "Review this source context. Identify severity, required action, missing information, and the recommended next step.",
      "created_at": "2026-08-08T10:00:00+00:00"
    },
    "invocations": [],
    "artifacts": []
  }
}
```

The task UUID is the stable asynchronous handle. A model invocation identifier may not exist when the task is accepted.

### Working Task

Read the task with a credential that has `invocations:read`:

```http
GET /api/tasks/0198f1d0-6f28-77ae-b521-d173991ad6d9
Authorization: Bearer <token>
```

```json
{
  "data": {
    "id": "0198f1d0-6f28-77ae-b521-d173991ad6d9",
    "kind": "task",
    "state": "working",
    "source_post": {
      "id": "01K4SOURCEPOST0000000000000",
      "type": "crm.conversation"
    },
    "space_id": "0198f1c8-52af-77fb-9664-30ec24fd63f8",
    "thread_id": "0198f1d0-65e4-7db8-891a-858b05a55e46",
    "prompt": {
      "id": "01K4PROMPTPOST0000000000000",
      "text": "Review this source context. Identify severity, required action, missing information, and the recommended next step.",
      "created_at": "2026-08-08T10:00:00+00:00"
    },
    "invocations": [
      {
        "actor_key": "coordinator_agent",
        "status": "pending",
        "invocation_id": null,
        "conversation_id": null,
        "error_message": null,
        "recorded_at": "2026-08-08T10:00:01+00:00"
      }
    ],
    "artifacts": []
  }
}
```

### Completed Task

```json
{
  "data": {
    "id": "0198f1d0-6f28-77ae-b521-d173991ad6d9",
    "kind": "task",
    "state": "completed",
    "source_post": {
      "id": "01K4SOURCEPOST0000000000000",
      "type": "crm.conversation"
    },
    "space_id": "0198f1c8-52af-77fb-9664-30ec24fd63f8",
    "thread_id": "0198f1d0-65e4-7db8-891a-858b05a55e46",
    "prompt": {
      "id": "01K4PROMPTPOST0000000000000",
      "text": "Review this source context. Identify severity, required action, missing information, and the recommended next step.",
      "created_at": "2026-08-08T10:00:00+00:00"
    },
    "invocations": [
      {
        "actor_key": "coordinator_agent",
        "status": "completed",
        "invocation_id": "0198f1d2-9325-7059-99ea-41012143b812",
        "conversation_id": "0198f1d2-9080-78b3-8096-47ce91ab35e5",
        "error_message": null,
        "recorded_at": "2026-08-08T10:00:08+00:00"
      }
    ],
    "artifacts": [
      {
        "id": "01K4REVIEWPOST0000000000000",
        "role": "assistant",
        "text": "Severity is high. Billing operations should regenerate the invoice export and confirm the restored download link.",
        "actor_key": "coordinator_agent",
        "created_at": "2026-08-08T10:00:08+00:00",
        "a2ui": null
      }
    ]
  }
}
```

The assistant artifact is a normal durable Figurate post. It has a `derived_from` relation to the source post. The consuming application may use the task ID, source post ID, model invocation ID, and its own external identifier for correlation.

### Failed Task

```json
{
  "data": {
    "id": "0198f1d0-6f28-77ae-b521-d173991ad6d9",
    "kind": "task",
    "state": "failed",
    "source_post": {
      "id": "01K4SOURCEPOST0000000000000",
      "type": "crm.conversation"
    },
    "space_id": "0198f1c8-52af-77fb-9664-30ec24fd63f8",
    "thread_id": "0198f1d0-65e4-7db8-891a-858b05a55e46",
    "prompt": {
      "id": "01K4PROMPTPOST0000000000000",
      "text": "Review this source context. Identify severity, required action, missing information, and the recommended next step.",
      "created_at": "2026-08-08T10:00:00+00:00"
    },
    "invocations": [
      {
        "actor_key": "coordinator_agent",
        "status": "failed",
        "invocation_id": null,
        "conversation_id": null,
        "error_message": "The configured model provider did not accept the request.",
        "recorded_at": "2026-08-08T10:00:03+00:00"
      }
    ],
    "artifacts": []
  }
}
```

## Task States

| State | Meaning |
| --- | --- |
| `submitted` | Figurate accepted the work and created its durable task record. |
| `working` | At least one presenter invocation is pending or the task has mixed unfinished outcomes. |
| `completed` | All presenter invocations completed and their result artifacts are available. |
| `failed` | Processing ended without a pending invocation and at least one invocation failed. |
| `canceled` | All unfinished presenter invocations were canceled. |

The proposed API does not include a generic cancellation endpoint in its first version. A task can still expose `canceled` when cancellation occurs through another supported orchestration surface.

## Use Case 1: CRM Conversation Review

### Goal

A CRM integration wants an agent to review a customer conversation for severity, required action, missing information, and the recommended response. The CRM remains the system of record for the ticket and customer.

### Credential Abilities

```json
[
  "nodes:write",
  "forms:submit",
  "invocations:read"
]
```

### Store the Conversation

```http
POST /api/spaces/0198f1c8-52af-77fb-9664-30ec24fd63f8/posts
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: crm-conversation-CRM-8842-v1
```

```json
{
  "type": "crm.conversation",
  "text": "Customer cannot download a paid invoice.",
  "payload": {
    "source": {
      "system": "crm",
      "conversation_id": "CRM-8842"
    },
    "customer": {
      "external_id": "CUSTOMER-91"
    },
    "messages": [
      {
        "sender": "customer",
        "body": "I paid invoice INV-109, but the download link does not work."
      },
      {
        "sender": "support",
        "body": "We are checking the export status."
      }
    ]
  },
  "meta": {
    "review_requested": true
  }
}
```

`review_requested` is CRM metadata only. It does not automatically invoke an agent.

### Request the Review

```json
{
  "instructions": "Review this conversation. Classify severity, identify the responsible team, list missing evidence, and recommend the next response to the customer."
}
```

The integration submits those instructions to `POST /api/posts/{post}/invocations` with `Idempotency-Key: crm-review-CRM-8842-v1`, then polls the returned task using `invocations:read`.

When the task reaches `completed`, the CRM reads the assistant artifact and decides whether to update ticket `CRM-8842`, notify billing operations, or send a customer response. Figurate does not update the CRM ticket unless the integration invokes an explicitly configured callback or tool.

## Use Case 2: Generic External Artifact Review

### Goal

An external product submits an artifact that needs analysis without requiring Figurate to add an ERP-, CMS-, ticketing-, or automation-specific endpoint.

### Credential Abilities

```json
[
  "nodes:write",
  "forms:submit",
  "invocations:read"
]
```

### Store the Artifact

```http
POST /api/threads/0198f28c-625d-782d-b341-32741e341a15/posts
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: external-artifact-REV-551-v3
```

```json
{
  "type": "external.artifact",
  "text": "Proposed homepage publication changes pricing copy and the primary call to action.",
  "payload": {
    "source": {
      "system": "cms",
      "external_id": "REV-551",
      "version": 3
    },
    "artifact": {
      "kind": "content.change.proposed",
      "changed_fields": [
        "headline",
        "pricing_summary",
        "primary_cta"
      ]
    }
  }
}
```

### Request the Review

```json
{
  "instructions": "Review this artifact for material risk, missing approvals, and conflicting claims. Return a concise recommendation with supporting evidence."
}
```

Because the source post belongs to a thread, Figurate runs the invocation in that existing thread. The completed artifact is another generic post linked back to `REV-551` through the source post. The CMS remains responsible for publication state, approvals, rollback, and audit requirements outside Figurate.

The same shape can carry an invoice exception from an ERP, a ticket escalation from a support platform, or an automation event. Only the source `type`, payload, and review instructions change.

## Use Case 3: Service Fulfillment Review

### Goal

A service marketplace wants an agent to assess whether a repair request is complete enough to route to providers. The marketplace owns service categories, quotes, orders, payments, ratings, and disputes.

### Credential Abilities

```json
[
  "nodes:write",
  "forms:submit",
  "invocations:read"
]
```

### Store the Service Request

```http
POST /api/spaces/0198f322-990f-73b3-a057-ac8162c33b21/posts
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: service-request-JOB-2041-v1
```

```json
{
  "type": "service.requested",
  "text": "Repair a damaged apartment entrance door that no longer closes securely.",
  "payload": {
    "source": {
      "system": "marketplace",
      "external_id": "JOB-2041"
    },
    "location": {
      "city": "Lagos",
      "area": "Yaba"
    },
    "urgency": "high",
    "budget": {
      "currency": "NGN",
      "maximum": 150000
    },
    "timing": {
      "preferred_date": "2026-08-12",
      "access_window": "09:00-14:00"
    },
    "evidence": [
      {
        "kind": "photo",
        "external_url": "https://media.example/jobs/JOB-2041/door-1.jpg"
      }
    ]
  }
}
```

### Request the Review

```json
{
  "instructions": "Assess whether this request is ready for provider routing. Identify safety concerns, missing measurements or evidence, and the recommended service category and next action. Do not create a quote or promise a provider outcome."
}
```

Figurate creates a dedicated review thread because the source belongs directly to a space. The agent can recommend collecting door dimensions, confirming lock damage, or routing the request to an emergency carpenter. It must not create a marketplace quote, order, payment, rating, or dispute record. The marketplace consumes the completed artifact and performs those domain actions under its own rules.

## Ownership Boundary

Figurate owns:

- spaces, threads, posts, graph relations, presenter assignments, task snapshots, invocation telemetry, and agent-generated artifacts;
- authorization around those resources;
- durable traceability from source post to prompt, task, and assistant artifacts.

The consuming application owns:

- CRM tickets, customers, and outbound customer messaging;
- ERP invoices, approvals, and financial state;
- CMS publications, release state, and rollback;
- fulfillment requests, quotes, orders, payments, ratings, and disputes;
- whether and how an agent recommendation changes external state;
- callback delivery and retry policy for its own systems.

## Idempotency and Retry Behavior

- Repeating an invocation with the same `Idempotency-Key` and identical input returns the original `202` response and task UUID.
- Reusing the same key with different instructions returns `409 Conflict`.
- After a network timeout, retry the same request with the same key instead of creating a new key.
- A task in `working` should continue to be polled; submitting another invocation can create duplicate agent work.
- A task in `failed` remains durable for diagnosis. A deliberate retry uses a new idempotency key so it creates a distinct task and audit trail.
- Source ingestion and agent invocation use different idempotency keys because they are separate operations.

## Authorization and Validation Failures

| Status | Condition |
| --- | --- |
| `401` | The request has no valid authenticated client. |
| `403` | The token lacks `forms:submit` for invocation, lacks `invocations:read` for task inspection, or the actor cannot access the source context. |
| `404` | The source post or actor-owned task does not exist. Tasks owned by another actor are not exposed. |
| `409` | An idempotency key is reused with different input. |
| `422` | Instructions are blank or too long, the source is not owned by a space or thread, or its canonical source envelope exceeds 64 KiB. |

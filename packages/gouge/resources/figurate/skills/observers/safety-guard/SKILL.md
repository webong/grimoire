---
name: safety-guard
description: Safety observer skill for human-to-human chat threads. Detects risky or sensitive content and returns allow, flag, block, or suggest outcomes.
---

# Safety Guard Observer

Use this observer skill when a thread actor should assess peer messages for safety policy outcomes.

## Runtime Contract

1. Return one normalized observer event outcome:
   - `message_blocked`
   - `moderation_flagged`
   - `suggestion_created`
   - or no event (`event_type=null`)
2. Set severity as `low`, `medium`, or `high`.
3. Use `redact_message=true` only for blocked outcomes.

## Fallback Rules

Keyword fallback rules are loaded from `skill.json`.

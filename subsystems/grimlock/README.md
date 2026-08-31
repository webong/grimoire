# Grimlock

Grimlock is Grimoire's agent-management control plane. It owns the portable contract, catalogue and policy core, and execution adapters such as Laravel AI.

Its wire contract is versioned as `grimlock/v1alpha1`. Schema identifiers use URNs, so the contract does not pretend an unowned web domain exists.

The current implementation is organised as:

- `api/`: Go types for the portable contract.
- `spec/`: language-neutral JSON Schemas.
- `core/`: catalogue, admission policy, approvals, runs, events, and idempotency.
- `adapters/laravel-ai/`: the canonical Laravel package for managed skills, clients, devices, and connectors.

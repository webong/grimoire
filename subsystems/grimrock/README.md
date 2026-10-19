# Grimrock (deprecated)

Grimrock contains the initial Go execution contract and ADK-Go engine. Under
the current architecture it is not part of the Grimoire registry service: its
runtime API, engine selection, sessions, runs, cancellation, tool execution,
delegation, and event normalization move into the Grimoire-owned Go SDK.

The directory is retained only as a migration source. New execution work must
be implemented in an independently versioned runtime implementation, which
consumes immutable resolved definitions from the Grimoire registry.

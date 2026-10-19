# Grimoire / Grimlock Laravel adapter (migration source)

This package currently mixes two concerns:

- registry persistence and discovery for skills, capabilities, and connector
  declarations; and
- Laravel AI tool invocation, session/run calls, connector execution, and
  policy enforcement.

Only the first concern belongs in the registry. The execution-oriented interfaces,
tools, models, and migrations are migration sources for Gouge, Grimoire's Laravel AI runtime, and must not be
extended here.

`EloquentRegistry` and the `catalog_entries` storage model are candidates for
a future registry-persistence adapter after the runtime tables have been split
out. Host consumers must not depend on this package for agent execution.

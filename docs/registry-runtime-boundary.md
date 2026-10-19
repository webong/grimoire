# Registry and runtime boundary

Grimoire stores runtime artifacts; it is not a runtime.

```text
Grimoire                         Runtime host
--------                         ------------
immutable runtime manifests      process/container launch
skill and tool artifacts          provider/model invocation
policies and requirements         live policy enforcement
resolution lockfiles              sessions and execution events
verified sandbox layout           cancellation and approvals
                                  credentials and external effects
```

The handoff is a `Resolution` lockfile plus a materialized directory. The host
must verify that lockfile, admit the declared requirements, and execute using its
native SDK. Runtime state must never be written into registry manifests or
content-addressed objects.

Runtime health, placement, usage, and billing are separate optional systems.
They may reference registry digests but are not required by the embedded core.

Historical `Session`, `Run`, `Approval`, and `Event` types under
`subsystems/grimlock` are migration source. Historical SQLite and HTTP execution
paths do not define the new registry API.

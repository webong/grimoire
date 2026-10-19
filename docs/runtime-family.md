# Registered runtimes

Runtime implementations are ordinary Grimoire resources. Grimoire does not own
a fixed runtime family and does not embed one SDK inside another.

A `Runtime` manifest declares:

- its protocol version;
- an immutable artifact digest;
- an entrypoint understood by a runtime host;
- supported capabilities; and
- requested sandbox properties.

Skills declare the capabilities and exact tools they require. Resolution checks
those requirements against a selected published runtime and emits a lockfile.
The runtime host then admits and executes that lockfile using its own native
session, event, credential, approval, and cancellation behavior.

The repository's Laravel AI, ADK-Go, and Grimrock code is migration source. If
retained, each implementation should become an artifact plus a thin host adapter
and pass shared bundle-admission fixtures. Gouge is no longer a product-level
name or required package boundary.

## Conformance target

A runtime host is compatible when it can:

1. verify the resolution and every locked object digest;
2. reject unsupported capabilities before executing;
3. keep registered artifacts read-only;
4. restrict writes to the supplied scratch area or explicitly admitted mounts;
5. expose its native lifecycle without implying that Grimoire executed the run;
   and
6. preserve evidence of external effects during failure or cancellation.

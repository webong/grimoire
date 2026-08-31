# Grimrock

Grimrock is Grimoire's execution plane. Grimlock decides what may run and issues an admitted run; Grimrock dispatches that run to an engine and returns normalized execution events.

Grimrock owns engine selection, execution requests, cancellation, and event normalization. It does not own the resource catalogue, authorization policy, approvals, credentials, or product data.

The initial engine is Google ADK for Go. Laravel AI participates through its own adapter under the same execution contract.

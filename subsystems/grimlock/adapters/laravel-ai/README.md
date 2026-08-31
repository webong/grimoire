# Grimoire / Grimlock Laravel AI adapter

This package is the Grimlock subsystem's canonical PHP surface within Grimoire for Laravel hosts. It owns the reusable management contract for:

- skills available to an agent;
- application and IDE clients;
- managed devices such as browsers, displays, and native hosts;
- MCP, A2A, ACP, HTTP, and native connectors;
- policy-gated connector invocation.

The host owns storage and protocol I/O. A Figurate provider will translate its `UserClient`, `Channel`, channel-link, and skill sources into the contracts in this package; it does not retain a separate management model.

## Integration points

- `SkillCatalog` supplies `ManagedSkill` records.
- `ClientRegistry`, `DeviceRegistry`, and `ConnectorRegistry` own discovery and lookup.
- `ConnectorInvocationPolicy` decides whether an operation may be invoked.
- `ConnectorInvoker` carries out protocol-specific MCP/A2A/ACP/native I/O after policy allows it.
- `DiscoverManagedSkillsTool`, `ListManagedConnectorsTool`, and `InvokeManagedConnectorTool` are ready for a Laravel AI agent's `tools()` method.

The default `EloquentRegistry` persists the surface in Grimlock's tables. The package service provider loads its migrations, so a Laravel host installs them through its normal `php artisan migrate` workflow. `InMemoryRegistry` remains the development and conformance implementation.

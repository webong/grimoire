<?php

declare(strict_types=1);

namespace Grimlock\LaravelAi\Support;

use Grimlock\LaravelAi\Contracts\ConnectorInvocationPolicy;
use Grimlock\LaravelAi\ValueObjects\ManagedConnector;
use Grimlock\LaravelAi\ValueObjects\PolicyDecision;

// The host may decorate this policy with network-trust, RBAC, and budget
// checks. This baseline preserves active/direction/allowlist gate semantics.
final class DefaultConnectorInvocationPolicy implements ConnectorInvocationPolicy
{
    public function authorize(ManagedConnector $connector, string $operation): PolicyDecision
    {
        if ($connector->status !== ManagedConnector::STATUS_ACTIVE) {
            return PolicyDecision::deny('Connector is not active.');
        }
        if (! $connector->maySend()) {
            return PolicyDecision::deny('Connector does not permit outbound invocation.');
        }
        if (! $connector->allows($operation)) {
            return PolicyDecision::deny('Connector operation is not allowlisted.');
        }

        return PolicyDecision::allow();
    }
}

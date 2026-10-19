<?php

namespace Webong\Gouge\Storage;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use RuntimeException;
use Webong\Gouge\Contracts\RuntimeStore;
use Webong\Gouge\Runtime\AgentRuntimeCancellation;
use Webong\Gouge\Runtime\AgentRuntimeCorrelation;
use Webong\Gouge\Runtime\AgentRuntimeEvent;
use Webong\Gouge\Runtime\AgentTurn;
use Webong\Gouge\Runtime\ExecutionResult;

final class DatabaseRuntimeStore implements RuntimeStore
{
    public function __construct(private ConnectionInterface $db) {}

    public function reserve(AgentTurn $turn): bool
    {
        $c = new AgentRuntimeCorrelation($turn->scopeId, $turn->sessionId, $turn->runId);

        return $this->db->transaction(function () use ($turn, $c): bool {
            $this->db->table('gouge_sessions')->insertOrIgnore([
                'key' => $this->sessionKey($c),
                'scope_id' => $turn->scopeId, 'session_id' => $turn->sessionId,
                'definition_key' => $turn->definitionKey(), 'active_run_id' => null, 'history' => '[]',
            ]);
            $session = $this->sessionQuery($c)->lockForUpdate()->first();
            $existing = $this->db->table('gouge_runs')->where('key', $this->runKey($c))->first();
            if ($existing) {
                if ($existing->fingerprint !== $turn->fingerprint()) {
                    throw new RuntimeException('Run ID was already used for different input.');
                }

                return false;
            }
            if ($session->definition_key !== $turn->definitionKey()) {
                throw new RuntimeException('Session is pinned to a different agent definition.');
            }
            if ($session->active_run_id !== null) {
                throw new RuntimeException('Session already has an active run.');
            }
            $events = [];
            $this->append($events, 'turn.accepted');
            $this->db->table('gouge_runs')->insert([
                'key' => $this->runKey($c), 'session_key' => $this->sessionKey($c),
                'scope_id' => $c->scopeId, 'session_id' => $c->sessionId, 'run_id' => $c->runId,
                'fingerprint' => $turn->fingerprint(), 'request' => $this->json($turn->toArray()),
                'status' => 'accepted', 'cancel_requested' => false, 'events' => $this->json($events),
            ]);
            $this->sessionQuery($c)->update(['active_run_id' => $c->runId]);

            return true;
        });
    }

    public function history(AgentRuntimeCorrelation $c): array
    {
        if (! $this->runQuery($c)->exists()) {
            throw new RuntimeException('Unknown runtime run.');
        }

        return $this->decode($this->sessionQuery($c)->value('history'));
    }

    public function start(AgentRuntimeCorrelation $c): bool
    {
        return $this->db->transaction(function () use ($c): bool {
            $this->sessionQuery($c)->lockForUpdate()->first();
            $run = $this->runQuery($c)->lockForUpdate()->first();
            if (! $run || $run->status !== 'accepted') {
                return false;
            }
            $events = $this->decode($run->events);
            $this->append($events, 'turn.started');
            $this->runQuery($c)->update(['status' => 'running', 'events' => $this->json($events)]);

            return true;
        });
    }

    public function finish(AgentRuntimeCorrelation $c, ?ExecutionResult $result, ?string $error = null): void
    {
        $this->db->transaction(function () use ($c, $result, $error): void {
            $session = $this->sessionQuery($c)->lockForUpdate()->first();
            $run = $this->runQuery($c)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['accepted', 'running'], true)) {
                return;
            }
            $events = $this->decode($run->events);
            if ($run->cancel_requested) {
                $status = 'cancelled';
                $this->append($events, 'turn.cancelled', ['interrupted' => false,
                    'result_discarded' => $result !== null, 'telemetry' => $result?->telemetry ?? []]);
            } elseif ($error !== null) {
                $status = 'failed';
                $this->append($events, 'turn.failed', ['error' => $error]);
            } else {
                if ($result === null) {
                    throw new RuntimeException('Completion requires an execution result.');
                }
                $status = 'completed';
                $request = $this->decode($run->request);
                $history = [...$this->decode($session->history), ['role' => 'user', 'content' => $request['prompt']], ...$result->messages];
                $this->sessionQuery($c)->update(['history' => $this->json($history)]);
                $this->append($events, 'turn.output', ['text' => $result->text, 'telemetry' => $result->telemetry]);
                $this->append($events, 'turn.completed');
            }
            $this->runQuery($c)->update(['status' => $status, 'events' => $this->json($events)]);
            $this->sessionQuery($c)->where('active_run_id', $c->runId)->update(['active_run_id' => null]);
        });
    }

    public function cancel(AgentRuntimeCorrelation $c): AgentRuntimeCancellation
    {
        return $this->db->transaction(function () use ($c): AgentRuntimeCancellation {
            $this->sessionQuery($c)->lockForUpdate()->first();
            $run = $this->runQuery($c)->lockForUpdate()->first();
            if (! $run || ! in_array($run->status, ['accepted', 'running'], true)) {
                return new AgentRuntimeCancellation($c, false, false, 'Run is unknown or already terminal.');
            }
            if (! $run->cancel_requested) {
                $events = $this->decode($run->events);
                $status = $run->status;
                if ($status === 'accepted') {
                    $status = 'cancelled';
                    $this->append($events, 'turn.cancelled', ['interrupted' => false]);
                    $this->sessionQuery($c)->where('active_run_id', $c->runId)->update(['active_run_id' => null]);
                } else {
                    $this->append($events, 'turn.cancellation_requested', ['interrupted' => false]);
                }
                $this->runQuery($c)->update(['status' => $status, 'cancel_requested' => true, 'events' => $this->json($events)]);
            }

            return new AgentRuntimeCancellation($c, true, false, 'Cooperative cancellation; in-flight model/tool calls are not interrupted.');
        });
    }

    public function events(AgentRuntimeCorrelation $c): iterable
    {
        $run = $this->runQuery($c)->first();
        if (! $run) {
            return;
        }
        foreach ($this->decode($run->events) as $event) {
            yield new AgentRuntimeEvent($event['type'], $c, $event['sequence'], $event['payload'], $event['occurred_at']);
        }
    }

    private function runQuery(AgentRuntimeCorrelation $c): Builder
    {
        if ($c->runtime !== 'laravel-ai') {
            throw new RuntimeException('Correlation belongs to another runtime.');
        }

        return $this->db->table('gouge_runs')->where('key', $this->runKey($c))
            ->where('session_key', $this->sessionKey($c));
    }

    private function sessionQuery(AgentRuntimeCorrelation $c): Builder
    {
        return $this->db->table('gouge_sessions')->where('key', $this->sessionKey($c));
    }

    // Hash exact identifiers so database collation cannot merge differently cased scopes.
    private function sessionKey(AgentRuntimeCorrelation $c): string
    {
        return hash('sha256', $this->json([$c->scopeId, $c->sessionId]));
    }

    private function runKey(AgentRuntimeCorrelation $c): string
    {
        return hash('sha256', $this->json([$c->scopeId, $c->runId]));
    }

    private function append(array &$events, string $type, array $payload = []): void
    {
        $events[] = ['type' => $type, 'sequence' => count($events), 'payload' => $payload, 'occurred_at' => gmdate('c')];
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    private function decode(string $value): array
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 32);
            $table->string('name', 191);
            $table->string('version', 64);
            $table->char('digest', 64);
            $table->json('spec');
            $table->timestampsTz();

            $table->unique(['kind', 'name', 'version'], 'resource_version_unique');
            $table->index('kind', 'resource_kind_index');
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 64);
            $table->string('device_identifier', 191);
            $table->string('platform', 64)->nullable();
            $table->string('app_version', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique('device_identifier', 'client_device_unique');
            $table->index('kind', 'client_kind_index');
        });

        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('client_id')->nullable();
            $table->string('kind', 64);
            $table->string('label', 191);
            $table->json('capabilities')->nullable();
            $table->json('endpoints')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index('kind', 'device_kind_index');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
        });

        Schema::create('connectors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('client_id')->nullable();
            $table->ulid('device_id')->nullable();
            $table->string('name', 191);
            $table->string('protocol', 32);
            $table->string('transport', 32);
            $table->string('direction', 32);
            $table->string('status', 32)->default('active');
            $table->text('endpoint')->nullable();
            $table->string('handler', 191)->nullable();
            $table->string('credential_ref', 191)->nullable();
            $table->json('allowed_operations');
            $table->json('metadata')->nullable();
            $table->timestampTz('last_connected_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique('name', 'connector_name_unique');
            $table->index(['protocol', 'status'], 'connector_discovery_index');
            $table->foreign('client_id')->references('id')->on('clients')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('subject_id', 191);
            $table->ulid('agent_resource_id');
            $table->string('status', 32)->default('ready');
            $table->json('state')->nullable();
            $table->timestampsTz();

            $table->index('subject_id', 'session_subject_index');
            $table->foreign('agent_resource_id')->references('id')->on('resources')->restrictOnDelete();
        });

        Schema::create('runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('session_id');
            $table->ulid('agent_resource_id');
            $table->ulid('parent_run_id')->nullable();
            $table->string('execution_adapter', 64);
            $table->string('status', 32);
            $table->string('trace_id', 191)->nullable();
            $table->json('input')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['session_id', 'created_at'], 'run_session_index');
            $table->index('trace_id', 'run_trace_index');
            $table->foreign('session_id')->references('id')->on('sessions')->cascadeOnDelete();
            $table->foreign('agent_resource_id')->references('id')->on('resources')->restrictOnDelete();
            $table->foreign('parent_run_id')->references('id')->on('runs')->nullOnDelete();
        });

        Schema::create('approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->ulid('tool_resource_id');
            $table->char('argument_digest', 64);
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['run_id', 'status'], 'approval_run_status_index');
            $table->foreign('run_id')->references('id')->on('runs')->cascadeOnDelete();
            $table->foreign('tool_resource_id')->references('id')->on('resources')->restrictOnDelete();
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('run_id');
            $table->unsignedBigInteger('sequence');
            $table->string('type', 128);
            $table->string('trace_id', 191)->nullable();
            $table->ulid('parent_run_id')->nullable();
            $table->json('data');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(['run_id', 'sequence'], 'event_sequence_unique');
            $table->index(['run_id', 'occurred_at'], 'event_run_occurred_index');
            $table->foreign('run_id')->references('id')->on('runs')->cascadeOnDelete();
        });

        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->ulid('tool_resource_id');
            $table->string('idempotency_key', 191);
            $table->string('lease_id', 191);
            $table->char('argument_digest', 64);
            $table->string('status', 32);
            $table->json('output')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'tool_resource_id', 'idempotency_key'], 'idempotency_unique');
            $table->foreign('run_id')->references('id')->on('runs')->cascadeOnDelete();
            $table->foreign('tool_resource_id')->references('id')->on('resources')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('events');
        Schema::dropIfExists('approvals');
        Schema::dropIfExists('runs');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('connectors');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('resources');
    }
};

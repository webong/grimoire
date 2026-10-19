<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_entries', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 32);
            $table->string('name', 191);
            $table->string('version', 64);
            $table->char('digest', 64);
            $table->json('spec');
            $table->string('status', 32)->default('draft');
            $table->timestampsTz();

            $table->unique(['kind', 'name', 'version'], 'catalog_entry_version_unique');
            $table->index('kind', 'catalog_entry_kind_index');
            $table->index('status', 'catalog_entry_status_index');
        });

        Schema::create('agent_clients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 64);
            $table->string('device_identifier', 191);
            $table->string('platform', 64)->nullable();
            $table->string('app_version', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->unique('device_identifier', 'agent_client_device_unique');
            $table->index('kind', 'agent_client_kind_index');
        });

        Schema::create('agent_devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('client_id')->nullable();
            $table->string('kind', 64);
            $table->string('label', 191);
            $table->json('capabilities')->nullable();
            $table->json('endpoints')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            $table->index('kind', 'agent_device_kind_index');
            $table->foreign('client_id')->references('id')->on('agent_clients')->nullOnDelete();
        });

        Schema::create('agent_connectors', function (Blueprint $table): void {
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

            $table->unique('name', 'agent_connector_name_unique');
            $table->index(['protocol', 'status'], 'agent_connector_discovery_index');
            $table->foreign('client_id')->references('id')->on('agent_clients')->nullOnDelete();
            $table->foreign('device_id')->references('id')->on('agent_devices')->nullOnDelete();
        });

        Schema::create('agent_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('subject_id', 191);
            $table->ulid('agent_resource_id');
            $table->string('status', 32)->default('ready');
            $table->json('state')->nullable();
            $table->timestampsTz();

            $table->index('subject_id', 'agent_session_subject_index');
            $table->foreign('agent_resource_id')->references('id')->on('catalog_entries')->restrictOnDelete();
        });

        Schema::create('agent_runs', function (Blueprint $table): void {
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

            $table->index(['session_id', 'created_at'], 'agent_run_session_index');
            $table->index('trace_id', 'agent_run_trace_index');
            $table->foreign('session_id')->references('id')->on('agent_sessions')->cascadeOnDelete();
            $table->foreign('agent_resource_id')->references('id')->on('catalog_entries')->restrictOnDelete();
            $table->foreign('parent_run_id')->references('id')->on('agent_runs')->nullOnDelete();
        });

        Schema::create('agent_approvals', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->ulid('tool_resource_id');
            $table->char('argument_digest', 64);
            $table->string('status', 32)->default('pending');
            $table->json('payload')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['run_id', 'status'], 'agent_approval_run_status_index');
            $table->foreign('run_id')->references('id')->on('agent_runs')->cascadeOnDelete();
            $table->foreign('tool_resource_id')->references('id')->on('catalog_entries')->restrictOnDelete();
        });

        Schema::create('agent_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->ulid('run_id');
            $table->unsignedBigInteger('sequence');
            $table->string('type', 128);
            $table->string('trace_id', 191)->nullable();
            $table->ulid('parent_run_id')->nullable();
            $table->json('data');
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(['run_id', 'sequence'], 'agent_event_sequence_unique');
            $table->index(['run_id', 'occurred_at'], 'agent_event_run_occurred_index');
            $table->foreign('run_id')->references('id')->on('agent_runs')->cascadeOnDelete();
        });

        Schema::create('agent_idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('run_id');
            $table->ulid('tool_resource_id');
            $table->string('idempotency_key', 191);
            $table->string('lease_id', 191);
            $table->char('argument_digest', 64);
            $table->string('status', 32);
            $table->json('output')->nullable();
            $table->timestampsTz();

            $table->unique(['run_id', 'tool_resource_id', 'idempotency_key'], 'agent_idempotency_unique');
            $table->foreign('run_id')->references('id')->on('agent_runs')->cascadeOnDelete();
            $table->foreign('tool_resource_id')->references('id')->on('catalog_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_idempotency_records');
        Schema::dropIfExists('agent_events');
        Schema::dropIfExists('agent_approvals');
        Schema::dropIfExists('agent_runs');
        Schema::dropIfExists('agent_sessions');
        Schema::dropIfExists('agent_connectors');
        Schema::dropIfExists('agent_devices');
        Schema::dropIfExists('agent_clients');
        Schema::dropIfExists('catalog_entries');
    }
};

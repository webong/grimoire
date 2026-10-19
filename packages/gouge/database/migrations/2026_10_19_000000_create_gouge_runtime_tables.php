<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('gouge.connection'));
        $schema->create('gouge_sessions', function (Blueprint $table) {
            $table->string('scope_id', 128);
            $table->string('session_id', 128);
            $table->text('definition_key');
            $table->string('active_run_id', 128)->nullable();
            $table->longText('history');
            $table->string('key', 64)->primary();
        });
        $schema->create('gouge_runs', function (Blueprint $table) {
            $table->string('scope_id', 128);
            $table->string('run_id', 128);
            $table->string('session_id', 128);
            $table->string('fingerprint', 64);
            $table->longText('request');
            $table->string('status', 32);
            $table->boolean('cancel_requested')->default(false);
            $table->longText('events');
            $table->string('key', 64)->primary();
            $table->string('session_key', 64);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection(config('gouge.connection'));
        $schema->dropIfExists('gouge_runs');
        $schema->dropIfExists('gouge_sessions');
    }
};

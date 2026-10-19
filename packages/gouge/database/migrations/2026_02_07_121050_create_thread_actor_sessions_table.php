<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('thread_actor_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('threads')->cascadeOnDelete();
            $table->foreignId('thread_actor_id')->constrained('thread_actors')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('conversation_id', 36)->nullable();
            $table->string('provider')->default('default');
            $table->string('model')->default('default');
            $table->json('state')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('agent_conversations')->nullOnDelete();
            $table->unique(['thread_actor_id', 'user_id', 'provider', 'model'], 'thread_actor_sessions_unique');
            $table->index(['thread_id', 'thread_actor_id', 'user_id'], 'thread_actor_sessions_thread_index');
            $table->index(['conversation_id'], 'thread_actor_sessions_conversation_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('thread_actor_sessions');
    }
};

<?php

use App\Http\Middleware\EnsureTokenAbility;
use App\Http\Middleware\EnsureTransportUser;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Webong\LaravelGouge\Ai\Gateways\Mcp\Servers\ComposeServer;
use Webong\LaravelGouge\Http\Controllers\Api\A2a\StreamController as A2aStreamController;
use Webong\LaravelGouge\Http\Controllers\Api\Acp\RpcController as AcpRpcController;
use Webong\LaravelGouge\Http\Controllers\Api\Acp\SessionController as AcpSessionController;
use Webong\LaravelGouge\Http\Controllers\Api\Acp\TaskController as AcpTaskController;
use Webong\LaravelGouge\Http\Controllers\Api\FormTurnsController;
use Webong\LaravelGouge\Http\Controllers\Api\PostInvocationController;
use Webong\LaravelGouge\Http\Controllers\Api\TaskController;
use Webong\LaravelGouge\Http\Middleware\EnsureA2aEnabled;
use Webong\LaravelGouge\Http\Middleware\EnsureA2aRpcAbility;
use Webong\LaravelGouge\Http\Middleware\NormalizeA2aRpcMethodNames;
use Webong\LaravelGouge\Http\Procedures\A2aProcedure;
use Webong\LaravelGouge\Http\Procedures\A2aTasksProcedure;
use Webong\LaravelGouge\Http\Procedures\A2aTasksPushNotificationConfigProcedure;

Route::prefix('posts')->middleware(['auth:sanctum,passport'])->group(function (): void {
    Route::post('/{post}/invocations', [PostInvocationController::class, 'store'])
        ->middleware(['api.ability:forms:submit', 'api.idempotent'])
        ->name('api.posts.invocations.store');
});

Route::prefix('tasks')->middleware(['auth:sanctum,passport'])->group(function (): void {
    Route::get('/{task}', [TaskController::class, 'show'])
        ->middleware('api.ability:invocations:read')
        ->name('api.tasks.show');
});

Route::prefix('form')->middleware(['auth:sanctum,passport'])->group(function (): void {
    Route::get('/{invocation}/turns', FormTurnsController::class)
        ->middleware('api.ability:invocations:read')
        ->name('api.form.turns.index');
});

Route::prefix('mcp')->middleware(['auth:sanctum,passport'])->group(function (): void {
    Mcp::web('/compose', ComposeServer::class)
        ->middleware([EnsureTransportUser::class, EnsureTokenAbility::class.':mcp:use']);
});

Route::prefix('acp')->middleware(['auth:sanctum,passport', EnsureTransportUser::class, EnsureTokenAbility::class.':acp:use'])->group(function (): void {
    Route::post('/rpc', AcpRpcController::class)->name('api.acp.rpc');
    Route::get('/sessions', [AcpSessionController::class, 'index'])->name('api.acp.sessions.index');
    Route::post('/sessions', [AcpSessionController::class, 'store'])->name('api.acp.sessions.store');
    Route::get('/sessions/{session}', [AcpSessionController::class, 'show'])->name('api.acp.sessions.show');
    Route::post('/sessions/{session}/prompt', [AcpSessionController::class, 'prompt'])->name('api.acp.sessions.prompt');
    Route::get('/tasks/{task}', [AcpTaskController::class, 'show'])->name('api.acp.tasks.show');
    Route::post('/tasks/{task}/cancel', [AcpTaskController::class, 'cancel'])->name('api.acp.tasks.cancel');
});

Route::prefix('a2a')->group(function (): void {
    Route::rpc('/rpc', [A2aProcedure::class, A2aTasksProcedure::class, A2aTasksPushNotificationConfigProcedure::class], '/')
        ->middleware([EnsureA2aEnabled::class, NormalizeA2aRpcMethodNames::class, 'auth:sanctum,passport', EnsureTransportUser::class, EnsureA2aRpcAbility::class])
        ->name('api.a2a.rpc');
    Route::post('/stream', A2aStreamController::class)
        ->middleware([EnsureA2aEnabled::class, NormalizeA2aRpcMethodNames::class, 'auth:sanctum,passport', EnsureTransportUser::class, EnsureA2aRpcAbility::class])
        ->name('api.a2a.stream');
    Route::webhooks('webhooks/push', 'a2a_push');
});

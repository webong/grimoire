<?php

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Webong\LaravelGouge\Http\Controllers\Api\A2a\AgentCardController;

Route::get('/.well-known/agent-card', AgentCardController::class)->name('a2a.agent-card');

Mcp::oauthRoutes();

<?php

use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\ChatwootSyncController;
use App\Http\Controllers\Api\ConversationAiDraftController;
use App\Http\Controllers\Api\ChatwootWebhookController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ConversationReplyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\IntegrationSettingsController;
use App\Http\Controllers\Api\KnowledgeDocumentController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::post('chatwoot/webhook', ChatwootWebhookController::class)->middleware('throttle:30,1');
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:30,1');

Route::middleware(['web', 'auth:web'])->group(function (): void {
    Route::get('me', [ProfileController::class, 'me']);
    Route::get('dashboard', [ProfileController::class, 'dashboard']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);

    Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'show', 'update']);

    Route::middleware(ResolveTenant::class)->group(function (): void {
        Route::get('integration-settings', [IntegrationSettingsController::class, 'show']);
        Route::patch('integration-settings', [IntegrationSettingsController::class, 'update']);
        Route::post('integration-settings/test', [IntegrationSettingsController::class, 'test'])->middleware('throttle:10,1');
        Route::post('chatwoot/sync', ChatwootSyncController::class)->middleware('throttle:10,1');
        Route::apiResource('tenant-users', TenantUserController::class)->only(['index', 'store', 'update']);
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('companies/{company}/logo', [CompanyController::class, 'uploadLogo']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('ai-agents', [AiAgentController::class, 'store']);
        Route::patch('ai-agents/{aiAgent}', [AiAgentController::class, 'update']);
        Route::post('conversations/{conversation}/ai-draft', ConversationAiDraftController::class)->middleware('throttle:20,1');
        Route::post('conversations/{conversation}/reply', ConversationReplyController::class)->middleware('throttle:20,1');
        Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('knowledge-documents/upload', [KnowledgeDocumentController::class, 'upload']);
        Route::post('knowledge-documents/index-text', [KnowledgeDocumentController::class, 'indexText']);
        Route::apiResource('knowledge-documents', KnowledgeDocumentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    });
});
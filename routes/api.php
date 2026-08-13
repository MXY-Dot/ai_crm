<?php

use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\ChatwootSyncController;
use App\Http\Controllers\Api\ConversationAiDraftController;
use App\Http\Controllers\Api\ChatwootWebhookController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ConversationAttachmentController;
use App\Http\Controllers\Api\ConversationReplyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\IntegrationSettingsController;
use App\Http\Controllers\Api\KnowledgeDocumentController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SuperAdminAnalyticsController;
use App\Http\Controllers\Api\SuperAdminBillingController;
use App\Http\Controllers\Api\SuperAdminCompanyController;
use App\Http\Controllers\Api\SuperAdminLlmProviderController;
use App\Http\Controllers\Api\SuperAdminOverviewController;
use App\Http\Controllers\Api\SuperAdminSupportController;
use App\Http\Controllers\Api\SuperAdminUserController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::post('chatwoot/webhook', ChatwootWebhookController::class)->middleware('throttle:30,1');
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:30,1');

Route::middleware(['web', 'auth:web'])->group(function (): void {
    Route::get('me', [ProfileController::class, 'me']);
    Route::get('dashboard', [ProfileController::class, 'dashboard']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::post('profile/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('profile/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('profile/2fa/disable', [TwoFactorController::class, 'disable']);

    Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'show', 'update']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::prefix('support')->group(function (): void {
        Route::get('tickets', [SupportTicketController::class, 'index']);
        Route::post('tickets', [SupportTicketController::class, 'store']);
        Route::get('tickets/{ticket}', [SupportTicketController::class, 'show']);
        Route::post('tickets/{ticket}/messages', [SupportTicketController::class, 'storeMessage']);
    });

    Route::middleware(EnsureSuperAdmin::class)->prefix('admin')->group(function (): void {
        Route::get('overview', [SuperAdminOverviewController::class, 'index']);
        Route::get('analytics', [SuperAdminAnalyticsController::class, 'index']);
        Route::get('billing/overview', [SuperAdminBillingController::class, 'index']);
        Route::get('llm-providers', [SuperAdminLlmProviderController::class, 'index']);
        Route::patch('llm-providers/primary', [SuperAdminLlmProviderController::class, 'updatePrimary']);
        Route::patch('llm-providers/{provider}/key', [SuperAdminLlmProviderController::class, 'updateKey']);
        Route::post('llm-providers/{provider}/test', [SuperAdminLlmProviderController::class, 'test'])->middleware('throttle:10,1');
        Route::get('companies/lookup', [SuperAdminCompanyController::class, 'lookup']);
        Route::get('companies', [SuperAdminCompanyController::class, 'index']);
        Route::post('companies', [SuperAdminCompanyController::class, 'store']);
        Route::get('companies/{tenant}', [SuperAdminCompanyController::class, 'show']);
        Route::patch('companies/{tenant}/status', [SuperAdminCompanyController::class, 'updateStatus']);
        Route::patch('companies/{tenant}/plan', [SuperAdminCompanyController::class, 'updatePlan']);
        Route::delete('companies/{tenant}', [SuperAdminCompanyController::class, 'destroy']);
        Route::get('users', [SuperAdminUserController::class, 'index']);
        Route::post('users', [SuperAdminUserController::class, 'store']);
        Route::get('users/{user}', [SuperAdminUserController::class, 'show']);
        Route::patch('users/{user}/status', [SuperAdminUserController::class, 'updateStatus']);
        Route::post('users/{user}/reset-password', [SuperAdminUserController::class, 'resetPassword']);
        Route::get('support/tickets', [SuperAdminSupportController::class, 'index']);
        Route::get('support/tickets/{ticket}', [SuperAdminSupportController::class, 'show']);
        Route::post('support/tickets/{ticket}/messages', [SuperAdminSupportController::class, 'storeMessage']);
        Route::patch('support/tickets/{ticket}/status', [SuperAdminSupportController::class, 'updateStatus']);
        Route::post('announcements', [SuperAdminSupportController::class, 'announce']);
    });

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
        Route::post('conversations/{conversation}/attachments', ConversationAttachmentController::class)->middleware('throttle:20,1');
        Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('knowledge-documents/upload', [KnowledgeDocumentController::class, 'upload']);
        Route::post('knowledge-documents/index-text', [KnowledgeDocumentController::class, 'indexText']);
        Route::apiResource('knowledge-documents', KnowledgeDocumentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    });
});
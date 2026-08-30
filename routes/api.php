<?php

use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\AiAnalyticsReportController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CancellationPolicyController;
use App\Http\Controllers\Api\ChatwootSyncController;
use App\Http\Controllers\Api\ConversationAiDraftController;
use App\Http\Controllers\Api\ChatwootWebhookController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FacebookWebhookController;
use App\Http\Controllers\Api\InstagramWebhookController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\ConversationAttachmentController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\ConversationReplyController;
use App\Http\Controllers\Api\ConversationTypingController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerFeedbackController;
use App\Http\Controllers\Api\EmergencySettingsController;
use App\Http\Controllers\Api\EmergencyStatusController;
use App\Http\Controllers\Api\IntegrationSettingsController;
use App\Http\Controllers\Api\KnowledgeDocumentController;
use App\Http\Controllers\Api\LanguageExampleController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MetaOAuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SuperAdminAnalyticsController;
use App\Http\Controllers\Api\SuperAdminBillingController;
use App\Http\Controllers\Api\SuperAdminCompanyController;
use App\Http\Controllers\Api\SuperAdminIncidentController;
use App\Http\Controllers\Api\SuperAdminLlmProviderController;
use App\Http\Controllers\Api\SuperAdminInsightsController;
use App\Http\Controllers\Api\SuperAdminLanguageQualityController;
use App\Http\Controllers\Api\SuperAdminBusinessModulesController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\CompanyModuleController;
use App\Http\Controllers\Api\PaymentGatewayWebhookController;
use App\Http\Controllers\Api\SuperAdminOverviewController;
use App\Http\Controllers\Api\SuperAdminSupportController;
use App\Http\Controllers\Api\SuperAdminUserController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantUserController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\VipCustomerController;
use App\Http\Controllers\Api\VipSettingsController;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\WidgetController;
use App\Http\Controllers\Api\WidgetSettingsController;
use App\Http\Controllers\Api\WidgetTokenController;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

Route::post('chatwoot/webhook', ChatwootWebhookController::class)->middleware('throttle:30,1');
Route::post('telegram/webhook', TelegramWebhookController::class)->middleware('throttle:30,1');

// Meta webhooks (WhatsApp/Instagram/Facebook) — one shared platform-level URL
// per provider (registered once in the Meta App dashboard, not per tenant);
// GET is Meta's subscribe-time verification handshake, POST is the actual
// event delivery. See FacebookWebhookController's docblock for why these
// can't carry a ?tenant_slug= the way Telegram's per-tenant bot URL does.
Route::match(['get', 'post'], 'whatsapp/webhook', WhatsAppWebhookController::class)->middleware('throttle:60,1');
Route::match(['get', 'post'], 'instagram/webhook', InstagramWebhookController::class)->middleware('throttle:60,1');
Route::match(['get', 'post'], 'facebook/webhook', FacebookWebhookController::class)->middleware('throttle:60,1');

// Public, unauthenticated — the payment gateway calls this directly, no
// X-Tenant-Id header possible. See PaymentGatewayWebhookController's docblock
// for how the {paymentId} in the URL (not a header) resolves the tenant.
Route::post('payments/{gateway}/webhook/{paymentId}', PaymentGatewayWebhookController::class)->middleware('throttle:60,1');

// Public, unauthenticated — a website visitor's browser, not a logged-in User.
// See WidgetController's docblock for the trust model (site key = public app id).
Route::prefix('widget/{siteKey}')->middleware('throttle:60,1')->group(function (): void {
    Route::get('appearance', [WidgetController::class, 'appearance']);
    Route::post('start', [WidgetController::class, 'start']);
    Route::post('messages', [WidgetController::class, 'send']);
    Route::get('messages', [WidgetController::class, 'index']);
    Route::post('attachments', [WidgetController::class, 'attachment']);
    Route::post('phone', [WidgetController::class, 'phone']);
});

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

    Route::prefix('onboarding')->group(function (): void {
        Route::get('business-types', [OnboardingController::class, 'businessTypes']);
        Route::post('complete', [OnboardingController::class, 'complete']);
        Route::post('integration-request', [OnboardingController::class, 'storeIntegrationRequest']);
    });

    Route::middleware(EnsureSuperAdmin::class)->prefix('admin')->group(function (): void {
        Route::get('overview', [SuperAdminOverviewController::class, 'index']);
        Route::get('analytics', [SuperAdminAnalyticsController::class, 'index']);
        Route::get('billing/overview', [SuperAdminBillingController::class, 'index']);
        Route::get('llm-providers', [SuperAdminLlmProviderController::class, 'index']);
        Route::get('insights/knowledge-gaps', [SuperAdminInsightsController::class, 'knowledgeGaps']);
        Route::get('insights/lost-leads', [SuperAdminInsightsController::class, 'lostLeads']);
        Route::get('language-quality', [SuperAdminLanguageQualityController::class, 'index']);
        Route::post('language-quality/system-prompts', [SuperAdminLanguageQualityController::class, 'storeSystemPrompt']);
        Route::patch('language-quality/system-prompts/{prompt}/activate', [SuperAdminLanguageQualityController::class, 'activateSystemPrompt']);
        Route::patch('language-quality/examples/{languageExample}/status', [SuperAdminLanguageQualityController::class, 'updateExampleStatus']);
        Route::post('language-quality/eval-examples', [SuperAdminLanguageQualityController::class, 'storeEvalExample']);
        Route::delete('language-quality/eval-examples/{aiEvalExample}', [SuperAdminLanguageQualityController::class, 'destroyEvalExample']);
        Route::post('language-quality/run-eval', [SuperAdminLanguageQualityController::class, 'runEval'])->middleware('throttle:5,10');
        Route::patch('language-quality/base-knowledge-document', [SuperAdminLanguageQualityController::class, 'updateBaseKnowledgeDocument']);

        Route::get('business-types', [SuperAdminBusinessModulesController::class, 'businessTypes']);
        Route::patch('business-types/{businessType}', [SuperAdminBusinessModulesController::class, 'updateBusinessType']);
        Route::get('integration-requests', [SuperAdminBusinessModulesController::class, 'integrationRequests']);
        Route::get('integration-requests/{integrationRequest}', [SuperAdminBusinessModulesController::class, 'showIntegrationRequest']);
        Route::patch('integration-requests/{integrationRequest}', [SuperAdminBusinessModulesController::class, 'updateIntegrationRequest']);
        Route::post('integration-requests/{integrationRequest}/messages', [SuperAdminBusinessModulesController::class, 'storeIntegrationRequestMessage']);
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
        Route::get('companies/{tenant}/vip-customers', [SuperAdminCompanyController::class, 'vipCustomers']);
        Route::post('companies/{tenant}/vip-customers/recalculate', [SuperAdminCompanyController::class, 'recalculateVip'])->middleware('throttle:5,1');
        Route::get('companies/{tenant}/vip-settings', [SuperAdminCompanyController::class, 'vipSettings']);
        Route::patch('companies/{tenant}/vip-settings', [SuperAdminCompanyController::class, 'updateVipSettings']);
        Route::get('companies/{tenant}/campaigns', [SuperAdminCompanyController::class, 'campaigns']);
        Route::get('companies/{tenant}/emergency-settings', [SuperAdminCompanyController::class, 'emergencySettings']);
        Route::patch('companies/{tenant}/emergency-settings', [SuperAdminCompanyController::class, 'updateEmergencySettings']);
        Route::get('companies/{tenant}/emergency-status', [SuperAdminCompanyController::class, 'emergencyStatus']);
        Route::patch('companies/{tenant}/emergency-override', [SuperAdminCompanyController::class, 'updateEmergencyOverride']);
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
        Route::get('incidents', [SuperAdminIncidentController::class, 'index']);
        Route::get('incidents/{incident}', [SuperAdminIncidentController::class, 'show']);
    });

    Route::middleware(ResolveTenant::class)->group(function (): void {
        Route::get('analytics', [AnalyticsController::class, 'index']);
        Route::get('analytics/knowledge-gaps', [AnalyticsController::class, 'knowledgeGaps']);
        Route::get('analytics/reports', [AiAnalyticsReportController::class, 'index']);
        Route::post('analytics/reports/generate', [AiAnalyticsReportController::class, 'generate'])->middleware('throttle:5,10');
        Route::get('integration-settings', [IntegrationSettingsController::class, 'show']);
        Route::patch('integration-settings', [IntegrationSettingsController::class, 'update']);
        Route::post('integration-settings/test', [IntegrationSettingsController::class, 'test'])->middleware('throttle:10,1');
        Route::get('emergency/status', [EmergencyStatusController::class, 'index']);
        Route::patch('emergency/override', [EmergencyStatusController::class, 'override']);
        Route::get('emergency/incidents/{incident}/missed', [EmergencyStatusController::class, 'missed']);
        Route::get('emergency-settings', [EmergencySettingsController::class, 'show']);
        Route::patch('emergency-settings', [EmergencySettingsController::class, 'update']);
        Route::get('vip-customers', [VipCustomerController::class, 'index']);
        Route::post('vip-customers/recalculate', [VipCustomerController::class, 'recalculate'])->middleware('throttle:5,1');
        Route::apiResource('campaigns', CampaignController::class)->only(['index', 'store', 'show']);
        Route::get('campaigns/{campaign}/audience', [CampaignController::class, 'audience']);
        Route::post('campaigns/draft-copy', [CampaignController::class, 'draftCopy']);
        Route::post('campaigns/{campaign}/submit-for-approval', [CampaignController::class, 'submitForApproval']);
        Route::post('campaigns/{campaign}/approve', [CampaignController::class, 'approve']);
        Route::post('campaigns/{campaign}/mark-sent', [CampaignController::class, 'markSent']);
        Route::get('vip-settings', [VipSettingsController::class, 'show']);
        Route::patch('vip-settings', [VipSettingsController::class, 'update']);
        Route::post('chatwoot/sync', ChatwootSyncController::class)->middleware('throttle:10,1');
        Route::get('widget-settings', [WidgetSettingsController::class, 'show']);
        Route::patch('widget-settings', [WidgetSettingsController::class, 'update']);
        Route::get('widget-tokens', [WidgetTokenController::class, 'index']);
        Route::post('widget-tokens', [WidgetTokenController::class, 'store']);
        Route::delete('widget-tokens/{widgetToken}', [WidgetTokenController::class, 'destroy']);
        Route::apiResource('tenant-users', TenantUserController::class)->only(['index', 'store', 'update']);
        Route::apiResource('companies', CompanyController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('companies/{company}/logo', [CompanyController::class, 'uploadLogo']);
        Route::get('company-modules', [CompanyModuleController::class, 'index']);
        Route::post('company-modules/toggle', [CompanyModuleController::class, 'toggle']);
        Route::get('customers/duplicates', [CustomerController::class, 'duplicates']);
        Route::post('customers/merge', [CustomerController::class, 'merge']);
        Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('customer-feedback', [CustomerFeedbackController::class, 'store']);
        Route::post('ai-agents', [AiAgentController::class, 'store']);
        Route::patch('ai-agents/{aiAgent}', [AiAgentController::class, 'update']);
        Route::delete('ai-agents/{aiAgent}', [AiAgentController::class, 'destroy']);
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::get('conversations/unread-total', [ConversationController::class, 'unreadTotal']);
        Route::patch('conversations/{conversation}/assignment', [ConversationController::class, 'assign']);
        Route::patch('conversations/{conversation}/labels', [ConversationController::class, 'labels']);
        Route::post('conversations/{conversation}/resolve', [ConversationController::class, 'resolve']);
        Route::get('conversations/{conversation}/analysis', [ConversationController::class, 'analysis']);
        Route::post('conversations/{conversation}/pin', [ConversationController::class, 'pin']);
        Route::delete('conversations/{conversation}/pin', [ConversationController::class, 'unpin']);
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'markRead']);
        Route::get('conversations/{conversation}/messages', [ConversationMessageController::class, 'index']);
        Route::get('conversations/{conversation}/typing', [ConversationTypingController::class, 'index']);
        Route::post('conversations/{conversation}/typing', [ConversationTypingController::class, 'heartbeat'])->middleware('throttle:30,1');
        Route::post('conversations/{conversation}/viewing', [ConversationTypingController::class, 'viewHeartbeat'])->middleware('throttle:30,1');
        Route::post('conversations/{conversation}/ai-draft', ConversationAiDraftController::class)->middleware('throttle:20,1');
        Route::post('conversations/{conversation}/reply', ConversationReplyController::class)->middleware('throttle:20,1');
        Route::post('conversations/{conversation}/attachments', ConversationAttachmentController::class)->middleware('throttle:20,1');
        Route::patch('messages/{message}', [MessageController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('messages/{message}', [MessageController::class, 'destroy'])->middleware('throttle:20,1');
        Route::apiResource('leads', LeadController::class)->only(['index', 'store', 'show', 'update']);
        Route::apiResource('language-examples', LanguageExampleController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('tasks', TaskController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('knowledge-documents/upload', [KnowledgeDocumentController::class, 'upload']);
        Route::post('knowledge-documents/index-text', [KnowledgeDocumentController::class, 'indexText']);
        Route::post('knowledge-documents/fetch-url', [KnowledgeDocumentController::class, 'fetchUrl']);
        Route::patch('knowledge-documents/{knowledgeDocument}/content', [KnowledgeDocumentController::class, 'updateContent']);
        Route::get('knowledge-documents/{knowledgeDocument}/file', [KnowledgeDocumentController::class, 'file']);
        Route::apiResource('knowledge-documents', KnowledgeDocumentController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

        // ТЗ раздел 10-19 (модуль салона) — услуги, специалисты, ресурсы, календарь и брони.
        Route::apiResource('services', ServiceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('resources', ResourceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::put('employees/{employee}/schedule', [EmployeeController::class, 'updateSchedule']);
        Route::put('employees/{employee}/services', [EmployeeController::class, 'updateServices']);
        Route::post('employees/{employee}/time-off', [EmployeeController::class, 'storeTimeOff']);
        Route::delete('employees/{employee}/time-off/{timeOff}', [EmployeeController::class, 'destroyTimeOff']);

        Route::get('cancellation-policy', [CancellationPolicyController::class, 'show']);
        Route::patch('cancellation-policy', [CancellationPolicyController::class, 'update']);

        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('bookings/availability', [BookingController::class, 'availability']);
        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('bookings/{booking}', [BookingController::class, 'show']);
        Route::patch('bookings/{booking}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
        Route::patch('bookings/{booking}/status', [BookingController::class, 'updateStatus']);
        Route::post('bookings/{booking}/payment-proof', [BookingController::class, 'storePaymentProof'])->middleware('throttle:20,1');
        Route::patch('bookings/{booking}/payment-proof/{paymentProof}', [BookingController::class, 'reviewPaymentProof']);
        Route::post('bookings/{booking}/gateway-payment', [BookingController::class, 'initiateGatewayPayment'])->middleware('throttle:20,1');

        Route::get('oauth/facebook/start', [MetaOAuthController::class, 'facebookStart'])->middleware('throttle:10,1');
        Route::get('oauth/instagram/start', [MetaOAuthController::class, 'instagramStart'])->middleware('throttle:10,1');
    });

    // Meta redirects the browser straight back here after the consent dialog —
    // it never carries our ?tenant_id= convention, only whatever we put in the
    // registered redirect_uri/state, so these sit outside ResolveTenant and
    // recover the tenant from the session MetaOAuthController::*Start() stashed.
    Route::get('oauth/facebook/callback', [MetaOAuthController::class, 'facebookCallback']);
    Route::get('oauth/facebook/pages', [MetaOAuthController::class, 'facebookPages']);
    Route::post('oauth/facebook/select-page', [MetaOAuthController::class, 'facebookSelectPage'])->middleware('throttle:10,1');
    Route::get('oauth/instagram/callback', [MetaOAuthController::class, 'instagramCallback']);
});
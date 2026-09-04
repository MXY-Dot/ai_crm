<?php

use App\Http\Controllers\Api\AiAgentController;
use App\Http\Controllers\Api\AiAnalyticsReportController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\BookingReportsController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CalendarController;
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
use App\Http\Controllers\Api\NotificationSettingsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\TableReservationController;
use App\Http\Controllers\Api\RoomReservationController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\RepairOrderController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\CourseGroupController;
use App\Http\Controllers\Api\EnrollmentController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TourDepartureController;
use App\Http\Controllers\Api\TourBookingController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\TrackShipmentController;
use App\Http\Controllers\Api\IntegrationApiKeyController;
use App\Http\Controllers\Api\ErpProductController;
use App\Http\Controllers\Api\ErpOrderController;
use App\Http\Controllers\Api\OrderReportsController;
use App\Http\Controllers\Api\ProductController;
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
use App\Http\Controllers\Auth\WelcomeSetupController;
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
use App\Http\Middleware\AuthenticateErpApiKey;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\EnsureEmailVerified;
use App\Http\Middleware\TrackLastSeen;
use App\Http\Controllers\Api\TeamMessageController;
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
// The bare {paymentId} route (no type segment) is the original Booking-only
// URL, already baked into every in-flight Booking checkout link -- left
// untouched. Order and room reservation payments use the new
// {type}/{paymentId} route below.
Route::post('payments/{gateway}/webhook/{paymentId}', PaymentGatewayWebhookController::class)
    ->where('paymentId', '[0-9]+')
    ->middleware('throttle:60,1');
Route::post('payments/{gateway}/webhook/{type}/{paymentId}', PaymentGatewayWebhookController::class)
    ->where(['type' => 'order|room_reservation', 'paymentId' => '[0-9]+'])
    ->middleware('throttle:60,1');

// Public, unauthenticated — ТЗ раздел 9 (Логистическая компания), a customer
// looking up their own package by tracking number. See TrackShipmentController's
// docblock for the trust model (tracking number = globally unique credential,
// no tenant context needed).
Route::get('track/{trackingNumber}', TrackShipmentController::class)->middleware('throttle:30,1');

// ТЗ раздел 9 — "Интеграция с CRM / 1С / складом". Authenticated by a
// Bearer token (AuthenticateErpApiKey), NOT the dashboard's session/
// X-Tenant-Id flow -- an external system (1C, a warehouse manager) proves
// which tenant it belongs to purely by which key it holds. See
// AuthenticateErpApiKey/IntegrationApiKey's own docblocks.
Route::prefix('erp')->middleware(['throttle:120,1', AuthenticateErpApiKey::class])->group(function (): void {
    Route::get('products', [ErpProductController::class, 'index']);
    Route::patch('products/{sku}/stock', [ErpProductController::class, 'updateStock']);
    Route::get('orders', [ErpOrderController::class, 'index']);
    Route::patch('orders/{order}/sync-status', [ErpOrderController::class, 'updateSyncStatus']);
});

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

Route::middleware(['web', 'auth:web', EnsureEmailVerified::class, TrackLastSeen::class])->group(function (): void {
    Route::get('me', [ProfileController::class, 'me']);
    Route::get('dashboard', [ProfileController::class, 'dashboard']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar']);
    Route::post('profile/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('profile/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('profile/2fa/disable', [TwoFactorController::class, 'disable']);

    Route::apiResource('tenants', TenantController::class)->only(['index', 'store', 'show', 'update']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notification-settings/status', [NotificationSettingsController::class, 'status']);
    Route::post('notification-settings/telegram-link-code', [NotificationSettingsController::class, 'telegramLinkCode']);
    Route::post('notification-settings/telegram-unlink', [NotificationSettingsController::class, 'telegramUnlink']);
    Route::post('notification-settings/test-email', [NotificationSettingsController::class, 'testEmail'])->middleware('throttle:5,1');
    Route::post('notification-settings/test-telegram', [NotificationSettingsController::class, 'testTelegram'])->middleware('throttle:5,1');
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

    Route::post('welcome-setup/complete', [WelcomeSetupController::class, 'complete']);

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
        Route::get('business-types/{businessType}', [SuperAdminBusinessModulesController::class, 'showBusinessType']);
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
        Route::get('analytics/export.xlsx', [AnalyticsController::class, 'exportXlsx']);
        Route::get('analytics/export.pdf', [AnalyticsController::class, 'exportPdf']);
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
        Route::patch('conversations/{conversation}/analysis', [ConversationController::class, 'updateAnalysis']);
        Route::get('conversations/{conversation}/sentiment-trajectory', [ConversationController::class, 'sentimentTrajectory']);
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
        Route::apiResource('branches', BranchController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('resources', ResourceController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::apiResource('employees', EmployeeController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::put('employees/{employee}/schedule', [EmployeeController::class, 'updateSchedule']);
        Route::put('employees/{employee}/services', [EmployeeController::class, 'updateServices']);
        Route::post('employees/{employee}/time-off', [EmployeeController::class, 'storeTimeOff']);
        Route::delete('employees/{employee}/time-off/{timeOff}', [EmployeeController::class, 'destroyTimeOff']);

        // Internal 1-on-1 team chat -- separate from the customer-facing Inbox,
        // no channel/lead/AI concept, just colleagues messaging each other.
        Route::get('team-messages/threads', [TeamMessageController::class, 'threads']);
        Route::get('team-messages/{colleague}', [TeamMessageController::class, 'messages']);
        Route::post('team-messages', [TeamMessageController::class, 'store']);
        Route::post('team-messages/attachments', [TeamMessageController::class, 'uploadAttachment'])->middleware('throttle:20,1');
        Route::patch('team-messages/{message}', [TeamMessageController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('team-messages/{message}', [TeamMessageController::class, 'destroy'])->middleware('throttle:20,1');

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
        Route::post('bookings/{booking}/mark-cash-paid', [BookingController::class, 'markCashPaid']);
        Route::post('bookings/{booking}/refund', [BookingController::class, 'refund']);
        Route::get('booking-reports', [BookingReportsController::class, 'index']);

        // Unified calendar (ТЗ раздел 9) — one feed across every reservation-shaped
        // module, see CalendarController's own docblock for the normalization contract.
        Route::get('calendar/modules', [CalendarController::class, 'modules']);
        Route::get('calendar/events', [CalendarController::class, 'events']);

        // Каталог + заказы + возвраты + доставка (module_key: catalog_products/orders/returns/delivery_tracking).
        Route::apiResource('products', ProductController::class)->only(['index', 'store', 'show', 'update', 'destroy']);

        Route::get('orders', [OrderController::class, 'index']);
        Route::get('order-returns', [OrderController::class, 'indexReturns']);
        Route::post('orders', [OrderController::class, 'store']);
        Route::get('orders/{order}', [OrderController::class, 'show']);
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus']);
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel']);
        Route::post('orders/{order}/return', [OrderController::class, 'storeReturn']);
        Route::patch('orders/{order}/return/{orderReturn}', [OrderController::class, 'reviewReturn']);
        Route::post('orders/{order}/return/{orderReturn}/refund', [OrderController::class, 'markReturnRefunded']);
        Route::patch('orders/{order}/delivery', [OrderController::class, 'updateDelivery']);
        Route::post('orders/{order}/payment-proof', [OrderController::class, 'storePaymentProof'])->middleware('throttle:20,1');
        Route::patch('orders/{order}/payment-proof/{paymentProof}', [OrderController::class, 'reviewPaymentProof']);
        Route::post('orders/{order}/gateway-payment', [OrderController::class, 'initiateGatewayPayment'])->middleware('throttle:20,1');
        Route::post('orders/{order}/mark-cash-paid', [OrderController::class, 'markCashPaid']);
        Route::post('orders/{order}/payment-refund', [OrderController::class, 'refundPayment']);
        Route::get('order-reports', [OrderReportsController::class, 'index']);

        // Ресторан и кафе (module_key: table_reservations) — tables themselves reuse the
        // Resource model/`resources` endpoint above (type=table); this is just the
        // reservation lifecycle. Меню/предзаказ/навынос/доставка reuse products/orders as-is.
        Route::get('table-reservations', [TableReservationController::class, 'index']);
        Route::get('table-availability', [TableReservationController::class, 'availability']);
        Route::post('table-reservations', [TableReservationController::class, 'store']);
        Route::get('table-reservations/{tableReservation}', [TableReservationController::class, 'show']);
        Route::patch('table-reservations/{tableReservation}/reschedule', [TableReservationController::class, 'reschedule']);
        Route::post('table-reservations/{tableReservation}/cancel', [TableReservationController::class, 'cancel']);
        Route::patch('table-reservations/{tableReservation}/status', [TableReservationController::class, 'updateStatus']);

        // Гостиница/хостел (module_key: room_reservations) — rooms themselves reuse the
        // Resource model/`resources` endpoint above (type=room, with price_per_night);
        // this is the reservation + full payment lifecycle, mirroring bookings' shape
        // above since a room reservation carries real money directly (unlike table
        // reservations, whose money flows through an order instead).
        Route::get('room-reservations', [RoomReservationController::class, 'index']);
        Route::get('room-availability', [RoomReservationController::class, 'availability']);
        Route::post('room-reservations', [RoomReservationController::class, 'store']);
        Route::get('room-reservations/{roomReservation}', [RoomReservationController::class, 'show']);
        Route::patch('room-reservations/{roomReservation}/reschedule', [RoomReservationController::class, 'reschedule']);
        Route::post('room-reservations/{roomReservation}/cancel', [RoomReservationController::class, 'cancel']);
        Route::patch('room-reservations/{roomReservation}/status', [RoomReservationController::class, 'updateStatus']);
        Route::post('room-reservations/{roomReservation}/payment-proof', [RoomReservationController::class, 'storePaymentProof'])->middleware('throttle:20,1');
        Route::patch('room-reservations/{roomReservation}/payment-proof/{paymentProof}', [RoomReservationController::class, 'reviewPaymentProof']);
        Route::post('room-reservations/{roomReservation}/gateway-payment', [RoomReservationController::class, 'initiateGatewayPayment'])->middleware('throttle:20,1');
        Route::post('room-reservations/{roomReservation}/mark-cash-paid', [RoomReservationController::class, 'markCashPaid']);
        Route::post('room-reservations/{roomReservation}/refund', [RoomReservationController::class, 'refund']);

        // Автосервис/автомойка (module_key: vehicle_service) -- vehicles are their own
        // simple CRUD (a customer's car, not a company-owned Resource); repair-order
        // billing (parts + labor, both just Products) reuses the existing Order/
        // OrderItem + payment module as-is via Order::repair_order_id, so no payment
        // endpoints live here at all.
        Route::apiResource('vehicles', VehicleController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('repair-orders', [RepairOrderController::class, 'index']);
        Route::post('repair-orders', [RepairOrderController::class, 'store']);
        Route::get('repair-orders/{repairOrder}', [RepairOrderController::class, 'show']);
        Route::patch('repair-orders/{repairOrder}/status', [RepairOrderController::class, 'updateStatus']);
        Route::patch('repair-orders/{repairOrder}/details', [RepairOrderController::class, 'updateDetails']);
        Route::post('repair-orders/{repairOrder}/cancel', [RepairOrderController::class, 'cancel']);

        // Учебный центр (module_key: course_scheduling) -- courses are simple CRUD
        // (a catalog offering, like Product); groups carry the real weekly schedule
        // and a real teacher/room double-booking guard; tuition billing reuses Order
        // as-is via Order::enrollment_id, same reuse pattern as repair_order_id.
        Route::apiResource('courses', CourseController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('course-groups', [CourseGroupController::class, 'index']);
        Route::post('course-groups', [CourseGroupController::class, 'store']);
        Route::get('course-groups/{courseGroup}', [CourseGroupController::class, 'show']);
        Route::patch('course-groups/{courseGroup}', [CourseGroupController::class, 'update']);
        Route::get('enrollments', [EnrollmentController::class, 'index']);
        Route::post('enrollments', [EnrollmentController::class, 'store']);
        Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show']);
        Route::post('enrollments/{enrollment}/complete', [EnrollmentController::class, 'complete']);
        Route::post('enrollments/{enrollment}/cancel', [EnrollmentController::class, 'cancel']);

        // Туристическая компания (module_key: tour_bookings) -- tours are simple CRUD
        // (a catalog offering, like Course); departures carry seats/dates with no
        // shared-resource conflict guard (unlike course groups, a departure doesn't
        // compete for a teacher/room); tour billing reuses Order as-is via
        // Order::tour_booking_id, same reuse pattern as enrollment_id.
        Route::apiResource('tours', TourController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
        Route::get('tour-departures', [TourDepartureController::class, 'index']);
        Route::post('tour-departures', [TourDepartureController::class, 'store']);
        Route::get('tour-departures/{tourDeparture}', [TourDepartureController::class, 'show']);
        Route::patch('tour-departures/{tourDeparture}', [TourDepartureController::class, 'update']);
        Route::get('tour-bookings', [TourBookingController::class, 'index']);
        Route::post('tour-bookings', [TourBookingController::class, 'store']);
        Route::get('tour-bookings/{tourBooking}', [TourBookingController::class, 'show']);
        Route::post('tour-bookings/{tourBooking}/confirm', [TourBookingController::class, 'confirm']);
        Route::post('tour-bookings/{tourBooking}/complete', [TourBookingController::class, 'complete']);
        Route::post('tour-bookings/{tourBooking}/cancel', [TourBookingController::class, 'cancel']);

        // Логистическая компания (module_key: shipment_tracking) -- staff-side CRUD
        // + status/tracking-event log. The public customer-facing lookup is the
        // unauthenticated `track/{trackingNumber}` route above, not here.
        Route::get('shipments', [ShipmentController::class, 'index']);
        Route::post('shipments', [ShipmentController::class, 'store']);
        Route::get('shipments/{shipment}', [ShipmentController::class, 'show']);
        Route::patch('shipments/{shipment}/status', [ShipmentController::class, 'updateStatus']);

        // ТЗ раздел 9 -- dashboard-side key management for the `/api/erp/*`
        // surface above (session-authenticated, unlike the ERP routes
        // themselves). No update() -- a key's name never changes, only its
        // active state, via destroy() (soft-revoke).
        Route::get('integration-api-keys', [IntegrationApiKeyController::class, 'index']);
        Route::post('integration-api-keys', [IntegrationApiKeyController::class, 'store']);
        Route::delete('integration-api-keys/{integrationApiKey}', [IntegrationApiKeyController::class, 'destroy']);

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
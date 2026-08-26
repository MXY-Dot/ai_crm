<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ТЗ раздел 4/5 -- credentials are NEVER stored here (see раздел 6, security:
 * OAuth/API-key/etc, requested only after status becomes 'agreed'). This
 * table is the request itself + WERO's review workflow, not the eventual
 * integration's live credentials/logs -- those are a separate future build
 * (IntegrationCredential/IntegrationLog from ТЗ раздел 24), deliberately not
 * built this round since nothing here has reached that stage yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform_name');
            $table->string('platform_url')->nullable();
            $table->string('plan_version')->nullable();
            $table->string('tech_contact')->nullable();
            $table->string('api_docs_url')->nullable();
            $table->json('data_to_receive')->nullable();
            $table->json('data_to_send')->nullable();
            $table->string('sync_frequency')->nullable();
            $table->text('scenario_description')->nullable();
            $table->text('comment')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status')->default('new')->index();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('cost_estimate', 10, 2)->nullable();
            $table->string('dev_time_estimate')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('source_type')->default('manual')->index();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('status')->default('queued')->index();
            $table->unsignedInteger('version')->default(1);
            $table->text('summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'company_id', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('content');
            $table->unsignedInteger('token_count')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->unique(['knowledge_document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
    }
};
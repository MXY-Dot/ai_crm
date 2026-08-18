<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `vector` extension is created out-of-band by a Postgres superuser
     * (the app's own DB role isn't a superuser and can't CREATE EXTENSION) —
     * see wero_pending_tasks.md's Stage 5 entry. This migration only adds the
     * column, which any role with table-alter rights can do once the type exists.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE knowledge_chunks DROP COLUMN embedding');
    }
};

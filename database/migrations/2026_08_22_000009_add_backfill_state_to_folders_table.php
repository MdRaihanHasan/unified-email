<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill progress lives on the folder rather than in the job payload, so a
        // half-finished walk survives a worker restart, a redeploy, or a queue flush.
        Schema::table('folders', function (Blueprint $table) {
            $table->string('backfill_cursor')->nullable()->after('unread_count');
            $table->timestamp('backfill_done_at')->nullable()->after('backfill_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn(['backfill_cursor', 'backfill_done_at']);
        });
    }
};

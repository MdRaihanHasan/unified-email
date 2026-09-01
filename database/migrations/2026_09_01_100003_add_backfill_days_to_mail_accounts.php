<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The 90-day import window was global, silent, and final. Per-account now:
// null follows the config default; 0 means full history. Widening it re-walks
// the mailbox with the larger window — safe, because every store is an upsert.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->unsignedInteger('backfill_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('backfill_days');
        });
    }
};

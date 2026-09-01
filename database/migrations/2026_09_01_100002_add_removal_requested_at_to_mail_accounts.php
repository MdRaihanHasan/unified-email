<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Removal became a queued job (deleting a 100k-message mailbox inside one HTTP
// request is a timeout with partial cleanup); this stamp is what lets the
// accounts page say "removing…" instead of showing a mailbox that half-exists.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->timestamp('removal_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn('removal_requested_at');
        });
    }
};

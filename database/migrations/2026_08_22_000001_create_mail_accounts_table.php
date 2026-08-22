<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');                        // "Work", "Personal", "Outlook"
            $table->string('provider');                     // App\Enums\Provider
            $table->string('email');
            $table->string('display_name')->nullable();

            // Encrypted at the model layer: refresh tokens, app passwords, IMAP host/port.
            $table->text('credentials')->nullable();

            // Provider-specific incremental cursor. Shape differs per provider:
            //   gmail_api -> {"historyId": "..."}
            //   imap      -> {"INBOX": {"uidvalidity": 1, "uidnext": 2}}
            //   graph     -> {"deltaLink": "https://..."}
            $table->jsonb('sync_cursor')->nullable();

            $table->string('status')->default('connecting'); // App\Enums\AccountStatus
            $table->text('last_error')->nullable();
            $table->timestamp('backfill_done_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('signature_html')->nullable();
            $table->timestamps();

            $table->unique(['email', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};

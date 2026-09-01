<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One poisoned message — a MIME shape the parser chokes on, a value Postgres
// rejects — used to wedge its whole account: the page retried five times and the
// scheduler re-kicked it forever. Failures now land here instead, the message is
// skipped, and mail:status says how many are waiting for a fix.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->text('provider_message_id');
            $table->text('error');
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_failures');
    }
};

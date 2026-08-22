<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();

            // The message being replied to / forwarded. Its rfc822_message_id and
            // references_ids are what we build In-Reply-To and References from.
            $table->foreignId('in_reply_to_message_id')->nullable()
                ->constrained('messages')->nullOnDelete();

            $table->string('type')->default('new');   // new|reply|reply_all|forward
            $table->jsonb('to_addrs')->nullable();
            $table->jsonb('cc_addrs')->nullable();
            $table->jsonb('bcc_addrs')->nullable();
            $table->text('subject')->nullable();
            $table->text('body_html')->nullable();
            $table->jsonb('attachments')->nullable(); // staged uploads, pre-send

            $table->string('status')->default('draft'); // App\Enums\OutboundStatus
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('sent_message_id')->nullable(); // provider id after send
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};

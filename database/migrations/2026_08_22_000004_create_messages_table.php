<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();

            $table->string('provider_message_id');            // Gmail id | IMAP UID | Graph id
            $table->string('provider_thread_id')->nullable(); // threadId | null | conversationId

            // RFC 5322 identity. Globally unique, so this is what makes cross-account
            // thread stitching possible.
            $table->string('rfc822_message_id')->nullable();
            $table->string('in_reply_to')->nullable();
            $table->jsonb('references_ids')->nullable();

            $table->jsonb('from_addr')->nullable();
            $table->jsonb('to_addrs')->nullable();
            $table->jsonb('cc_addrs')->nullable();
            $table->jsonb('bcc_addrs')->nullable();
            $table->jsonb('reply_to')->nullable();

            $table->text('subject')->nullable();
            $table->text('snippet')->nullable();
            $table->text('body_html')->nullable();
            $table->text('body_text')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();

            $table->boolean('is_read')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->boolean('is_answered')->default(false);

            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->jsonb('headers')->nullable();
            $table->timestamps();

            // The idempotency backstop for every sync path: re-running a sync, a
            // retried job, or a full resync can never insert the same message twice.
            $table->unique(['mail_account_id', 'provider_message_id']);

            $table->index('thread_id');
            $table->index('rfc822_message_id');
            $table->index('received_at');
            $table->index(['mail_account_id', 'received_at']);
        });

        // Postgres full-text search. 'simple' rather than 'english' because these
        // mailboxes are multilingual and stemming English over Bangla/other text
        // loses more than it gains.
        DB::statement(<<<'SQL'
            ALTER TABLE messages ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(subject, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(from_addr->>'address', '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(from_addr->>'name', '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(body_text, '')), 'C')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX messages_search_vector_index ON messages USING GIN (search_vector)');

        // Unread badge counts read this constantly; a partial index keeps it small.
        DB::statement('CREATE INDEX messages_unread_index ON messages (mail_account_id) WHERE is_read = false');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

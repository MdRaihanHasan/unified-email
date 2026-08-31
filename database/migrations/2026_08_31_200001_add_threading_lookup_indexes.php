<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ThreadResolver runs three lookups for every stored message, and none of them
 * had an index: the reverse-reference containment check was a sequential scan of
 * the whole messages table per message, so import cost grew quadratically with
 * mailbox size.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Tier 2: "has this account already stored a sibling of this thread id".
            $table->index(['mail_account_id', 'provider_thread_id'], 'messages_account_provider_thread_index');
        });

        // Tier 1 forward: candidates are matched by equality against in_reply_to.
        // A hash index rather than btree: the column was widened to text because
        // real headers overflow 255 bytes, and an oversized value would break a
        // btree insert — hashing sidesteps the size limit and equality is all the
        // resolver ever asks of it.
        DB::statement('CREATE INDEX IF NOT EXISTS messages_in_reply_to_hash ON messages USING hash (in_reply_to)');

        // Tier 1 reverse: whereJsonContains(references_ids, id) compiles to the
        // jsonb containment operator, which only a GIN index can serve.
        DB::statement('CREATE INDEX IF NOT EXISTS messages_references_ids_gin ON messages USING gin (references_ids jsonb_path_ops)');
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_account_provider_thread_index');
        });

        DB::statement('DROP INDEX IF EXISTS messages_in_reply_to_hash');
        DB::statement('DROP INDEX IF EXISTS messages_references_ids_gin');
    }
};

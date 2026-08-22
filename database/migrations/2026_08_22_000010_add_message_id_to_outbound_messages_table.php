<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            // The Message-ID we generated for the outgoing mail. The sent copy comes
            // back through ordinary sync from the Sent folder, and this is what ties
            // it to the send we performed.
            $table->string('rfc822_message_id')->nullable()->after('sent_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropColumn('rfc822_message_id');
        });
    }
};

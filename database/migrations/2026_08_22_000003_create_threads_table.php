<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A thread can span mail accounts: a reply that arrives on Workspace Gmail is
        // stitched to an original that arrived on Outlook via RFC References headers.
        // That is the whole point of a unified inbox, so there is no account_id here.
        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->text('subject')->nullable();
            $table->string('subject_normalized')->nullable(); // "Re:"/"Fwd:" stripped
            $table->text('snippet')->nullable();
            $table->jsonb('participants')->nullable();

            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->boolean('has_attachments')->default(false);
            $table->boolean('is_starred')->default(false);
            $table->timestamps();

            $table->index('last_message_at');
            $table->index('subject_normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads');
    }
};

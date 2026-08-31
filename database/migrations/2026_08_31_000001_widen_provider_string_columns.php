<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The first real backfill died on `value too long for varchar(255)` inserting an
// attachment: Gmail attachmentIds routinely run past 300 characters. Everything a
// provider hands us free-form goes to text; only our own enum-ish columns stay short.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->text('remote_id')->nullable()->change();
            $table->text('filename')->change();
            $table->text('mime_type')->nullable()->change();
            $table->text('content_id')->nullable()->change();
            $table->text('disk_path')->nullable()->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->text('rfc822_message_id')->nullable()->change();
            $table->text('in_reply_to')->nullable()->change();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->text('subject_normalized')->nullable()->change();
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->text('sent_message_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('remote_id')->nullable()->change();
            $table->string('filename')->change();
            $table->string('mime_type')->nullable()->change();
            $table->string('content_id')->nullable()->change();
            $table->string('disk_path')->nullable()->change();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('rfc822_message_id')->nullable()->change();
            $table->string('in_reply_to')->nullable()->change();
        });

        Schema::table('threads', function (Blueprint $table) {
            $table->string('subject_normalized')->nullable()->change();
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->string('sent_message_id')->nullable()->change();
        });
    }
};

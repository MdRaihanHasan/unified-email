<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_account_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id');            // Gmail label id | IMAP path | Graph folder id
            $table->string('name');
            $table->string('path')->nullable();     // IMAP hierarchy, e.g. "[Gmail]/Sent Mail"
            $table->string('role')->default('custom'); // App\Enums\FolderRole

            // Gmail models folders as labels, so one message can sit in many at once.
            // See message_folders.
            $table->boolean('is_label')->default(false);
            $table->boolean('is_selectable')->default(true);

            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamps();

            $table->unique(['mail_account_id', 'remote_id']);
            $table->index(['mail_account_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};

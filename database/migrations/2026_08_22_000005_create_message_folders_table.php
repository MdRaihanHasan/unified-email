<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Many-to-many, not messages.folder_id. Gmail treats folders as labels, so a
        // single message is legitimately in INBOX and two custom labels at once.
        // IMAP and Graph just always write exactly one row here.
        Schema::create('message_folders', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->constrained()->cascadeOnDelete();

            $table->primary(['message_id', 'folder_id']);
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_folders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('remote_id')->nullable();   // Gmail attachmentId | Graph id | IMAP part no
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->boolean('is_inline')->default(false);
            $table->string('content_id')->nullable();  // cid: reference for inline images
            $table->string('disk_path')->nullable();   // null until downloaded on demand
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};

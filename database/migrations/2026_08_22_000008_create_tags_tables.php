<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Local-only tags. Deliberately separate from provider labels/folders so
        // tagging never has to round-trip to Gmail or Graph.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->default('#6b7280');
            $table->timestamps();
        });

        Schema::create('message_tag', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['message_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_tag');
        Schema::dropIfExists('tags');
    }
};

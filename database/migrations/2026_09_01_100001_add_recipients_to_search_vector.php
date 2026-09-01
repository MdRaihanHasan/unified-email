<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Recipients were never in the search vector, so "mail I sent to anna" was
// unfindable by name. Generated columns cannot be altered in place; drop and
// re-add (the GIN index goes with the column). Table rewrite is fine at this size.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP COLUMN search_vector');

        DB::statement(<<<'SQL'
            ALTER TABLE messages ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', coalesce(subject, '')), 'A') ||
                setweight(to_tsvector('simple', coalesce(from_addr->>'address', '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(from_addr->>'name', '')), 'B') ||
                setweight(to_tsvector('simple', coalesce(body_text, '')), 'C') ||
                setweight(to_tsvector('simple', coalesce(to_addrs::text, '')), 'D')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX messages_search_vector_index ON messages USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP COLUMN search_vector');

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
    }
};

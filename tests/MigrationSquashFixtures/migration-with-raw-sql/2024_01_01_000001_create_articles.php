<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body');
            $table->integer('views')->default(0);
            $table->timestamps();
        });

        // PROBLEMATIC: Raw SQL statement - this should be detected by guards
        DB::statement('ALTER TABLE articles ADD COLUMN slug VARCHAR(255) UNIQUE AFTER title');

        // Another problematic one
        DB::unprepared('UPDATE articles SET views = 0 WHERE views IS NULL');
    }

    public function down(): void
    {
        // Cleanup
        DB::statement('ALTER TABLE articles DROP COLUMN slug');
        Schema::dropIfExists('articles');
    }
};

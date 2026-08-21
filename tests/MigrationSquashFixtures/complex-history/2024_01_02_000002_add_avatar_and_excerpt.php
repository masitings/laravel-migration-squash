<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add new column
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('name');
        });
        
        // Change column type
        Schema::table('posts', function (Blueprint $table) {
            $table->text('excerpt')->nullable()->after('title');
        });
        
        // Add index
        Schema::table('posts', function (Blueprint $table) {
            $table->index('status');
            $table->index(['created_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at', 'updated_at']);
            $table->dropColumn('excerpt');
        });
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};

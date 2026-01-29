<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Drop unique constraint on reference
            $table->dropUnique(['reference']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Add unique constraint back if needed
            $table->unique('reference');
        });
    }
};
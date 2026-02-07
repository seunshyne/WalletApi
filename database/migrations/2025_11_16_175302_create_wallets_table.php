<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('address', 10)->unique();
            $table->decimal('balance', 15, 2)->default(0.00);
            $table->string('currency', 10)->default('NGN');
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('address');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wallets');
    }
};
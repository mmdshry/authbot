<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('telegram_id')->unique();
            $table->string('phone')->nullable();
            $table->string('otp')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('verified')->default(false);
            $table->boolean('receive_notifications')->default(true);
            $table->json('notification_cryptos')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};

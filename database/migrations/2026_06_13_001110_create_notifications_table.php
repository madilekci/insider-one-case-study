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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('channel', 20)->index();
            $table->string('recipient');
            $table->text('content');
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 20)->default('queued')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->json('provider_response')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

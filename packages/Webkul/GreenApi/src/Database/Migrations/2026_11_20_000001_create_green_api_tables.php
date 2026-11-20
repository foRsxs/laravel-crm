<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('green_api_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id', 32);
            $table->string('chat_id', 100);
            $table->string('name')->nullable();
            $table->unsignedInteger('person_id')->nullable();
            $table->unsignedInteger('lead_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('mode', 16)->default('human');
            $table->boolean('opted_out')->default(false);
            $table->timestamps();
            $table->unique(['instance_id', 'chat_id']);
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('lead_id')->references('id')->on('leads')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('green_api_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('green_api_conversations')->cascadeOnDelete();
            $table->string('external_id', 191)->nullable();
            $table->uuid('client_reference')->nullable();
            $table->string('direction', 16);
            $table->string('type', 50)->default('textMessage');
            $table->text('body')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_name')->nullable();
            $table->string('status', 24)->default('received');
            $table->unsignedInteger('sent_by')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'external_id'], 'green_api_message_external_unique');
            $table->unique(['conversation_id', 'client_reference'], 'green_api_message_request_unique');
            $table->foreign('sent_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('green_api_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_key', 64)->unique();
            $table->string('instance_id', 32)->index();
            $table->string('external_id', 191)->nullable()->index();
            $table->string('type', 64);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('green_api_webhook_events');
        Schema::dropIfExists('green_api_messages');
        Schema::dropIfExists('green_api_conversations');
    }
};

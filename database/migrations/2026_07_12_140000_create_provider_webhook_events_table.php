<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('provider_webhook_events')) {
            Schema::create('provider_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 32);
                $table->string('external_event_id', 128);
                $table->string('event_type', 128)->nullable();
                $table->timestamp('received_at')->useCurrent();
                $table->timestamp('processed_at')->nullable();
                $table->string('processing_status', 32)->default('pending');
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'external_event_id']);
                $table->index(['provider', 'event_type']);
                $table->index(['processing_status', 'received_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhook_events');
    }
};

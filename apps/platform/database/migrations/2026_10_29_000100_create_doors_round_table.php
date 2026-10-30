<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('doorsRound', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticketId')->unique()->constrained('ticket')->restrictOnDelete();
            $table->foreignId('playerId')->constrained('player')->restrictOnDelete();
            $table->string('status', 16)->default('IN_PLAY');
            $table->text('boardEncrypted');
            $table->json('openedJson');
            $table->json('configJson');
            $table->string('economicsVersion', 30);
            $table->string('commitment', 64);
            $table->unsignedBigInteger('exposureKobo');
            $table->dateTime('expiresAt', 3);
            $table->dateTime('settledAt', 3)->nullable();
            $table->dateTime('createdAt', 3);
            $table->index(['status', 'expiresAt']);
            $table->index(['playerId', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('doorsRound'); }
};

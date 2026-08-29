<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Story 7.4 (REQ-HG-060..063) — itemNumber IS the primary key over 1-90, not
        // a separate auto-increment id: REQ-HG-061 wants "a stable, immutable,
        // one-to-one number-to-item mapping", and making the number the primary key
        // makes "renumbering" structurally the same operation as "deleting one row and
        // creating another with a different id" — exactly the operation
        // HeritageCatalogueService refuses once the first live ticket exists.
        Schema::create('heritageCatalogueItem', function (Blueprint $table) {
            $table->unsignedTinyInteger('itemNumber')->primary();
            $table->string('canonicalName', 100);
            $table->string('localName', 100)->nullable();
            $table->string('traditionOfOrigin', 30);
            $table->string('leaderApplicability', 10)->default('both')->comment('king | queen | both');
            $table->string('bodySlot', 10)->comment('head|neck|torso|waist|wrist|hand|feet — REQ-HG-063');
            $table->unsignedTinyInteger('layerPriority')->default(0);
            $table->text('culturalDescription')->nullable();
            // REQ-HG-064 — publication is refused without this. NULL here is the
            // honest default: no real cultural-advisor sign-off exists in this
            // codebase yet (see the seeder's own doc comment).
            $table->string('signOffRef', 100)->nullable();
            $table->string('depictionConstraint', 30)->nullable()->comment('none | abstraction_required | restricted');
            $table->string('assetRef', 200)->nullable()->comment('placeholder manifest pointer — artwork is deferred (PRD §9.4 scope note)');
            $table->dateTime('publishedAt')->nullable();
            $table->dateTime('createdAt')->useCurrent();

            $table->unique('canonicalName', 'uniq_heritageCatalogueItem_canonicalName');
            $table->index('traditionOfOrigin', 'idx_heritageCatalogueItem_tradition');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heritageCatalogueItem');
    }
};

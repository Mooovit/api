<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-011: per-team barcode registry attached to items. Scans today resolve
 * by raw item id (MV-027); this table lets real carrier codes (Code128,
 * EAN-128, QR…) map to items with server-side uniqueness scoped to the team.
 */
class CreateItemBarcodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('item_barcodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->uuid('team_id')->index();
            $table->string('code', 191);
            $table->string('type', 191)->nullable();
            $table->timestamps();

            /* Uniqueness is scoped to the team, enforced by the database */
            $table->unique(['team_id', 'code']);

            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('item_barcodes');
    }
}

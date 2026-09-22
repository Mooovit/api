<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-035: location barcodes — a nullable `barcode` on locations, binding
 * the physical locator stickers already on shelves.
 *
 * One optional code per location (single column, NOT a registry table).
 * Plain nullable string, no DB unique index: soft-deleted rows keep their
 * code reserved and the SQLite test suite makes `unique(team_id, barcode)`
 * fragile — uniqueness is enforced controller-level (409) like the item
 * barcode registry (API-011).
 */
class AddBarcodeToLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('barcode', 191)->nullable()->default(null);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('barcode');
        });
    }
}

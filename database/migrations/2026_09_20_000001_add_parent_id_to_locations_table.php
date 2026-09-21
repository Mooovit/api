<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-034: sub-locations — a nullable self-referencing `parent_id` on
 * locations ("Garage > Black shelf").
 *
 * Mirrors the `create_item_relations.php` pattern verbatim (uuid column,
 * self-FK, index) — that pattern already runs under the SQLite test suite
 * (the FK is skipped on SQLite ALTERs; integrity is enforced app-side by
 * validation + the cycle guard).
 */
class AddParentIdToLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->default(null)->index();
            $table->foreign('parent_id')->references('id')->on('locations');
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
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }
}

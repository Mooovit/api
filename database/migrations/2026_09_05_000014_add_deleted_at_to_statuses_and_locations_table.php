<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft deletes for statuses and locations.
 *
 * A hard delete erased the referenced row, so every History entry that
 * pointed at it (and every item still carrying the id) lost its meaning.
 * With a tombstone the row stays resolvable; the API index, the dumps
 * (BackupService) and the kanban all hide it through the default scope,
 * so devices see exactly the same list as before.
 */
class AddDeletedAtToStatusesAndLocationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}

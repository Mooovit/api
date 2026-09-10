<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * API-033 — revision-keyed item delta: `items.sync_revision` carries the
 * team's post-increment revision counter at the moment the row was last
 * written (create/update/move/assign/rename/pick/unpick/label change/
 * soft delete/restore + side-effect re-parents). `GET api/item?since_revision=N`
 * keys the delta on it — exact windows, no `updated_at` second-precision
 * re-delivery. Nullable: only rows not yet touched since this migration.
 */
class AddSyncRevisionToItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('sync_revision')->nullable()->after('picked_at');
        });

        /* Backfill: existing rows (live + trashed) start at their team's
           current counter, so the first use of a revision cursor — the
           revision a client just pulled a full list with — cannot strand
           pre-existing rows behind it. */
        DB::statement(
            'UPDATE items SET sync_revision = (SELECT revision FROM teams WHERE teams.id = items.team_id)'
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('sync_revision');
        });
    }
}

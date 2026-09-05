<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-003: per-team revision counter.
 *
 * Bumped atomically on every team-scoped write (items, statuses, locations,
 * labels, item-label attach/detach) so clients can poll one integer
 * (GET api/revision / X-Revision header) instead of downloading the full
 * list to detect changes.
 */
class AddRevisionToTeamsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedBigInteger('revision')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('revision');
        });
    }
}

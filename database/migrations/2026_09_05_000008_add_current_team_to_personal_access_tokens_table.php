<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-015: per-device (per-token) current team.
 *
 * A Sanctum token may carry its own current team — the default stays the
 * token owner's current team (null), so existing tokens behave exactly as
 * before. The device (the PDA holding the token) switches itself through
 * `POST api/device-team`.
 */
class AddCurrentTeamToPersonalAccessTokensTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('current_team_id')->nullable()->index();
            $table->foreign('current_team_id')
                ->references('id')->on('teams')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropForeign(['current_team_id']);
            $table->dropColumn('current_team_id');
        });
    }
}

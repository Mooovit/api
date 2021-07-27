<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateItemRelations extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /* We update the items table */
        Schema::table('items', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->default(null);
            $table->uuid('team_id')->index()->nullable();
            $table->uuid('location_id')->index()->nullable();
            $table->uuid('status_id')->index()->nullable();
            $table->foreign('team_id')->references('id')->on('teams');
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('status_id')->references('id')->on('statuses');
            $table->foreign('parent_id')->references('id')->on('items');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('parent_id');
            $table->dropColumn('team_id');
            $table->dropColumn('location_id');
            $table->dropColumn('status_id');
        });
    }
}

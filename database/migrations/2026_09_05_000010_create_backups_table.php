<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-018: savepoint backups — one zip of CSV dumps (items/locations/
 * statuses/labels) per team, stored on a Storage disk (`disk` + unique
 * `path`); `user_id` records the creator ("this backup belongs to that
 * user"); the per-entity counts mirror the archive contents.
 */
class CreateBackupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('user_id')->index();
            $table->string('disk', 32)->default('local');
            $table->string('path')->unique();
            $table->unsignedInteger('size');
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('location_count');
            $table->unsignedInteger('status_count');
            $table->unsignedInteger('label_count');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('backups');
    }
}

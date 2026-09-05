<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-021: flag backups whose zip was copied off to the team's S3 bucket.
 * Nullable: rows from before this flag predate offload entirely.
 */
class AddRemoteToBackupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->boolean('remote')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('backups', function (Blueprint $table) {
            $table->dropColumn('remote');
        });
    }
}

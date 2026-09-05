<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-021: per-team S3 offload credentials. One config per team (unique
 * `team_id`); `secret_key` is stored encrypted at rest (Crypt — the
 * `encrypted` cast on the model); `endpoint` is optional for S3-compatible
 * stores; `prefix` (nullable — computed default `backups/{team_id}`).
 */
class CreateTeamS3ConfigsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('team_s3_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->unique();
            $table->string('bucket', 255);
            $table->string('region', 64);
            $table->string('access_key', 255);
            $table->text('secret_key'); /* encrypted at rest */
            $table->string('endpoint', 255)->nullable();
            $table->string('prefix', 255)->nullable();
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('team_s3_configs');
    }
}

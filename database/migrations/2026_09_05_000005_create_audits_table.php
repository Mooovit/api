<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-010: audit/stocktake snapshots per location. `payload` keeps the exact
 * MV-050 report structure ({found_ids, extra, unknown_codes}) so the Android
 * submit is a near-free serialization of its local report.
 */
class CreateAuditsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('location_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedInteger('found_count');
            $table->unsignedInteger('missing_count')->default(0);
            $table->unsignedInteger('extra_count')->default(0);
            $table->json('payload');
            $table->timestamps();

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
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
        Schema::dropIfExists('audits');
    }
}

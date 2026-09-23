<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-037: batch sync — one offline push from a phone becomes ONE row here
 * (batch id + who/where) and ONE row per submitted operation in
 * `sync_batch_operations`. The totals are the reconciliation counters the
 * GET endpoint echoes back; `finished_at` marks a fully-dispatched batch.
 *
 * Audit tables: cascade on team/user delete, no SoftDeletes, and they are
 * deliberately NOT watched by the team-revision observer (writing the
 * receipt must not bump the sync cursor).
 */
class CreateSyncBatchesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('user_id')->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('applied')->default(0);
            $table->unsignedInteger('conflicted')->default(0);
            $table->unsignedInteger('noop')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);

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
        Schema::dropIfExists('sync_batches');
    }
}

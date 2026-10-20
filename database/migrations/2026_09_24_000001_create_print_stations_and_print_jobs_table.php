<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-039: server-side DYMO print queue.
 *
 * `print_stations` — workstations that declared a printer to the server. A
 * browser generates a `client_id` (localStorage) and heart-beats `last_seen_at`
 * via its claim polling; a station is "live" while that stamp is fresh (90s
 * window, App\Support\PrintQueue). Upsert identity is [team_id, client_id].
 *
 * `print_jobs` — one row per requested sticker. Statuses: pending (waiting for
 * a station), claimed (a station took it, printing), printed, failed. Jobs
 * persist until printed/failed — there is no TTL in v1. `target_printer` is
 * the optional DYMO printer name a pusher demanded; null means any live station
 * may claim it. Enqueueing against a printer with no live station fails with a
 * 409 (controller-level; no DB constraint).
 *
 * Plumbing tables: cascade on team delete, no SoftDeletes, deliberately NOT
 * watched by the team-revision observer (printing must not bump the sync
 * cursor) — same decision as the sync_batches audit tables (API-037).
 */
class CreatePrintStationsAndPrintJobsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('print_stations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('client_id')->index();
            $table->string('printer_name');
            $table->string('label')->nullable();
            $table->string('template', 8)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'client_id']);

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('item_id')->index();
            $table->string('template', 8);
            $table->string('target_printer')->nullable();
            $table->string('status', 16)->default('pending');
            $table->uuid('claimed_by')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['team_id', 'status', 'created_at']);

            $table->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('item_id')->references('id')->on('items')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_stations');
    }
}

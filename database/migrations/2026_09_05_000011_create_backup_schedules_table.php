<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-020: auto-backup schedules per team. One schedule per team (unique
 * `team_id`); `user_id` is the configurator (provenance); the scheduler
 * command picks up `enabled` rows whose `next_run_at` (indexed) is due.
 */
class CreateBackupSchedulesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('team_id')->unique();
            $table->uuid('user_id')->index();
            $table->string('frequency', 16); // daily | weekly | monthly | yearly
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
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
        Schema::dropIfExists('backup_schedules');
    }
}

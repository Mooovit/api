<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-037: one submitted sync-batch operation + its server-side outcome.
 *
 * `payload` keeps the exact submission (op-specific fields + `base_revision`
 * + per-field `base` values) so a GET can replay what the phone claimed;
 * `result` is one of applied|conflict|noop|error and `detail` carries the
 * per-op outcome (conflict fields, detached_ids, error code…).
 */
class CreateSyncBatchOperationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sync_batch_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->index();
            $table->string('op');
            $table->unsignedInteger('index');
            $table->uuid('item_id')->nullable()->default(null)->index();
            $table->json('payload');
            $table->string('result');
            $table->json('detail')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['batch_id', 'index']);

            $table->foreign('batch_id')->references('id')->on('sync_batches')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sync_batch_operations');
    }
}

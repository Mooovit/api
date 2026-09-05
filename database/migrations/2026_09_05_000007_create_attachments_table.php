<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-013: image attachments on items. Binary lives on a Storage disk
 * (`disk` + unique `path`); the row is metadata only.
 */
class CreateAttachmentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->index();
            $table->uuid('team_id')->index();
            $table->uuid('user_id')->index();
            $table->string('disk', 32)->default('local');
            $table->string('path')->unique();
            $table->string('original_name')->nullable();
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->string('caption')->nullable();
            $table->timestamps();

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
        Schema::dropIfExists('attachments');
    }
}

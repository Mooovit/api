<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-024: public share links for items. Each item has at most one link row
 * (unique `item_id`); the `token` is the random capability carried by the
 * public URL — never derived from the item id. Deactivating stamps
 * `deactivated_at` (the row stays), and re-activating issues a FRESH token,
 * so a revoked URL can never come back to life.
 */
class CreateItemShareLinksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('item_share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('item_id')->unique();
            $table->uuid('team_id')->index();
            $table->string('token', 64)->unique();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
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
        Schema::dropIfExists('item_share_links');
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API-032: picked state — a nullable `picked_at` timestamp marks an item as
 * TEMPORARILY out of its box ("temporary pick, I'll put it back later"),
 * without touching `parent_id` (containment is unchanged; this is a
 * soft/ephemeral state, deletion keeps its own API-005/008 semantics).
 * null = in box. Serialized as-is by the item payloads (ISO-8601 via the
 * model cast) and mutated only by the pick/unpick intent verbs.
 */
class AddPickedAtToItemsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('items', function (Blueprint $table) {
            $table->timestamp('picked_at')->nullable()->after('parent_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('picked_at');
        });
    }
}

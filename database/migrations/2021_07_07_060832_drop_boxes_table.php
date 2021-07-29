<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DropBoxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /* We update the items table */
        Schema::table('items', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign('items_box_id_foreign');
                $table->dropIndex('items_box_id_index');
            }
            $table->dropColumn('box_id');
        });

        /* We drop the table boxes */
        Schema::dropIfExists('boxes');
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     * @throws Exception
     */
    public function down()
    {
        throw new Exception("This migration cannot be reversed");
    }
}

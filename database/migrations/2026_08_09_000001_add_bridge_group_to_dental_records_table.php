<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('dental_records', function (Blueprint $table) {
            $table->string('bridge_group_id')->nullable()->after('condition');
            $table->index('bridge_group_id');
        });
    }

    public function down()
    {
        Schema::table('dental_records', function (Blueprint $table) {
            $table->dropIndex(['bridge_group_id']);
            $table->dropColumn('bridge_group_id');
        });
    }
};

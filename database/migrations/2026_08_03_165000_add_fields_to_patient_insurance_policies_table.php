<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('patient_insurance_policies', function (Blueprint $table) {
            $table->string('subscriber_id')->after('insurance_policy_id');
            $table->string('group_number')->nullable()->after('subscriber_id');
            $table->enum('relationship_to_subscriber', ['self', 'spouse', 'child', 'other'])->default('self')->after('group_number');
            $table->string('subscriber_name')->nullable()->after('relationship_to_subscriber');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patient_insurance_policies', function (Blueprint $table) {
            $table->dropColumn([
                'subscriber_id',
                'group_number',
                'relationship_to_subscriber',
                'subscriber_name',
            ]);
        });
    }
};

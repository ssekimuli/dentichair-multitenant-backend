<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('invoices')) {
            DB::table('invoices')
                ->where(function($q) {
                    $q->whereNull('category')
                      ->orWhere('category', '!=', 'radiology-income');
                })
                ->where(function($q) {
                    if (Schema::hasTable('xray_requests')) {
                        $q->whereExists(function($sub) {
                            $sub->select(DB::raw(1))
                                ->from('xray_requests')
                                ->whereColumn('xray_requests.invoice_id', 'invoices.id');
                        });
                    }
                    if (Schema::hasTable('invoice_other_charges')) {
                        $q->orWhereExists(function($sub) {
                            $sub->select(DB::raw(1))
                                ->from('invoice_other_charges')
                                ->whereColumn('invoice_other_charges.invoice_id', 'invoices.id')
                                ->where(function($oq) {
                                    $oq->where('description', 'LIKE', '%Radiology%')
                                       ->orWhere('description', 'LIKE', '%X-Ray%');
                                });
                        });
                    }
                })
                ->update([
                    'category' => 'radiology-income',
                    'status' => 'Paid',
                    'paid_amount' => DB::raw('CASE WHEN paid_amount <= 0 THEN amount ELSE paid_amount END'),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op
    }
};
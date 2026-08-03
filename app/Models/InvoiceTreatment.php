<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceTreatment extends Model
{
    protected $fillable = [
        'invoice_id', 'procedure', 'procedure_id', 'cost', 'quantity', 'unit_price', 'total_amount',
        'covered_amount', 'insurance_amount', 'patient_amount', 'excluded_amount',
        'coverage_percentage', 'coverage_rule_snapshot', 'pre_authorization_number'
    ];

    protected $casts = [
        'covered_amount' => 'decimal:2',
        'insurance_amount' => 'decimal:2',
        'patient_amount' => 'decimal:2',
        'excluded_amount' => 'decimal:2',
        'coverage_percentage' => 'decimal:2',
        'coverage_rule_snapshot' => 'array',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

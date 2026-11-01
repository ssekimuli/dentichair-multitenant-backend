<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'service_id',
        'treatment_plan_item_id',
        'lab_order_item_id',
        'item_type',
        'description',
        'tooth_number',
        'material',
        'shade',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'expected_delivery_date',
        'special_instructions',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'expected_delivery_date' => 'date',
        'sort_order' => 'integer',
    ];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function treatmentPlanItem(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_item_id');
    }

    public function labOrderItem(): BelongsTo
    {
        return $this->belongsTo(LaboratoryOrder::class, 'lab_order_item_id');
    }
}


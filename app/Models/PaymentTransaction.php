<?php

namespace App\Models;

use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTransaction extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'insurance_claim_id',
        'payer_type',
        'payer_id',
        'amount',
        'method',
        'date',
        'client_uuid',
        'qb_payment_id',
        'qb_charge_id',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

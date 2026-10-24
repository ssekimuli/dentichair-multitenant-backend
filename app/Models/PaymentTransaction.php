<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use LogsActivity;
    protected $fillable = ['amount', 'method', 'date', 'client_uuid'];
}

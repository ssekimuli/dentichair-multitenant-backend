<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceOtherCharge extends Model
{
protected $fillable = ['invoice_id', 'description', 'amount'];
}

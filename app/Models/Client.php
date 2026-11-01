<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
    ];

    public function quotations()
    {
        return $this->hasMany(Quotation::class, 'lab_client_id');
    }

    public function laboratoryOrders()
    {
        return $this->hasMany(LaboratoryOrder::class, 'client_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'client_id');
    }
}

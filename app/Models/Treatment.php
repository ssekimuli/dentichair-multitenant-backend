<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Treatment extends Model
{
    use LogsActivity;
    use HasFactory;
    protected $fillable = ['treatment_code', 'procedure', 'cost', 'category'];

    protected $casts = [
        'cost' => 'decimal:2',
    ];
}
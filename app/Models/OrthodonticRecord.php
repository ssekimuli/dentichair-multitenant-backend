<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Model;

class OrthodonticRecord extends Model
{
    use LogsActivity;
    protected $fillable = [
        'patient_id',
        'date',
        'appliance',
        'appliance_location',
        'treatment_phase',
        'amount',
        'period',
        'procedures',
        'invoice_id',
        'medical_history_id',
        'wire_size',
        'wire_size_upper',
        'wire_size_lower',
        'archwire_material',
        'elastics',
        'compliance',
        'oral_hygiene',
        'bracket_changes',
        'broken_brackets',
        'notes',
        'next_visit_goals',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'bracket_changes' => 'array',
        'broken_brackets' => 'array',
    ];

    protected $appends = ['months_in_treatment'];

    public function patient()
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function medicalHistory()
    {
        return $this->belongsTo(MedicalHistory::class, 'medical_history_id');
    }

    public function getMonthsInTreatmentAttribute()
    {
        if (!$this->patient_id) return 0;
        
        static $firstDates = [];
        if (!isset($firstDates[$this->patient_id])) {
            $firstDates[$this->patient_id] = static::where('patient_id', $this->patient_id)->min('date');
        }
        
        if (!$firstDates[$this->patient_id]) return 0;
        
        $start = \Carbon\Carbon::parse($firstDates[$this->patient_id]);
        // Use the current record's date to show the months in treatment at that specific visit
        $current = \Carbon\Carbon::parse($this->date);
        
        return max(0, $start->diffInMonths($current));
    }
}

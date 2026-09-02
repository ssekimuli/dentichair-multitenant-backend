<?php

namespace App\Models;

use App\Traits\LogsActivity;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RestorativeRecord extends Model
{
    use LogsActivity;
    use HasFactory;

    protected $fillable = [
        'patient_id',
        'tooth_id',
        'surface',
        'material',
        'notes',
        'procedure_cost',
        'material_cost',
        'other_charges',
    ];

    protected $casts = [
        'procedure_cost' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'other_charges' => 'decimal:2',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function treatmentPlan(): HasOne
    {
        return $this->hasOne(TreatmentPlan::class, 'restorative_record_id');
    }

    protected static function booted(): void
    {
        static::created(function (RestorativeRecord $record) {
            $record->createTreatmentPlanEntry();
        });

        static::updated(function (RestorativeRecord $record) {
            if ($record->isDirty(['tooth_id', 'surface', 'material', 'notes', 'procedure_cost', 'material_cost', 'other_charges'])) {
                $record->updateTreatmentPlanEntry();
            }
        });

        static::deleted(function (RestorativeRecord $record) {
            $record->deleteTreatmentPlanEntry();
        });
    }

    /**
     * Auto-create corresponding treatment plan entry
     */
    public function createTreatmentPlanEntry(): void
    {
        if ($this->treatmentPlan) {
            return;
        }

        $procedure = $this->getProcedureName();
        $cost = $this->getEstimatedCost();
        $patientBranchId = $this->patient->branch_id;
        $doctorId = auth()->id() ?? auth('api')->id();

        TreatmentPlan::create([
            'patient_id' => $this->patient_id,
            'restorative_record_id' => $this->id,
            'procedure' => $procedure,
            'tooth' => $this->tooth_id,
            'cost' => $cost,
            'status' => 'Proposed',
            'branch_id' => $patientBranchId,
            'doctor_id' => $doctorId,
            'notes' => $this->notes,
            'date' => now()->format('Y-m-d'),
        ]);

        \App\Models\ActivityLog::log(
            'Restorative Record Added',
            "Restorative treatment added as proposed treatment for tooth #{$this->tooth_id}",
            ['patient_id' => $this->patient_id, 'restorative_record_id' => $this->id]
        );
    }

    /**
     * Update corresponding treatment plan entry
     */
    public function updateTreatmentPlanEntry(): void
    {
        $treatmentPlan = $this->treatmentPlan;
        if (!$treatmentPlan) {
            return;
        }

        $treatmentPlan->update([
            'procedure' => $this->getProcedureName(),
            'tooth' => $this->tooth_id,
            'cost' => $this->getEstimatedCost(),
            'notes' => $this->notes,
        ]);

        \App\Models\ActivityLog::log(
            'Restorative Record Updated',
            "Restorative treatment updated for tooth #{$this->tooth_id}",
            ['patient_id' => $this->patient_id, 'restorative_record_id' => $this->id]
        );
    }

    /**
     * Delete corresponding treatment plan entry
     */
    public function deleteTreatmentPlanEntry(): void
    {
        if ($this->treatmentPlan) {
            $this->treatmentPlan->delete();

            \App\Models\ActivityLog::log(
                'Restorative Record Deleted',
                "Restorative treatment removed from treatment plan",
                ['patient_id' => $this->patient_id, 'restorative_record_id' => $this->id]
            );
        }
    }

    /**
     * Generate procedure name from restorative details
     */
    private function getProcedureName(): string
    {
        $parts = [];

        if ($this->tooth_id) {
            $parts[] = "Tooth #" . $this->tooth_id;
        }

        if ($this->surface) {
            $parts[] = ucfirst($this->surface);
        }

        if ($this->material) {
            $parts[] = $this->material;
        }

        if (!empty($parts)) {
            return implode(' - ', $parts) . ' Restoration';
        }

        return 'Restorative Treatment';
    }

    /**
     * Total cost for the treatment plan entry: the sum of the
     * procedure, material, and other charges submitted with the
     * record. Falls back to a material-based estimate when no
     * cost breakdown was provided.
     */
    private function getEstimatedCost(): float
    {
        $totalCost = (float) ($this->procedure_cost ?? 0)
            + (float) ($this->material_cost ?? 0)
            + (float) ($this->other_charges ?? 0);

        if ($totalCost > 0) {
            return $totalCost;
        }

        return $this->getEstimatedCostFromMaterial();
    }

    /**
     * Get estimated cost based on material type
     */
    private function getEstimatedCostFromMaterial(): float
    {
        $materialCosts = [
            'amalgam' => 150,
            'composite' => 200,
            'glass ionomer' => 120,
            'resin' => 180,
            'compomer' => 140,
        ];

        if (!$this->material) {
            return 0.0;
        }

        $material = strtolower($this->material);

        foreach ($materialCosts as $key => $cost) {
            if (str_contains($material, $key)) {
                return (float) $cost;
            }
        }

        return 0.0;
    }
}

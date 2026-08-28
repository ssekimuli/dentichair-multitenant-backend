# Integration: Restorative Records to Treatment Plan

## Problem
When a restorative procedure is added (via RestorativeRecord), it doesn't appear in the treatment plan as a proposed treatment. Currently, treatments can only be manually added to a treatment plan.

## Solution
Create a system to automatically generate treatment plan entries from restorative records, allowing them to appear as proposed treatments.

---

## Implementation Plan

### Phase 1: Database & Relationships

#### 1.1 Add Relationship to RestorativeRecord
```php
// RestorativeRecord.php
public function treatmentPlans()
{
    return $this->morphMany(TreatmentPlan::class, 'source');
    // or use a foreign key
    return $this->hasMany(TreatmentPlan::class, 'restorative_record_id');
}
```

#### 1.2 Update TreatmentPlan Migration
Add nullable field:
```sql
ALTER TABLE treatment_plans ADD COLUMN restorative_record_id BIGINT UNSIGNED NULLABLE;
ALTER TABLE treatment_plans ADD FOREIGN KEY (restorative_record_id) REFERENCES restorative_records(id);
```

---

### Phase 2: Models

#### 2.1 Update RestorativeRecord Model
```php
class RestorativeRecord extends Model
{
    // ... existing code ...

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function treatmentPlan(): HasOne
    {
        return $this->hasOne(TreatmentPlan::class, 'restorative_record_id');
    }

    /**
     * Auto-create treatment plan entry when restorative record is created
     */
    public static function booted()
    {
        static::created(function (RestorativeRecord $record) {
            $record->createTreatmentPlanEntry();
        });

        static::updated(function (RestorativeRecord $record) {
            $record->updateTreatmentPlanEntry();
        });

        static::deleted(function (RestorativeRecord $record) {
            $record->deleteTreatmentPlanEntry();
        });
    }

    /**
     * Create corresponding treatment plan entry
     */
    public function createTreatmentPlanEntry()
    {
        $procedure = $this->getProcedureName();
        $cost = $this->getEstimatedCost();

        TreatmentPlan::create([
            'patient_id' => $this->patient_id,
            'restorative_record_id' => $this->id,
            'procedure' => $procedure,
            'tooth' => $this->tooth_id,
            'cost' => $cost,
            'status' => 'Proposed',  // Initially proposed
            'branch_id' => $this->patient->branch_id,
            'doctor_id' => auth()->id(),
        ]);
    }

    /**
     * Update corresponding treatment plan entry
     */
    public function updateTreatmentPlanEntry()
    {
        $treatmentPlan = $this->treatmentPlan;
        if ($treatmentPlan) {
            $treatmentPlan->update([
                'procedure' => $this->getProcedureName(),
                'tooth' => $this->tooth_id,
                'cost' => $this->getEstimatedCost(),
            ]);
        }
    }

    /**
     * Delete corresponding treatment plan entry
     */
    public function deleteTreatmentPlanEntry()
    {
        $this->treatmentPlan?->delete();
    }

    /**
     * Generate procedure name from restorative details
     */
    private function getProcedureName(): string
    {
        $parts = [];

        if ($this->surface) {
            $parts[] = ucfirst($this->surface);
        }

        if ($this->material) {
            $parts[] = $this->material;
        }

        if (!empty($parts)) {
            return implode(' ', $parts) . ' Restoration';
        }

        return 'Restorative Treatment';
    }

    /**
     * Get estimated cost (can be enhanced with material costs)
     */
    private function getEstimatedCost(): ?float
    {
        // Default cost or calculate from material
        $materialCosts = [
            'amalgam' => 150,
            'composite' => 200,
            'glass ionomer' => 120,
            'resin' => 180,
        ];

        $material = strtolower($this->material ?? '');
        
        foreach ($materialCosts as $key => $cost) {
            if (str_contains($material, $key)) {
                return $cost;
            }
        }

        return null; // Will need manual entry
    }
}
```

#### 2.2 Update TreatmentPlan Model
```php
class TreatmentPlan extends Model
{
    protected $fillable = [
        // ... existing fields ...
        'restorative_record_id',
    ];

    public function restorativeRecord(): BelongsTo
    {
        return $this->belongsTo(RestorativeRecord::class);
    }
}
```

---

### Phase 3: API Endpoints

#### 3.1 Create Endpoint to Convert Restorative to Treatment
```php
// PatientController.php

public function restorativeToTreatment($patientId, $restorativeId)
{
    $restorative = RestorativeRecord::findOrFail($restorativeId);
    
    if ($restorative->patient_id != $patientId) {
        return response()->json(['error' => 'Restorative record not found'], 404);
    }

    // Create or update treatment plan entry
    if ($restorative->treatmentPlan) {
        return response()->json([
            'message' => 'Treatment plan already exists for this restorative record',
            'treatment' => $restorative->treatmentPlan,
        ]);
    }

    $treatmentPlan = $restorative->createTreatmentPlanEntry();

    return response()->json([
        'message' => 'Restorative treatment added to treatment plan',
        'treatment' => $treatmentPlan,
    ], 201);
}
```

#### 3.2 Get Proposed Treatments from Restorative Records
```php
public function getProposedTreatmentsFromRestorative($patientId)
{
    $treatments = TreatmentPlan::where('patient_id', $patientId)
        ->where('status', 'Proposed')
        ->whereNotNull('restorative_record_id')
        ->with('restorativeRecord')
        ->get();

    return response()->json($treatments);
}
```

---

### Phase 4: Frontend Integration

#### 4.1 Display Restorative Records as Proposed Treatments
```typescript
// In treatment plan component

// Get proposed treatments
const proposedTreatments = treatmentPlans.filter(t => t.status === 'Proposed');

// Show with source indicator
proposedTreatments.map(t => ({
    ...t,
    source: t.restorative_record_id ? 'Restorative Record' : 'Manual',
    sourceIcon: t.restorative_record_id ? '📋' : '✏️',
}))
```

#### 4.2 Add Button to Create Treatment from Restorative
```typescript
// In restorative record view
<Button onClick={() => convertRestorativeToTreatment(restorativeId)}>
    Add to Treatment Plan
</Button>
```

---

### Phase 5: Alternative Approach (if auto-creation not desired)

If you want manual control instead of auto-creation:

#### 5.1 API to Show Restorative Records Not in Treatment Plan
```php
public function getRestorativesNotInTreatment($patientId)
{
    $restoratives = RestorativeRecord::where('patient_id', $patientId)
        ->whereDoesntHave('treatmentPlan')
        ->get();

    return response()->json($restoratives);
}
```

#### 5.2 Batch Convert to Treatments
```php
public function bulkAddRestorativesToTreatment(Request $request, $patientId)
{
    $restorativeIds = $request->input('restorative_ids');

    $records = RestorativeRecord::whereIn('id', $restorativeIds)
        ->where('patient_id', $patientId)
        ->get();

    $created = [];
    foreach ($records as $record) {
        $treatmentPlan = $record->createTreatmentPlanEntry();
        $created[] = $treatmentPlan;
    }

    return response()->json([
        'message' => count($created) . ' treatments added',
        'treatments' => $created,
    ], 201);
}
```

---

## Implementation Options

### Option A: Auto-Create (Recommended)
- ✅ Automatic, no manual step
- ✅ Restorative records appear immediately as treatments
- ✅ Changes sync automatically
- ❌ All restorative records become treatments

### Option B: Manual Conversion
- ✅ Dentist controls what becomes treatment
- ✅ Flexible, allows filtering
- ❌ Additional click required

### Option C: Hybrid
- Auto-create as "Proposed" status
- Dentist reviews and approves/rejects
- Can batch approve/reject

---

## Database Changes

```sql
-- Add to lab_restorative_records table
ALTER TABLE restorative_records 
ADD COLUMN restorative_record_id BIGINT UNSIGNED NULLABLE AFTER invoice_id;

ALTER TABLE treatment_plans 
ADD CONSTRAINT fk_treatment_plans_restorative_record_id 
FOREIGN KEY (restorative_record_id) 
REFERENCES restorative_records(id) ON DELETE CASCADE;

-- Index for faster queries
CREATE INDEX idx_treatment_plans_restorative_record_id 
ON treatment_plans(restorative_record_id);
```

---

## Route Changes

```php
// routes/api.php

Route::post('/patients/{patientId}/restorative/{restorativeId}/to-treatment', [PatientController::class, 'restorativeToTreatment']);

Route::get('/patients/{patientId}/proposed-treatments', [PatientController::class, 'getProposedTreatmentsFromRestorative']);

Route::post('/patients/{patientId}/bulk-add-restorative-treatments', [PatientController::class, 'bulkAddRestorativesToTreatment']);
```

---

## Frontend Changes

### Add Action Button in Restorative Records View
```typescript
// In restorative records list/view

<Button 
    onClick={() => addToTreatmentPlan(restorativeId)}
    disabled={hasExistingTreatment}
>
    {hasExistingTreatment ? 'Already in Treatment Plan' : 'Add to Treatment Plan'}
</Button>
```

### Show Proposed Treatments in Treatment Plan
```typescript
// In treatment plan view

const ProposedTreatments = ({ treatments }) => {
    const proposed = treatments.filter(t => t.status === 'Proposed');

    return (
        <div>
            <h3>Proposed Treatments</h3>
            {proposed.map(t => (
                <div key={t.id}>
                    <span>{t.source === 'Restorative Record' && '📋'}</span>
                    <span>{t.procedure}</span>
                    <span>Tooth #{t.tooth}</span>
                    <span>${t.cost}</span>
                    <button onClick={() => approveTreatment(t.id)}>Approve</button>
                </div>
            ))}
        </div>
    );
};
```

---

## Implementation Checklist

### Backend
- [ ] Create migration: Add restorative_record_id to treatment_plans
- [ ] Update RestorativeRecord model with relationships
- [ ] Update TreatmentPlan model with restorative_record relationship
- [ ] Implement booted() hooks for auto-creation
- [ ] Create API endpoints
- [ ] Add routes
- [ ] Create unit tests
- [ ] Test data sync on update/delete

### Frontend
- [ ] Add "Add to Treatment Plan" button in restorative view
- [ ] Fetch proposed treatments (restorative source)
- [ ] Display source indicator in treatment list
- [ ] Update treatment plan form if needed
- [ ] Test conversion flow

### Testing
- [ ] Create restorative record → treatment plan appears
- [ ] Update restorative record → treatment plan updates
- [ ] Delete restorative record → treatment plan removed
- [ ] Multiple restorative records → multiple treatments
- [ ] Invoice generation includes restorative-sourced treatments

---

## Recommended: Which Option to Choose?

**Use Option A (Auto-Create)** for your use case because:
1. Clinical workflow expects restorative records to be treatments
2. No additional manual steps needed
3. Automatic sync on changes
4. All restorative data captured in treatment plan

This way, when you add a restorative record, it automatically appears as a "Proposed" treatment in the treatment plan.


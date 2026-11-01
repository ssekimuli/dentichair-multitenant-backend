<?php

namespace App\Services\Quotation;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Employee;
use App\Models\LaboratoryOrder;
use App\Models\Patient;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\TreatmentPlan;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function __construct(
        protected QuotationNumberService $numberService,
        protected QuotationCalculationService $calculationService,
        protected QuotationStatusService $statusService
    ) {}

    /**
     * Get paginated quotations with filtering and tenant branch isolation.
     */
    public function list(int $branchId, array $filters = []): LengthAwarePaginator
    {
        $query = Quotation::with([
            'patient:id,first_name,last_name,email,phone',
            'labClient:id,name,email,phone,address',
            'dentist:id,name,email,role',
            'branch:id,name,currency',
            'items',
            'invoice:id,receipt_number,amount,status',
        ])->where('branch_id', $branchId);

        // Search
        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function (Builder $q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($pq) use ($search) {
                      $pq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                  })
                  ->orWhereHas('labClient', function ($lq) use ($search) {
                      $lq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Filter by customer type
        if (!empty($filters['customer_type'])) {
            $query->where('customer_type', $filters['customer_type']);
        }

        // Filter by patient
        if (!empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        // Filter by lab client
        if (!empty($filters['lab_client_id'])) {
            $query->where('lab_client_id', $filters['lab_client_id']);
        }

        // Filter by lab order
        if (!empty($filters['lab_order_id'])) {
            $query->where('lab_order_id', $filters['lab_order_id']);
        }

        // Filter by dentist
        if (!empty($filters['dentist_id'])) {
            $query->where('dentist_id', $filters['dentist_id']);
        }

        // Filter by status
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by source type
        if (!empty($filters['source_type'])) {
            $query->where('source_type', $filters['source_type']);
        }

        // Filter by quotation date range
        if (!empty($filters['date_from'])) {
            $query->whereDate('quotation_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('quotation_date', '<=', $filters['date_to']);
        }

        // Filter by valid_until
        if (!empty($filters['valid_until_from'])) {
            $query->whereDate('valid_until', '>=', $filters['valid_until_from']);
        }
        if (!empty($filters['valid_until_to'])) {
            $query->whereDate('valid_until', '<=', $filters['valid_until_to']);
        }

        // Sorting
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = strtolower($filters['sort_order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, ['quotation_date', 'valid_until', 'total', 'created_at', 'status'])) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest();
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 15)));
        return $query->paginate($perPage);
    }

    /**
     * Create a quotation (patient or lab client).
     */
    public function create(array $data, int $branchId, ?int $userId = null): Quotation
    {
        return DB::transaction(function () use ($data, $branchId, $userId) {
            $customerType = $data['customer_type'] ?? null;
            if (!in_array($customerType, ['patient', 'lab_client'])) {
                throw ValidationException::withMessages([
                    'customer_type' => ['The customer_type must be either patient or lab_client.']
                ]);
            }

            // Exactly one customer must be populated
            $patientId = $data['patient_id'] ?? null;
            $labClientId = $data['lab_client_id'] ?? null;

            if ($customerType === 'patient') {
                if (empty($patientId)) {
                    throw ValidationException::withMessages([
                        'patient_id' => ['The patient_id is required for a patient quotation.']
                    ]);
                }
                if (!empty($labClientId)) {
                    throw ValidationException::withMessages([
                        'lab_client_id' => ['A quotation cannot belong to both a patient and a laboratory client.']
                    ]);
                }

                // Verify patient exists and belongs to the branch
                $patient = Patient::where('id', $patientId)->where('branch_id', $branchId)->first();
                if (!$patient) {
                    throw ValidationException::withMessages([
                        'patient_id' => ['The selected patient does not exist or does not belong to this clinic branch.']
                    ]);
                }
            } else {
                if (empty($labClientId)) {
                    throw ValidationException::withMessages([
                        'lab_client_id' => ['The lab_client_id is required for a laboratory client quotation.']
                    ]);
                }
                if (!empty($patientId)) {
                    throw ValidationException::withMessages([
                        'patient_id' => ['A quotation cannot belong to both a patient and a laboratory client.']
                    ]);
                }

                // Verify client exists
                $client = Client::find($labClientId);
                if (!$client) {
                    throw ValidationException::withMessages([
                        'lab_client_id' => ['The selected laboratory client does not exist.']
                    ]);
                }
            }

            // Verify dentist if provided
            $dentistId = $data['dentist_id'] ?? null;
            if (!empty($dentistId)) {
                $dentist = Employee::where('id', $dentistId)->where('branch_id', $branchId)->first();
                if (!$dentist) {
                    throw ValidationException::withMessages([
                        'dentist_id' => ['The selected dentist does not belong to this branch.']
                    ]);
                }
            }

            // Verify lab order if provided
            $labOrderId = $data['lab_order_id'] ?? null;
            if (!empty($labOrderId)) {
                if ($customerType !== 'lab_client') {
                    throw ValidationException::withMessages([
                        'lab_order_id' => ['A lab order can only be attached to a laboratory client quotation.']
                    ]);
                }
                $labOrder = LaboratoryOrder::where('id', $labOrderId)->where('branch_id', $branchId)->first();
                if (!$labOrder) {
                    throw ValidationException::withMessages([
                        'lab_order_id' => ['The selected laboratory order was not found for this branch.']
                    ]);
                }
                if ($labOrder->client_id != $labClientId) {
                    throw ValidationException::withMessages([
                        'lab_order_id' => ['The selected laboratory order does not belong to the selected laboratory client.']
                    ]);
                }
            }

            // Calculate items
            $itemsData = $data['items'] ?? [];
            if (empty($itemsData)) {
                throw ValidationException::withMessages([
                    'items' => ['At least one item is required to create a quotation.']
                ]);
            }

            $calculatedItems = [];
            foreach ($itemsData as $index => $itemRaw) {
                $calc = $this->calculationService->calculateItem($itemRaw);
                $calculatedItems[] = array_merge($itemRaw, $calc, [
                    'sort_order' => $itemRaw['sort_order'] ?? $index,
                ]);
            }

            // Calculate overall quotation totals
            $totals = $this->calculationService->calculateQuotation(
                $calculatedItems,
                $data['discount_type'] ?? 'none',
                (float) ($data['discount_amount'] ?? 0.00),
                (float) ($data['tax_rate'] ?? 0.00)
            );

            // Currency from branch or branch setting
            $branch = Branch::find($branchId);
            $currency = $data['currency'] ?? $branch?->currency ?? 'USD';

            $quotationNumber = $this->numberService->generate($branchId);

            $quotation = Quotation::create([
                'quotation_number' => $quotationNumber,
                'customer_type' => $customerType,
                'patient_id' => $patientId,
                'lab_client_id' => $labClientId,
                'dentist_id' => $dentistId,
                'lab_order_id' => $labOrderId,
                'branch_id' => $branchId,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'quotation_date' => $data['quotation_date'] ?? now()->format('Y-m-d'),
                'valid_until' => $data['valid_until'] ?? now()->addDays(30)->format('Y-m-d'),
                'currency' => $currency,
                'subtotal' => $totals['subtotal'],
                'discount_type' => $totals['discount_type'],
                'discount_amount' => $totals['discount_amount'],
                'tax_rate' => $totals['tax_rate'],
                'tax_amount' => $totals['tax_amount'],
                'total' => $totals['total'],
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'terms_conditions' => $data['terms_conditions'] ?? null,
                'expected_delivery_date' => $data['expected_delivery_date'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            // Save items
            foreach ($calculatedItems as $cItem) {
                $quotation->items()->create([
                    'service_id' => $cItem['service_id'] ?? null,
                    'treatment_plan_item_id' => $cItem['treatment_plan_item_id'] ?? null,
                    'lab_order_item_id' => $cItem['lab_order_item_id'] ?? null,
                    'item_type' => $cItem['item_type'] ?? ($customerType === 'patient' ? 'procedure' : 'lab_service'),
                    'description' => $cItem['description'],
                    'tooth_number' => $cItem['tooth_number'] ?? null,
                    'material' => $cItem['material'] ?? null,
                    'shade' => $cItem['shade'] ?? null,
                    'quantity' => $cItem['quantity'],
                    'unit_price' => $cItem['unit_price'],
                    'discount_type' => $cItem['discount_type'],
                    'discount_amount' => $cItem['discount_amount'],
                    'tax_rate' => $cItem['tax_rate'],
                    'tax_amount' => $cItem['tax_amount'],
                    'total' => $cItem['total'],
                    'expected_delivery_date' => $cItem['expected_delivery_date'] ?? null,
                    'special_instructions' => $cItem['special_instructions'] ?? null,
                    'sort_order' => $cItem['sort_order'],
                ]);
            }

            // Record initial status history
            $this->statusService->transition($quotation, 'draft', $userId, 'staff', 'Initial draft creation.');

            ActivityLog::log(
                'Quotation Created',
                "Quotation #{$quotation->quotation_number} created for {$quotation->customer_name}.",
                ['quotation_id' => $quotation->id, 'total' => $quotation->total]
            );

            return $quotation->fresh(['items', 'patient', 'labClient', 'dentist', 'branch']);
        });
    }

    /**
     * Create quotation for a patient from selected treatment plan items.
     */
    public function createFromTreatmentPlan(int $patientId, array $data, int $branchId, ?int $userId = null): Quotation
    {
        $patient = Patient::where('id', $patientId)->where('branch_id', $branchId)->firstOrFail();

        $selectedItemIds = $data['treatment_plan_item_ids'] ?? [];
        if (empty($selectedItemIds)) {
            throw ValidationException::withMessages([
                'treatment_plan_item_ids' => ['Please select at least one treatment plan item.']
            ]);
        }

        // Load treatment plans and verify ownership
        $treatmentPlans = TreatmentPlan::whereIn('id', $selectedItemIds)
            ->where('patient_id', $patientId)
            ->where('branch_id', $branchId)
            ->get();

        if ($treatmentPlans->count() !== count($selectedItemIds)) {
            throw ValidationException::withMessages([
                'treatment_plan_item_ids' => ['One or more selected treatment plan items do not belong to this patient or branch.']
            ]);
        }

        // Map treatment plans to quotation items
        $items = [];
        $dentistId = $data['dentist_id'] ?? null;

        foreach ($treatmentPlans as $tp) {
            if (!$dentistId && $tp->doctor_id) {
                $dentistId = $tp->doctor_id;
            }
            $items[] = [
                'treatment_plan_item_id' => $tp->id,
                'service_id' => $tp->treatment_id,
                'item_type' => 'procedure',
                'description' => $tp->procedure,
                'tooth_number' => $tp->tooth,
                'quantity' => 1,
                'unit_price' => (float) $tp->cost,
                'special_instructions' => $tp->notes,
            ];
        }

        // Merge any additional manual items
        if (!empty($data['additional_items'])) {
            foreach ($data['additional_items'] as $addItem) {
                $items[] = $addItem;
            }
        }

        $sourceType = !empty($data['additional_items']) ? 'mixed' : 'treatment_plan';

        $quotationPayload = array_merge($data, [
            'customer_type' => 'patient',
            'patient_id' => $patientId,
            'dentist_id' => $dentistId,
            'source_type' => $sourceType,
            'source_id' => $treatmentPlans->first()?->id,
            'items' => $items,
        ]);

        return $this->create($quotationPayload, $branchId, $userId);
    }

    /**
     * Create quotation from an existing laboratory order.
     */
    public function createFromLabOrder(int $labOrderId, array $data, int $branchId, ?int $userId = null): Quotation
    {
        $labOrder = LaboratoryOrder::with(['client', 'labService'])
            ->where('id', $labOrderId)
            ->where('branch_id', $branchId)
            ->firstOrFail();

        if (!$labOrder->client_id) {
            throw ValidationException::withMessages([
                'lab_order_id' => ['The selected lab order is not linked to any laboratory client.']
            ]);
        }

        $price = $labOrder->applied_price > 0
            ? (float) $labOrder->applied_price
            : (float) ($labOrder->cost ?? 0.00);

        $items = [
            [
                'lab_order_item_id' => $labOrder->id,
                'service_id' => $labOrder->lab_service_id,
                'item_type' => 'lab_service',
                'description' => $labOrder->item ?? 'Laboratory Appliance / Service',
                'tooth_number' => $labOrder->tooth,
                'material' => $labOrder->material,
                'shade' => $labOrder->shade,
                'quantity' => 1,
                'unit_price' => $price,
                'special_instructions' => $labOrder->instructions,
                'expected_delivery_date' => $labOrder->due_date?->format('Y-m-d'),
            ]
        ];

        // Additional manual items
        if (!empty($data['additional_items'])) {
            foreach ($data['additional_items'] as $addItem) {
                $items[] = $addItem;
            }
        }

        $sourceType = !empty($data['additional_items']) ? 'mixed' : 'lab_order';

        $quotationPayload = array_merge($data, [
            'customer_type' => 'lab_client',
            'lab_client_id' => $labOrder->client_id,
            'lab_order_id' => $labOrder->id,
            'source_type' => $sourceType,
            'source_id' => $labOrder->id,
            'expected_delivery_date' => $data['expected_delivery_date'] ?? $labOrder->due_date?->format('Y-m-d'),
            'items' => $items,
        ]);

        return $this->create($quotationPayload, $branchId, $userId);
    }

    /**
     * Update an eligible draft quotation.
     */
    public function update(Quotation $quotation, array $data, ?int $userId = null): Quotation
    {
        if (!$quotation->canBeEdited()) {
            throw ValidationException::withMessages([
                'status' => ["Only draft quotations can be edited. Quotation #{$quotation->quotation_number} is in '{$quotation->status}' status."]
            ]);
        }

        return DB::transaction(function () use ($quotation, $data, $userId) {
            // Update items if provided
            if (isset($data['items'])) {
                if (empty($data['items'])) {
                    throw ValidationException::withMessages([
                        'items' => ['Quotation must have at least one item.']
                    ]);
                }

                $quotation->items()->delete();

                $calculatedItems = [];
                foreach ($data['items'] as $index => $itemRaw) {
                    $calc = $this->calculationService->calculateItem($itemRaw);
                    $calculatedItems[] = array_merge($itemRaw, $calc, [
                        'sort_order' => $itemRaw['sort_order'] ?? $index,
                    ]);
                }

                $totals = $this->calculationService->calculateQuotation(
                    $calculatedItems,
                    $data['discount_type'] ?? $quotation->discount_type,
                    isset($data['discount_amount']) ? (float) $data['discount_amount'] : (float) $quotation->discount_amount,
                    isset($data['tax_rate']) ? (float) $data['tax_rate'] : (float) $quotation->tax_rate
                );

                $data['subtotal'] = $totals['subtotal'];
                $data['discount_type'] = $totals['discount_type'];
                $data['discount_amount'] = $totals['discount_amount'];
                $data['tax_rate'] = $totals['tax_rate'];
                $data['tax_amount'] = $totals['tax_amount'];
                $data['total'] = $totals['total'];

                foreach ($calculatedItems as $cItem) {
                    $quotation->items()->create([
                        'service_id' => $cItem['service_id'] ?? null,
                        'treatment_plan_item_id' => $cItem['treatment_plan_item_id'] ?? null,
                        'lab_order_item_id' => $cItem['lab_order_item_id'] ?? null,
                        'item_type' => $cItem['item_type'] ?? 'procedure',
                        'description' => $cItem['description'],
                        'tooth_number' => $cItem['tooth_number'] ?? null,
                        'material' => $cItem['material'] ?? null,
                        'shade' => $cItem['shade'] ?? null,
                        'quantity' => $cItem['quantity'],
                        'unit_price' => $cItem['unit_price'],
                        'discount_type' => $cItem['discount_type'],
                        'discount_amount' => $cItem['discount_amount'],
                        'tax_rate' => $cItem['tax_rate'],
                        'tax_amount' => $cItem['tax_amount'],
                        'total' => $cItem['total'],
                        'expected_delivery_date' => $cItem['expected_delivery_date'] ?? null,
                        'special_instructions' => $cItem['special_instructions'] ?? null,
                        'sort_order' => $cItem['sort_order'],
                    ]);
                }
            }

            $data['updated_by'] = $userId;
            $quotation->update(collect($data)->except(['items', 'branch_id', 'status', 'converted_invoice_id', 'quotation_number'])->toArray());

            ActivityLog::log(
                'Quotation Updated',
                "Quotation #{$quotation->quotation_number} was updated.",
                ['quotation_id' => $quotation->id]
            );

            return $quotation->fresh(['items', 'patient', 'labClient', 'dentist']);
        });
    }

    /**
     * Delete or cancel a quotation.
     */
    public function deleteOrCancel(Quotation $quotation, ?int $userId = null, ?string $reason = null): bool
    {
        if (in_array($quotation->status, ['approved', 'converted'])) {
            throw ValidationException::withMessages([
                'status' => ["Approved or converted financial quotations cannot be deleted. You may cancel eligible quotations instead."]
            ]);
        }

        if ($quotation->status === 'draft') {
            $quotation->delete();
            ActivityLog::log(
                'Quotation Deleted',
                "Draft quotation #{$quotation->quotation_number} was soft-deleted.",
                ['quotation_id' => $quotation->id]
            );
            return true;
        }

        // Cancel other statuses
        $this->statusService->transition($quotation, 'cancelled', $userId, 'staff', $reason ?? 'Cancelled by staff.');
        return true;
    }
}


<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\InvoiceTreatment;
use App\Models\InvoiceOtherCharge;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

/**
 * @group Invoices & Billing
 * 
 * Endpoints for managing patient invoices, payments, and billing operations.
 * Includes invoice creation, payment processing, and financial tracking.
 */
class InvoiceController extends Controller
{
    /**
     * Get All Invoices
     * 
     * Retrieve a paginated list of invoices with optional filtering by branch, status, or search term.
     * Returns complete invoice details including treatments, charges, and payment transactions.
     * 
     * @authenticated
     * 
     * @queryParam branch_id string Filter invoices by branch ID. Example: b1
     * @queryParam status string Filter invoices by status (Pending, Paid, Overdue). Example: Paid
     * @queryParam search string Search invoices by patient name, receipt number, or status. Example: John
     * @queryParam per_page int Number of results per page (default: 10). Example: 20
     * 
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "inv1",
     *       "patient_name": "John Doe",
     *       "patient_id": "1",
     *       "amount": 250.00,
     *       "paid_amount": 100.00,
     *       "status": "Pending",
     *       "issue_date": "2024-05-10",
     *       "due_date": "2024-06-10",
     *       "branch_id": "b1",
     *       "payment_method": "Insurance",
     *       "treatments": [
     *         {
     *           "procedure": "Filling for #16",
     *           "cost": 250.00
     *         }
     *       ],
     *       "other_charges": [],
     *       "transactions": [
     *         {
     *           "id": "txn1",
     *           "date": "2024-05-12",
     *           "amount": 100.00,
     *           "method": "Insurance"
     *         }
     *       ],
     *       "patient": {
     *         "id": "1",
     *         "name": "John Doe",
     *         "email": "john.doe@example.com"
     *       },
     *       "branch": {
     *         "id": "b1",
     *         "name": "Downtown Clinic"
     *       }
     *     }
     *   ],
     *   "meta": {
     *     "current_page": 1,
     *     "last_page": 5,
     *     "per_page": 10,
     *     "total": 48
     *   }
     * }
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $query = Invoice::with(['patient.insurancePolicies.package.company', 'branch', 'treatments', 'otherCharges', 'transactions', 'laboratoryOrder.client', 'xrayRequest']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('category')) {
            $cat = strtolower(str_replace(' ', '-', $request->category));
            if ($cat === 'lab-income' || $cat === 'lab') {
                $query->where(function ($q) {
                    $q->where('category', 'lab-income')->orWhere('category', 'like', '%lab%');
                });
            } else {
                $query->where('category', $request->category);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('patient_name', 'like', "%{$search}%")
                  ->orWhere('receipt_number', 'like', "%{$search}%")
                  ->orWhere('status', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%")
                  ->orWhereHas('patient', function ($pq) use ($search) {
                      $pq->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%")
                         ->orWhereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$search}%"]);
                  });
            });
        }

        $invoices = $query->latest()->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $invoices->items(),
            'meta' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
            ]
        ]);
    }

    /**
     * Get Income Records
     *
     * Retrieve all paid invoices for the selected branch.
     *
     * @authenticated
     *
     * @queryParam branch_id string Optional branch ID filter. Example: b1
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": "inv1",
     *       "patient_name": "John Doe",
     *       "patient_id": "1",
     *       "amount": 250.00,
     *       "paid_amount": 250.00,
     *       "status": "Paid",
     *       "issue_date": "2024-05-10",
     *       "due_date": "2024-06-10",
     *       "branch_id": "b1",
     *       "payment_method": "Insurance",
     *       "treatments": [
     *         {
     *           "procedure": "Filling for #16",
     *           "cost": 250.00
     *         }
     *       ],
     *       "other_charges": [],
     *       "transactions": [
     *         {
     *           "id": "txn1",
     *           "date": "2024-05-12",
     *           "amount": 250.00,
     *           "method": "Insurance"
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    public function getIncomeRecords(Request $request)
    {
        // Self-heal: ensure any existing invoices tied to X-ray requests or with Radiology other charges are categorized as radiology-income
        try {
            DB::table('invoices')
                ->where(function ($q) {
                    $q->whereNull('category')
                      ->orWhere('category', '!=', 'radiology-income');
                })
                ->where(function ($q) {
                    $q->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('xray_requests')
                            ->whereColumn('xray_requests.invoice_id', 'invoices.id');
                    })
                    ->orWhereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('invoice_other_charges')
                            ->whereColumn('invoice_other_charges.invoice_id', 'invoices.id')
                            ->where(function ($oq) {
                                $oq->where('description', 'LIKE', '%Radiology%')
                                   ->orWhere('description', 'LIKE', '%X-Ray%');
                            });
                    });
                })
                ->update([
                    'category' => 'radiology-income',
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Radiology self-heal error in getIncomeRecords: ' . $e->getMessage());
        }

        $query = Invoice::with(['patient', 'branch', 'treatments', 'otherCharges', 'transactions', 'laboratoryOrder.client']);

        $branch = null;
        if ($request->filled('branch_id') && $request->branch_id !== 'all') {
            $query->where('branch_id', $request->branch_id);
            $branch = Branch::find($request->branch_id);
        }

        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('issue_date', [
                \Carbon\Carbon::parse($request->date_from)->startOfDay(),
                \Carbon\Carbon::parse($request->date_to)->endOfDay()
            ]);
        }

        $invoices = $query->latest()->get();
        $totalPaid = $invoices->sum(function ($inv) {
            $paid = (float) $inv->paid_amount;
            return $paid > 0 ? $paid : (float) $inv->amount;
        });

        // Define all available categories
        $allCategories = ['lab-income', 'pharmacy-income', 'procedure-income', 'radiology-income', 'other'];

        // Group by category
        $groupedByCategory = $invoices->groupBy(function ($invoice) {
            return $invoice->category ?? 'other';
        })->map(function ($group) {
            $catTotal = $group->sum(function ($inv) {
                $paid = (float) $inv->paid_amount;
                return $paid > 0 ? $paid : (float) $inv->amount;
            });
            return [
                'records' => $group->values(),
                'count' => $group->count(),
                'total' => number_format($catTotal, 2, '.', ''),
            ];
        });

        // Create summary with all categories, including those with 0 records
        $summary = [];
        foreach ($allCategories as $category) {
            if (isset($groupedByCategory[$category])) {
                $summary[$category] = [
                    'count' => $groupedByCategory[$category]['count'],
                    'total' => floatval($groupedByCategory[$category]['total']),
                ];
            } else {
                $summary[$category] = [
                    'count' => 0,
                    'total' => 0.0,
                ];
            }
        }

        // Ensure by_category also has all categories
        foreach ($allCategories as $category) {
            if (!isset($groupedByCategory[$category])) {
                $groupedByCategory[$category] = [
                    'records' => [],
                    'count' => 0,
                    'total' => '0.00',
                ];
            }
        }

        return response()->json([
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
            ] : null,
            'total' => number_format($totalPaid, 2, '.', ''),
            'summary' => $summary,
            'by_category' => $groupedByCategory,
            'records' => $invoices,
        ]);
    }

    /**
     * Get Invoice by ID
     * 
     * Retrieve detailed information for a specific invoice including all treatments,
     * additional charges, and payment history.
     * 
     * @authenticated
     * 
     * @urlParam id string required The invoice ID. Example: inv1
     * 
     * @response 200 {
     *   "id": "inv1",
     *   "patient_name": "John Doe",
     *   "patient_id": "1",
     *   "amount": 250.00,
     *   "paid_amount": 100.00,
     *   "status": "Pending",
     *   "issue_date": "2024-05-10",
     *   "due_date": "2024-06-10",
     *   "branch_id": "b1",
     *   "payment_method": "Insurance",
     *   "treatments": [
     *     {
     *       "procedure": "Filling for #16",
     *       "cost": 250.00
     *     }
     *   ],
     *   "other_charges": [
     *     {
     *       "description": "Late Fee",
     *       "amount": 20.00
     *     }
     *   ],
     *   "transactions": [
     *     {
     *       "id": "txn1",
     *       "date": "2024-05-12",
     *       "amount": 100.00,
     *       "method": "Insurance"
     *     }
     *   ],
     *   "patient": {
     *     "id": "1",
     *     "name": "John Doe"
     *   },
     *   "branch": {
     *     "id": "b1",
     *     "name": "Downtown Clinic"
     *   }
     * }
     * @response 404 {
     *   "error": "Invoice not found"
     * }
     * 
     * @param string $id Invoice ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $invoice = Invoice::with([
            'patient.insurancePolicies.package.company',
            'branch',
            'treatments',
            'otherCharges',
            'transactions',
            'laboratoryOrder.client'
        ])->find($id);

        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        return response()->json($invoice);
    }

    /**
     * Create Invoice
     * 
     * Create a new invoice for a patient with treatments and optional additional charges.
     * Automatically sets initial status to "Pending" and paid amount to 0.
     * 
     * @authenticated
     * 
     * @bodyParam patient_name string required Patient's full name. Example: John Doe
     * @bodyParam patient_id string required Patient's ID. Example: 1
     * @bodyParam amount number required Total invoice amount. Example: 250.00
     * @bodyParam issue_date date required Invoice issue date (YYYY-MM-DD). Example: 2024-05-10
     * @bodyParam due_date date required Invoice due date (YYYY-MM-DD). Example: 2024-06-10
     * @bodyParam branch_id string required Branch ID. Example: b1
     * @bodyParam treatments array required Array of treatment procedures
     * @bodyParam treatments.*.procedure string required Treatment procedure description. Example: Filling for #16
     * @bodyParam treatments.*.cost number required Treatment cost. Example: 250.00
     * @bodyParam other_charges array Optional array of additional charges
     * @bodyParam other_charges.*.description string required Charge description. Example: Late Fee
     * @bodyParam other_charges.*.amount number required Charge amount. Example: 20.00
     * 
     * @response 201 {
     *   "id": "inv5",
     *   "patient_name": "John Doe",
     *   "patient_id": "1",
     *   "amount": 270.00,
     *   "paid_amount": 0.00,
     *   "status": "Pending",
     *   "issue_date": "2024-05-10",
     *   "due_date": "2024-06-10",
     *   "branch_id": "b1",
     *   "payment_method": null,
     *   "treatments": [
     *     {
     *       "procedure": "Filling for #16",
     *       "cost": 250.00
     *     }
     *   ],
     *   "other_charges": [
     *     {
     *       "description": "Late Fee",
     *       "amount": 20.00
     *     }
     *   ],
     *   "transactions": []
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": {
     *     "patient_name": ["The patient name field is required."],
     *     "patient_id": ["The patient id field is required."],
     *     "amount": ["The amount field is required."],
     *     "issue_date": ["The issue date field is required."],
     *     "due_date": ["The due date field is required."],
     *     "branch_id": ["The branch id field is required."],
     *     "treatments": ["The treatments field is required."]
     *   }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'patient_name' => 'nullable|string',
            'patient_id' => 'nullable|exists:patients,id',
            'client_id' => 'nullable|exists:clients,id',
            'amount' => 'required|numeric',
            'issue_date' => 'required|date',
            'due_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
            'category' => 'nullable|string|in:lab-income,pharmacy-income,procedure-income,radiology-income,other',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'insurance_coverage' => 'nullable|numeric|min:0',
            'treatments' => 'sometimes|array',
            'treatments.*.procedure_id' => 'nullable',
            'treatments.*.procedure' => 'required|string',
            'treatments.*.cost' => 'required|numeric',
            'treatments.*.tooth' => 'nullable',
            'treatments.*.notes' => 'nullable|string',
            'other_charges' => 'nullable|array',
            'other_charges.*.description' => 'required|string',
            'other_charges.*.amount' => 'required|numeric',
            'medicines' => 'nullable|array',
            'medicines.*.medicine_id' => 'required|exists:medicines,id',
            'medicines.*.medicine_unit_id' => 'required|exists:medicine_units,id',
            'medicines.*.quantity' => 'required|integer|min:1',
            'medicines.*.price' => 'required|numeric',
        ]);

        $existingInvoice = null;

        if (!empty($validated['patient_id'])) {
            $existingInvoice = Invoice::where('patient_id', $validated['patient_id'])
                ->whereDate('issue_date', $validated['issue_date'])
                ->where('branch_id', $validated['branch_id'])
                ->whereIn('status', ['Pending', 'Overdue'])
                ->first();
        }

        if ($existingInvoice) {
            // Merge into existing invoice
            $newAmount = $existingInvoice->amount + $validated['amount'];
            
            $existingInvoice->amount = $newAmount;
            
            if (isset($validated['discount'])) {
                $existingInvoice->discount = ($existingInvoice->discount ?? 0) + $validated['discount'];
            }
            if (isset($validated['tax'])) {
                $existingInvoice->tax = ($existingInvoice->tax ?? 0) + $validated['tax'];
            }
            if (isset($validated['insurance_coverage'])) {
                $existingInvoice->insurance_coverage = ($existingInvoice->insurance_coverage ?? 0) + $validated['insurance_coverage'];
            }

            // Status update
            if ($existingInvoice->paid_amount >= $newAmount) {
                $existingInvoice->status = 'Paid';
            } else {
                $existingInvoice->status = $existingInvoice->issue_date < today() ? 'Overdue' : 'Pending';
            }

            // Merge medicines
            if (isset($validated['medicines'])) {
                $currentMedsStr = $existingInvoice->getRawOriginal('medicines');
                $currentMeds = $currentMedsStr ? json_decode($currentMedsStr, true) : [];
                $existingInvoice->medicines = array_merge($currentMeds ?: [], $validated['medicines']);
            }

            $existingInvoice->save();
            $invoice = $existingInvoice;
        } else {
            $invoice = Invoice::create([
                ...collect($validated)->except(['treatments', 'other_charges', 'medicines'])->toArray(),
                'paid_amount' => 0,
                'status' => 'Pending',
                'category' => $validated['category'] ?? null,
                'medicines' => $validated['medicines'] ?? null,
            ]);
        }

        if (isset($validated['treatments'])) {
            $claimedPlanIds = [];
            foreach ($validated['treatments'] as $treatment) {
                // Link or create a corresponding completed treatment plan if patient exists
                if ($invoice->patient_id) {
                    $tp = null;
                    if (!empty($treatment['procedure_id'])) {
                        $tp = \App\Models\TreatmentPlan::find($treatment['procedure_id']);
                    }
                    // Billing a procedure that the doctor already planned for this same day:
                    // reuse that pending plan so it is completed instead of billed twice.
                    if (!$tp && empty($treatment['procedure_id'])) {
                        $tp = $this->findPendingPlanForTreatment($invoice, $treatment, $claimedPlanIds);
                        if ($tp) {
                            $claimedPlanIds[] = $tp->id;
                        }
                    }
                    if (!$tp) {
                        $tp = new \App\Models\TreatmentPlan([
                            'patient_id' => $invoice->patient_id,
                            'procedure' => $treatment['procedure'],
                            'cost' => $treatment['cost'],
                            'status' => 'Completed',
                            'date' => \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d'),
                        ]);
                        if (isset($treatment['tooth'])) {
                            $tp->tooth = $treatment['tooth'];
                        }
                        if (isset($treatment['notes'])) {
                            $tp->notes = $treatment['notes'];
                        }
                    } else {
                        // Update cost just in case it was modified on the invoice form
                        $tp->cost = $treatment['cost'];
                        $tp->status = 'Completed';
                        $tp->date = \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d');
                    }
                    $tp->invoice_id = 'inv' . $invoice->id;
                    $tp->saveQuietly();
                }
            }
        }

        if (isset($validated['other_charges'])) {
            foreach ($validated['other_charges'] as $charge) {
                $invoice->otherCharges()->create($charge);
            }
        }

        if (isset($validated['medicines'])) {
            foreach ($validated['medicines'] as $med) {
                // Deduct stock quantity
                $medUnit = \App\Models\MedicineUnit::find($med['medicine_unit_id']);
                if ($medUnit) {
                    $medUnit->decrement('stock_quantity', $med['quantity']);
                }
            }
        }

        // Trigger insurance sync which recreates treatments with exact splits and computes new total
        if ($invoice->patient_id) {
            \App\Models\TreatmentPlan::syncInvoiceForPatientAndDate(
                $invoice->patient_id, 
                \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d')
            );
            $invoice->refresh();

            // Treatment plans above were saved quietly, which skips the model
            // events that normally write the medical history. Sync it explicitly
            // so the invoice procedures appear in the patient's medical history.
            if (!empty($validated['treatments'])) {
                \App\Models\TreatmentPlan::syncMedicalHistoryForPatientAndDate(
                    $invoice->patient_id,
                    \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d')
                );
            }
        }

        $invoice->load(['treatments', 'otherCharges', 'patient', 'branch', 'transactions', 'laboratoryOrder.client']);

        $settings = \App\Models\GlobalSetting::getSettings();

        if ($settings && isset($settings->notifications['billing'])) {
            $sendEmail = $settings->notifications['billing']['email'] ?? true;
            $sendSms = $settings->notifications['billing']['sms'] ?? false;
            $sendWa = $settings->notifications['billing']['whatsapp'] ?? false;

            $clinicName = $settings->clinic_name ?? 'Our Clinic';

            if ($sendSms && $invoice->patient?->phone && $settings->deductSms()) {
                $msg = "Dear {$invoice->patient->first_name}, your new invoice (#{$invoice->id}) for amount {$invoice->amount} from {$clinicName} is ready.";
                \App\Services\NotificationService::sendSms($invoice->patient->phone, $msg);
            }

            if ($sendWa && $invoice->patient?->phone && $settings->isWhatsappActive()) {
                $msg = "Dear {$invoice->patient->first_name}, your new invoice (#{$invoice->id}) for amount {$invoice->amount} from {$clinicName} is ready.";
                \App\Services\NotificationService::sendWhatsApp($invoice->patient->phone, $msg);
            }
        }

        $sendEmail = $settings?->notifications['billing']['email'] ?? true;

        if ($sendEmail && $invoice->patient && $invoice->patient->email) {
            try {
                Mail::to($invoice->patient->email)->send(new \App\Mail\GenericMarkdownMail(
                    'emails.invoice-created',
                    [
                        'patientName' => $invoice->patient->first_name . ' ' . $invoice->patient->last_name,
                        'invoiceId' => $invoice->id,
                        'dueDate' => $invoice->due_date,
                        'amount' => $invoice->amount,
                        'treatments' => $invoice->treatments->toArray(),
                        'otherCharges' => $invoice->otherCharges->toArray(),
                        'clinicName' => $settings?->clinic_name ?? 'Our Clinic',
                    ],
                    'New Invoice #' . $invoice->id . ' - ' . ($settings?->clinic_name ?? 'Our Clinic')
                ));
            } catch (\Exception $e) {
                logger()->error("Failed to send invoice creation email: " . $e->getMessage());
            }
        }

        return response()->json($invoice, 201);
    }

    /**
     * Find a planned (Proposed / In Progress) treatment for this patient on the
     * invoice date whose procedure name matches the billed procedure. Names are
     * compared case-insensitively. Plans already claimed by this request are skipped.
     */
    private function findPendingPlanForTreatment(Invoice $invoice, array $treatment, array $claimedPlanIds): ?\App\Models\TreatmentPlan
    {
        $wanted = mb_strtolower(trim((string) ($treatment['procedure'] ?? '')));
        if ($wanted === '') {
            return null;
        }

        $candidates = \App\Models\TreatmentPlan::query()
            ->where('patient_id', $invoice->patient_id)
            ->whereDate('date', \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d'))
            ->whereRaw("LOWER(status) IN ('proposed', 'in progress')")
            ->whereNotIn('id', $claimedPlanIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $candidates->first(
            fn ($plan) => mb_strtolower(trim((string) $plan->procedure)) === $wanted
        );
    }

    /**
     * Update Invoice
     * 
     * Update an existing invoice's details including basic information, treatments, and charges.
     * When treatments or charges are provided, existing ones are replaced with the new data.
     * 
     * @authenticated
     * 
     * @urlParam id string required The invoice ID. Example: inv1
     * @bodyParam patient_name string Patient's full name. Example: John Doe Updated
     * @bodyParam patient_id string Patient's ID. Example: 1
     * @bodyParam amount number Total invoice amount. Example: 300.00
     * @bodyParam paid_amount number Amount already paid. Example: 150.00
     * @bodyParam status string Invoice status. Example: Paid
     * @bodyParam issue_date date Invoice issue date. Example: 2024-05-10
     * @bodyParam due_date date Invoice due date. Example: 2024-06-10
     * @bodyParam branch_id string Branch ID. Example: b1
     * @bodyParam payment_method string Payment method used. Example: Credit Card
     * @bodyParam treatments array Optional array of treatment procedures to update
     * @bodyParam treatments.*.procedure string required Treatment procedure description. Example: Root Canal for #16
     * @bodyParam treatments.*.cost number required Treatment cost. Example: 1500.00
     * @bodyParam other_charges array Optional array of additional charges to update
     * @bodyParam other_charges.*.description string required Charge description. Example: Consultation Fee
     * @bodyParam other_charges.*.amount number required Charge amount. Example: 50.00
     * 
     * @response 200 {
     *   "id": "inv1",
     *   "patient_name": "John Doe Updated",
     *   "patient_id": "1",
     *   "amount": 300.00,
     *   "paid_amount": 150.00,
     *   "status": "Paid",
     *   "issue_date": "2024-05-10",
     *   "due_date": "2024-06-10",
     *   "branch_id": "b1",
     *   "payment_method": "Credit Card",
     *   "treatments": [
     *     {
     *       "procedure": "Root Canal for #16",
     *       "cost": 1500.00
     *     }
     *   ],
     *   "other_charges": [
     *     {
     *       "description": "Consultation Fee",
     *       "amount": 50.00
     *     }
     *   ],
     *   "transactions": [
     *     {
     *       "id": "txn1",
     *       "date": "2024-05-12",
     *       "amount": 100.00,
     *       "method": "Insurance"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Invoice not found"
     * }
     * 
     * @param Request $request
     * @param string $id Invoice ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        $invoice = Invoice::find($id);
        
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'patient_name' => 'sometimes|nullable|string',
            'patient_id' => 'sometimes|nullable|exists:patients,id',
            'amount' => 'sometimes|numeric',
            'paid_amount' => 'sometimes|numeric',
            'status' => 'sometimes|in:Pending,Paid,Overdue',
            'issue_date' => 'sometimes|date',
            'due_date' => 'sometimes|date',
            'branch_id' => 'sometimes|exists:branches,id',
            'category' => 'sometimes|nullable|string|in:lab-income,pharmacy-income,procedure-income,radiology-income,other',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'insurance_coverage' => 'nullable|numeric|min:0',
            'payment_method' => 'nullable|string',
            'treatments' => 'sometimes|array',
            'treatments.*.procedure_id' => 'nullable',
            'treatments.*.procedure' => 'required|string',
            'treatments.*.cost' => 'required|numeric',
            'treatments.*.tooth' => 'nullable',
            'treatments.*.notes' => 'nullable|string',
            'other_charges' => 'sometimes|array',
            'other_charges.*.description' => 'required|string',
            'other_charges.*.amount' => 'required|numeric',
            'medicines' => 'sometimes|array',
            'medicines.*.medicine_id' => 'required|exists:medicines,id',
            'medicines.*.medicine_unit_id' => 'required|exists:medicine_units,id',
            'medicines.*.quantity' => 'required|integer|min:1',
            'medicines.*.price' => 'required|numeric',
        ]);

        // Update basic invoice details (excluding treatments, other_charges, medicines)
        $invoiceData = collect($validated)->except(['treatments', 'other_charges', 'medicines'])->toArray();
        $invoice->update($invoiceData);

        // Update treatments if provided
        if (isset($validated['treatments'])) {
            $keepTpIds = [];
            foreach ($validated['treatments'] as $treatment) {
                if (!empty($treatment['procedure_id'])) {
                    $keepTpIds[] = $treatment['procedure_id'];
                }
            }

            // Delete existing treatment plans associated with this invoice, except any being reused
            $existingTpQuery = \App\Models\TreatmentPlan::whereIn('invoice_id', [$invoice->id, 'inv' . $invoice->id]);
            if (!empty($keepTpIds)) {
                $existingTpQuery->whereNotIn('id', $keepTpIds);
            }
            $existingTpQuery->delete();

            foreach ($validated['treatments'] as $treatment) {
                if ($invoice->patient_id) {
                    $tp = null;
                    if (!empty($treatment['procedure_id'])) {
                        $tp = \App\Models\TreatmentPlan::find($treatment['procedure_id']);
                    }
                    if (!$tp) {
                        $tp = new \App\Models\TreatmentPlan([
                            'patient_id' => $invoice->patient_id,
                            'procedure' => $treatment['procedure'],
                            'cost' => $treatment['cost'],
                            'status' => 'Completed',
                            'date' => \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d'),
                        ]);
                        if (isset($treatment['tooth'])) {
                            $tp->tooth = $treatment['tooth'];
                        }
                        if (isset($treatment['notes'])) {
                            $tp->notes = $treatment['notes'];
                        }
                    } else {
                        $tp->cost = $treatment['cost'];
                        $tp->status = 'Completed';
                        $tp->date = \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d');
                    }
                    $tp->invoice_id = 'inv' . $invoice->id;
                    $tp->saveQuietly();
                }
            }
        }

        // Sync basic details of existing treatment plans for this invoice (if not recreated)
        if (!isset($validated['treatments'])) {
            if ($invoice->patient_id) {
                \App\Models\TreatmentPlan::whereIn('invoice_id', [$invoice->id, 'inv' . $invoice->id])->update([
                    'patient_id' => $invoice->patient_id,
                    'date' => \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d'),
                ]);
            } else {
                \App\Models\TreatmentPlan::whereIn('invoice_id', [$invoice->id, 'inv' . $invoice->id])->delete();
            }
        }

        // Update other charges if provided
        if (isset($validated['other_charges'])) {
            $invoice->otherCharges()->delete();
            foreach ($validated['other_charges'] as $charge) {
                $invoice->otherCharges()->create($charge);
            }
        }

        // Update medicines if provided
        if (isset($validated['medicines'])) {
            // Restore previous stock
            $oldMedicines = $invoice->medicines ?? [];
            foreach ($oldMedicines as $oldMed) {
                $medUnit = \App\Models\MedicineUnit::find($oldMed['medicine_unit_id']);
                if ($medUnit) {
                    $medUnit->increment('stock_quantity', $oldMed['quantity']);
                }
            }
            
            // Deduct new stock
            foreach ($validated['medicines'] as $med) {
                $medUnit = \App\Models\MedicineUnit::find($med['medicine_unit_id']);
                if ($medUnit) {
                    $medUnit->decrement('stock_quantity', $med['quantity']);
                }
            }

            // Save JSON directly
            $invoice->update(['medicines' => $validated['medicines']]);
        }

        // Trigger insurance sync which recreates treatments with exact splits and computes new total
        if ($invoice->patient_id) {
            \App\Models\TreatmentPlan::syncInvoiceForPatientAndDate(
                $invoice->patient_id, 
                \Carbon\Carbon::parse($invoice->issue_date)->format('Y-m-d')
            );
            $invoice->refresh();
        }

        return response()->json($invoice->load(['treatments', 'otherCharges', 'transactions', 'branch', 'laboratoryOrder.client']));
    }

    /**
     * Add Payment to Invoice
     * 
     * Process a payment for an invoice. Creates a payment transaction and updates
     * the invoice's paid amount and status automatically.
     * If paid amount reaches or exceeds total amount, status changes to "Paid".
     * 
     * @authenticated
     * 
     * @urlParam id string required The invoice ID. Example: inv1
     * @bodyParam amount number required Payment amount. Example: 150.00
     * @bodyParam method string required Payment method. Example: Credit Card
     * 
     * @response 200 {
     *   "id": "inv1",
     *   "patient_name": "John Doe",
     *   "patient_id": "1",
     *   "amount": 250.00,
     *   "paid_amount": 250.00,
     *   "status": "Paid",
     *   "issue_date": "2024-05-10",
     *   "due_date": "2024-06-10",
     *   "branch_id": "b1",
     *   "payment_method": "Credit Card",
     *   "treatments": [
     *     {
     *       "procedure": "Filling for #16",
     *       "cost": 250.00
     *     }
     *   ],
     *   "other_charges": [],
     *   "transactions": [
     *     {
     *       "id": "txn1",
     *       "date": "2024-05-12",
     *       "amount": 100.00,
     *       "method": "Insurance"
     *     },
     *     {
     *       "id": "txn2",
     *       "date": "2024-07-15",
     *       "amount": 150.00,
     *       "method": "Credit Card"
     *     }
     *   ]
     * }
     * @response 404 {
     *   "error": "Invoice not found"
     * }
     * 
     * @param Request $request
     * @param string $id Invoice ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function addPayment(Request $request, $id)
    {
        $invoice = Invoice::find($id);
        
        if (!$invoice) {
            return response()->json(['error' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric',
            'method' => 'required|string',
            'client_uuid' => 'nullable|string',
            'payment_date' => 'nullable|string',
        ]);

        $clientUuid = $validated['client_uuid'] ?? null;

        // Idempotency: if payment with this client_uuid was already saved, do not pay twice
        if ($clientUuid && \App\Models\PaymentTransaction::where('client_uuid', $clientUuid)->exists()) {
            $invoice->load(['treatments', 'otherCharges', 'transactions', 'patient', 'laboratoryOrder.client']);
            return response()->json($invoice);
        }

        $paymentDate = isset($validated['payment_date'])
            ? \Carbon\Carbon::parse($validated['payment_date'])->format('Y-m-d')
            : now()->format('Y-m-d');

        try {
            $transaction = \Illuminate\Support\Facades\DB::transaction(function () use ($invoice, $validated, $clientUuid, $paymentDate) {
                $locked = Invoice::lockForUpdate()->find($invoice->id);

                if ($clientUuid && \App\Models\PaymentTransaction::where('client_uuid', $clientUuid)->exists()) {
                    return null;
                }

                $tx = $locked->transactions()->create([
                    'amount' => $validated['amount'],
                    'method' => $validated['method'],
                    'client_uuid' => $clientUuid,
                    'date' => $paymentDate,
                ]);

                $newPaidAmount = $locked->paid_amount + $validated['amount'];
                $locked->update([
                    'paid_amount' => $newPaidAmount,
                    'status' => $newPaidAmount >= $locked->amount ? 'Paid' : 'Pending',
                    'payment_method' => $validated['method'],
                ]);

                return $tx;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $invoice->refresh()->load(['treatments', 'otherCharges', 'transactions', 'patient', 'laboratoryOrder.client']);
            return response()->json($invoice);
        } catch (\Exception $e) {
            if ($clientUuid && \App\Models\PaymentTransaction::where('client_uuid', $clientUuid)->exists()) {
                $invoice->refresh()->load(['treatments', 'otherCharges', 'transactions', 'patient', 'laboratoryOrder.client']);
                return response()->json($invoice);
            }
            throw $e;
        }

        if (!$transaction) {
            $invoice->refresh()->load(['treatments', 'otherCharges', 'transactions', 'patient', 'laboratoryOrder.client']);
            return response()->json($invoice);
        }

        $invoice->refresh();

        \App\Models\ActivityLog::log('pay_invoice', "Payment of {$validated['amount']} added to Invoice #{$invoice->id}", ['invoice_id' => $invoice->id, 'amount' => $validated['amount'], 'method' => $validated['method']]);

        $invoice->load(['treatments', 'otherCharges', 'transactions', 'patient', 'laboratoryOrder.client']);

        $settings = \App\Models\GlobalSetting::getSettings();
        $sendEmail = $settings->notifications['payment_success']['email'] ?? true;
        $sendSms = $settings->notifications['payment_success']['sms'] ?? false;
        $sendWa = $settings->notifications['payment_success']['whatsapp'] ?? false;
        
        $clinicName = $settings->clinic_name ?? 'Our Clinic';

        if ($sendSms && $invoice->patient && $invoice->patient->phone && $settings->deductSms()) {
            $msg = "Payment Receipt from {$clinicName}: Thank you {$invoice->patient->first_name} for your payment of {$transaction->amount} towards Invoice #{$invoice->id}.";
            \App\Services\NotificationService::sendSms($invoice->patient->phone, $msg);
        }

        if ($sendWa && $invoice->patient && $invoice->patient->phone && $settings->isWhatsappActive()) {
            $msg = "Payment Receipt from {$clinicName}: Thank you {$invoice->patient->first_name} for your payment of {$transaction->amount} towards Invoice #{$invoice->id}.";
            \App\Services\NotificationService::sendWhatsApp($invoice->patient->phone, $msg);
        }

        $sendEmail = $settings->notifications['payment_success']['email'] ?? true;

        if ($sendEmail && $invoice->patient && $invoice->patient->email) {
            try {
                Mail::to($invoice->patient->email)->send(new \App\Mail\GenericMarkdownMail(
                    'emails.payment-receipt',
                    [
                        'patientName' => $invoice->patient->first_name . ' ' . $invoice->patient->last_name,
                        'invoiceId' => $invoice->id,
                        'paymentDate' => $transaction->date,
                        'paymentMethod' => $transaction->method,
                        'amountPaid' => $transaction->amount,
                        'totalAmount' => $invoice->amount,
                        'remainingBalance' => $invoice->amount - $invoice->payments()->sum('amount'),
                        'clinicName' => $settings->clinic_name ?? 'Our Clinic',
                    ],
                    'Payment Receipt - Invoice #' . $invoice->id . ' - ' . ($settings->clinic_name ?? 'Our Clinic')
                ));
            } catch (\Exception $e) {
                logger()->error("Failed to send payment receipt email: " . $e->getMessage());
            }
        }

        return response()->json($invoice);
    }

    /**
     * Delete Invoice
     * 
     * Soft-delete an invoice and remove its treatments and charges. The invoice is excluded from
     * all income totals (Reports, Accounting monthly finances, income records) immediately.
     * Restores medicine stock. Its payment transactions are kept on record.
     * 
     * @authenticated
     * 
     * @urlParam id string required The invoice ID. Example: inv1
     * 
     * @response 200 {
     *   "success": true
     * }
     * @response 404 {
     *   "error": "Invoice not found"
     * }
     * 
     * @param string $id Invoice ID
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $invoice = Invoice::find($id);
            
            if (!$invoice) {
                return response()->json(['error' => 'Invoice not found'], 404);
            }

            // All-or-nothing: if any step fails, stock and records stay as they were,
            // so a retry cannot restore medicine stock twice.
            \Illuminate\Support\Facades\DB::transaction(function () use ($invoice) {
                // Delete associated treatment plans so they are not re-billed when billing again on the same date
                \App\Models\TreatmentPlan::whereIn('invoice_id', [$invoice->id, 'inv' . $invoice->id])->delete();

                if ($invoice->patient_id && $invoice->issue_date) {
                    \App\Models\TreatmentPlan::where('patient_id', $invoice->patient_id)
                        ->whereDate('date', $invoice->issue_date)
                        ->where(function ($q) use ($invoice) {
                            $q->whereNull('invoice_id')
                              ->orWhereIn('invoice_id', ['', (string) $invoice->id, 'inv' . $invoice->id]);
                        })
                        ->delete();
                }

                // Delete invoice line items
                $invoice->treatments()->delete();
                $invoice->otherCharges()->delete();

                // Restore medicine stock
                $medicines = $invoice->medicines ?? [];
                foreach ($medicines as $med) {
                    if (isset($med['medicine_unit_id']) && isset($med['quantity'])) {
                        $medUnit = \App\Models\MedicineUnit::find($med['medicine_unit_id']);
                        if ($medUnit) {
                            $medUnit->increment('stock_quantity', $med['quantity']);
                        }
                    }
                }

                // Soft delete: the invoice drops out of every income total (all of them filter deleted_at)
                $invoice->delete();
            });

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            \Log::error('Failed to delete invoice: ' . $e->getMessage(), ['id' => $id, 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'message' => 'An error occurred while deleting the invoice',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a printable HTML view of the invoice / receipt with facility logo and watermark.
     * GET /invoices/{id}/print
     */
    public function print(string|int $id)
    {
        $invoice = Invoice::with([
            'patient.branch.setting',
            'branch.setting',
            'treatments',
            'otherCharges',
            'transactions',
            'laboratoryOrder.client',
        ])->findOrFail($id);

        $patient = $invoice->patient;
        $branch = $invoice->branch ?? $patient?->branch ?? \App\Models\Branch::first();
        $branchSetting = $branch?->setting;
        $business = \App\Models\Business::first();
        $globalSetting = \App\Models\GlobalSetting::getSettings();

        // 1. Facility / Clinic Name (from GlobalSetting, Business, BranchSetting, Branch, env)
        $clinicName = !empty($globalSetting?->clinic_name) ? $globalSetting->clinic_name : null;
        if (!$clinicName && !empty($business?->name)) {
            $clinicName = $business->name;
        }
        if (!$clinicName && !empty($branchSetting?->clinic_name)) {
            $clinicName = $branchSetting->clinic_name;
        }
        if (!$clinicName && !empty($branch?->name)) {
            $clinicName = $branch->name;
        }
        if (!$clinicName) {
            $clinicName = env('CLINIC_NAME', env('BUSINESS_NAME', env('FACILITY_NAME', config('app.name') !== 'Laravel' ? config('app.name') : 'Dental & Oral Healthcare Clinic')));
        }

        // 2. Facility Subtitle / Tagline / Details
        $clinicDetails = !empty($globalSetting?->subtitle) ? $globalSetting->subtitle : null;
        if (!$clinicDetails && !empty($business?->details)) {
            $clinicDetails = $business->details;
        }
        if (!$clinicDetails) {
            $clinicDetails = env('CLINIC_DETAILS', env('FACILITY_DETAILS', env('CLINIC_TAGLINE', 'Comprehensive Oral Healthcare, Dental Surgery & Preventive Care')));
        }

        // 3. Facility Address
        $clinicAddress = !empty($globalSetting?->address) ? $globalSetting->address : null;
        if (!$clinicAddress && !empty($branch?->address)) {
            $clinicAddress = $branch->address;
        }
        if (!$clinicAddress) {
            $clinicAddress = env('CLINIC_ADDRESS', env('FACILITY_ADDRESS', 'Plot 12 Kampala Road, City Center, Kampala'));
        }

        // 4. Facility Phone Contact
        $clinicPhone = !empty($globalSetting?->phone) ? $globalSetting->phone : null;
        if (!$clinicPhone && !empty($branch?->phone)) {
            $clinicPhone = $branch->phone;
        }
        if (!$clinicPhone) {
            $clinicPhone = env('CLINIC_PHONE', env('FACILITY_PHONE', env('BUSINESS_PHONE', env('APP_PHONE', '+256 700 000 000'))));
        }

        // 5. Facility Email
        $clinicEmail = !empty($globalSetting?->email) ? $globalSetting->email : null;
        if (!$clinicEmail && !empty($business?->business_email)) {
            $clinicEmail = $business->business_email;
        }
        if (!$clinicEmail && !empty($branch?->email)) {
            $clinicEmail = $branch->email;
        }
        if (!$clinicEmail) {
            $clinicEmail = env('CLINIC_EMAIL', env('FACILITY_EMAIL', env('BUSINESS_EMAIL', config('mail.from.address', 'billing@dentistchair.com'))));
        }

        $receiptFooter = !empty($globalSetting?->receipt_footer) ? $globalSetting->receipt_footer : null;
        $branchName = $branch?->name ?? '';

        // 6. Facility Logo (Branch logo -> GlobalSetting logo -> Business logo)
        $clinicLogo = !empty($branch?->logo) ? $branch->logo : null;
        if (!$clinicLogo && !empty($globalSetting?->logo)) {
            $clinicLogo = $globalSetting->logo;
        }
        if (!$clinicLogo && !empty($business?->logo)) {
            $clinicLogo = $business->logo;
        }

        return view('invoices.print', compact(
            'invoice',
            'patient',
            'branch',
            'clinicName',
            'clinicDetails',
            'clinicAddress',
            'clinicPhone',
            'clinicEmail',
            'clinicLogo',
            'receiptFooter',
            'branchName'
        ));
    }
}
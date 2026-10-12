<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\AttendanceRecord;
use App\Models\InventoryItem;
use App\Models\LaboratoryOrder;
use App\Models\LabService;
use App\Models\LabServicePrice;
use App\Models\LeaveRequest;
use App\Models\StaffAdvance;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ChartData;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

/**
 * @group Management Module
 *
 * APIs for payroll, attendance, inventory, lab orders, leave requests, staff advances,
 * expenses, and chart data management.
 */
class ManagementController extends Controller
{
    /**
     * Get Payrolls
     *
     * Retrieve all payroll records, optionally filtered by branch.
     *
     * @queryParam branch_id integer Optional branch ID to filter payrolls. Example: 1
     * @response 200 {
     *   "id": 1,
     *   "employee_id": 2,
     *   "gross_pay": 5000,
     *   "net_pay": 4500,
     *   "status": "Pending"
     * }
     */
    public function getPayrolls(Request $request)
    {
        $query = Payroll::with(['employee', 'branch']);
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }
        return response()->json($query->latest()->get());
    }

    /**
     * Create Payroll
     *
     * Create a new payroll record for an employee.
     *
     * @bodyParam employee_id integer required The employee ID. Example: 2
     * @bodyParam employee_name string required The employee's name. Example: "James Wilson"
     * @bodyParam pay_period string required The pay period. Example: "August 2025"
     * @bodyParam gross_pay number required The gross pay amount. Example: 5000
     * @bodyParam deductions number required Total deductions. Example: 500
     * @bodyParam net_pay number required The net pay. Example: 4500
     * @bodyParam pay_date date required Date of payment. Example: "2025-08-31"
     * @bodyParam branch_id integer required Branch ID. Example: 1
     * @response 201 {
     *   "id": "pr5",
     *   "employee_name": "James Wilson",
     *   "status": "Pending"
     * }
     */
    public function createPayroll(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'employee_name' => 'required|string',
            'pay_period' => 'required|string',
            'gross_pay' => 'required|numeric',
            'deductions' => 'required|numeric',
            'net_pay' => 'required|numeric',
            'pay_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $payroll = Payroll::create([
            ...$validated,
            'status' => 'Pending',
        ]);

        return response()->json($payroll->load(['employee', 'branch']), 201);
    }

    /**
     * Approve Payroll
     *
     * Approve or reject a payroll record.
     *
     * @authenticated
     * @bodyParam approval_status string required The approval status. Allowed values: Pending, Approved, Rejected. Example: Approved
     */
    public function approvePayroll(Request $request, $id)
    {
        $payroll = Payroll::find($id);
        if (!$payroll) {
            return response()->json(['error' => 'Payroll record not found'], 404);
        }

        if ($request->user()->cannot('approve', $payroll)) {
            return response()->json(['message' => 'Unauthorized. Only admin and HR roles can approve salaries.'], 403);
        }

        $validated = $request->validate([
            'approval_status' => 'required|in:Pending,Approved,Rejected',
        ]);

        $status = $validated['approval_status'] === 'Approved' ? 'Paid' : 'Pending';

        $payroll->update([
            'approval_status' => $validated['approval_status'],
            'status' => $status,
        ]);

        return response()->json($payroll->load(['employee', 'branch']));
    }

    /**
     * Get Attendance Records
     *
     * Retrieve employee attendance records.
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     * @queryParam date date Optional specific date filter. Example: "2025-10-16"
     * @response 200 {
     *   "id": "att1",
     *   "employee_id": 2,
     *   "date": "2025-10-16",
     *   "status": "Present"
     * }
     */
    public function getAttendance(Request $request)
    {
        $query = AttendanceRecord::with(['employee', 'branch']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('date')) $query->where('date', $request->date);
        return response()->json($query->latest()->get());
    }

/**
 * Mark attendance for one or multiple employees.
 *
 * This endpoint can handle:
 * 1. Single employee attendance
 * 2. Multiple employee attendance (send an array of employee IDs)
 *
 * @group Attendance
 *
 * @bodyParam employee_id int|array required The employee ID or an array of employee IDs. Example: 1 or [1, 2, 3]
 * @bodyParam employee_name string nullable The employee name. If empty, it will be fetched from Employee model. Example: John Doe
 * @bodyParam date date required The date of the attendance. Example: 2025-10-16
 * @bodyParam status string required The attendance status. Allowed values: Present, Absent, On Leave. Example: Present
 * @bodyParam check_in string nullable The check-in time in HH:MM format. Example: 09:00
 * @bodyParam check_out string nullable The check-out time in HH:MM format. Example: 17:00
 * @bodyParam branch_id int required The branch ID. Example: 1
 *
 * @response 200 [
 *   {
 *      "id": "att1",
 *      "employee_id": 1,
 *      "employee_name": "John Doe",
 *      "date": "2025-10-16",
 *      "status": "Present",
 *      "check_in": "09:00",
 *      "check_out": "17:00",
 *      "branch_id": 1,
 *     
 *      "branch": {
 *          "id": 1,
 *          "name": "Main Branch"
 *      }
 *   }
 * ]
 */
    public function markAttendance(Request $request)
    {
        $data = $request->all();

        $records = [];
        $isList = array_is_list($data) || (isset($data[0]) && is_array($data[0]));

        if ($isList) {
            foreach ($data as $key => $item) {
                if (is_int($key)) {
                    if (is_array($item)) {
                        $records[] = $item;
                    } else {
                        $records[] = ['employee_id' => $item];
                    }
                }
            }
        } else {
            $records[] = $data;
        }

        $responses = [];

        foreach ($records as $record) {
            $employeeIdInput = $record['employee_id'] ?? auth()->id();
            $date = $record['date'] ?? now()->toDateString();
            $status = $record['status'] ?? null;
            $checkIn = $record['check_in'] ?? null;
            $checkOut = $record['check_out'] ?? null;
            $branchId = $record['branch_id'] ?? $request->input('branch_id');
            $employeeName = $record['employee_name'] ?? null;

            $employeeIds = is_array($employeeIdInput) ? $employeeIdInput : [$employeeIdInput];

            foreach ($employeeIds as $employeeId) {
                $validated = validator([
                    'employee_id' => $employeeId,
                    'employee_name' => $employeeName,
                    'date' => $date,
                    'status' => $status,
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'branch_id' => $branchId,
                ], [
                    'employee_id' => 'required|exists:employees,id',
                    'employee_name' => 'nullable|string',
                    'date' => 'required|date',
                    'status' => 'required|in:Present,Absent,On Leave,Late',
                    'check_in' => 'nullable|string',
                    'check_out' => 'nullable|string',
                    'branch_id' => 'required|exists:branches,id',
                ])->validate();

                if (empty($validated['employee_name'])) {
                    $employee = Employee::find($validated['employee_id']);
                    $validated['employee_name'] = $employee ? $employee->name : '';
                }

                $existing = AttendanceRecord::where('employee_id', $validated['employee_id'])
                    ->where('date', $validated['date'])
                    ->first();

                if ($existing) {
                    $existing->update($validated);
                    $responses[] = $existing->load(['employee', 'branch']);
                } else {
                    $attendance = AttendanceRecord::create($validated);
                    $responses[] = $attendance->load(['employee', 'branch']);
                }
            }
        }

        return response()->json($responses, 200);
    }

    /**
     * Get Inventory
     *
     * Retrieve inventory items.
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getInventory(Request $request)
    {
        $query = InventoryItem::with(['branch']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        $inventory = $query->latest()->paginate($request->input('per_page', 10));
        return response()->json([
            'data' => $inventory->items(),
            'meta' => [
                'current_page' => $inventory->currentPage(),
                'last_page' => $inventory->lastPage(),
                'per_page' => $inventory->perPage(),
                'total' => $inventory->total(),
            ]
        ]);
    }

    /**
     * Update Inventory Item
     *
     * Update stock, reorder level, or price for an inventory item.
     *
     * @bodyParam stock integer required Current stock quantity. Example: 100
     * @bodyParam reorder_level integer Optional Reorder threshold. Example: 10
     * @bodyParam price number Optional Price per unit. Example: 50.5
     */
    public function updateInventory(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        if (!$item) return response()->json(['error' => 'Inventory item not found'], 404);

        $validated = $request->validate([
            'stock' => 'required|integer',
            'reorder_level' => 'sometimes|integer',
            'price' => 'sometimes|numeric',
        ]);

        $item->update([
            ...$validated,
            'status' => $validated['stock'] <= 0
                ? 'Out of Stock'
                : ($validated['stock'] <= $item->reorder_level ? 'Low Stock' : 'In Stock'),
        ]);

        return response()->json($item->load('branch'));
    }

    /**
     * Get Laboratory Orders
     *
     * Retrieve lab orders.
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getLabOrders(Request $request)
    {
        $query = LaboratoryOrder::with(['client', 'branch', 'labService.prices']);

        // Branch filter
        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Search filter (searches across multiple fields)
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('doctor_name', 'like', "%{$search}%")
                  ->orWhere('item', 'like', "%{$search}%")
                  ->orWhere('tooth', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Client filter
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        // Doctor filter
        if ($request->filled('doctor_name')) {
            $query->where('doctor_name', 'like', "%{$request->doctor_name}%");
        }

        // Date range filters
        if ($request->filled('sent_date_from')) {
            $query->whereDate('sent_date', '>=', $request->sent_date_from);
        }
        if ($request->filled('sent_date_to')) {
            $query->whereDate('sent_date', '<=', $request->sent_date_to);
        }

        if ($request->filled('due_date_from')) {
            $query->whereDate('due_date', '>=', $request->due_date_from);
        }
        if ($request->filled('due_date_to')) {
            $query->whereDate('due_date', '<=', $request->due_date_to);
        }

        // Sort options
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'doctor_name', 'item', 'status', 'sent_date', 'due_date', 'applied_price', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest();
        }

        $orders = $query->paginate($request->input('per_page', 10));

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ]
        ]);
    }

    /**
     * Update Lab Order Status
     *
     * @bodyParam status string required Must be Draft, Received, In Production, Ready for Delivery, Delivered, or Cancelled. Example: "In Production"
     */
    public function updateLabOrderStatus(Request $request, $id)
    {
        try {
            $order = LaboratoryOrder::find($id);
            if (!$order) return response()->json(['error' => 'Lab order not found'], 404);

            $validated = $request->validate([
                'status' => 'required|in:Draft,Received,In Production,Ready for Delivery,Delivered,Cancelled',
            ]);

            $oldStatus = $order->status;
            $order->update($validated);

            // Generate invoice when status changes to "In Production"
            if ($validated['status'] === 'In Production' && $oldStatus !== 'In Production') {
                $this->generateLabOrderInvoice($order);
            }

            // Cancel/void invoice when lab order is cancelled
            if ($validated['status'] === 'Cancelled' && $oldStatus !== 'Cancelled') {
                $this->cancelLabOrderInvoice($order);
            }

            \App\Models\ActivityLog::log(
                'Lab Order Status Updated',
                "Lab order #{$order->id} status updated to {$validated['status']}.",
                ['lab_order_id' => $order->id, 'status' => $validated['status']]
            );

            return response()->json($order->load(['client', 'branch']));
        } catch (\Exception $e) {
            Log::error('Error updating lab order status', [
                'lab_order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    /**
     * Generate Invoice for Lab Order
     *
     * When status changes to "In Production", generate and send invoice to client.
     * If the same client already has a Pending/Overdue lab-income invoice for the
     * same branch on the same day, the new order is merged into that invoice instead
     * of creating a duplicate.
     */
    private function generateLabOrderInvoice(LaboratoryOrder $order)
    {
        try {
            $invoiceAmount = ($order->applied_price && $order->applied_price > 0)
                ? (float) $order->applied_price
                : (float) ($order->labService?->getActivePrice($order->branch_id)?->base_price ?? 0);
            $clientName    = $order->client->name ?? 'Lab Order';
            $today         = now()->format('Y-m-d');

            // ── Look for an existing same-client / same-day / same-branch invoice ──
            $existingInvoice = Invoice::where('branch_id', $order->branch_id)
                ->where('patient_name', $clientName)
                ->where('patient_id', null)        // lab-order invoices never have a patient_id
                ->where('category', 'lab-income')
                ->whereIn('status', ['Pending', 'Overdue', 'Paid'])
                ->whereDate('issue_date', $today)
                ->first();

            if ($existingInvoice) {
                // ── Merge: add amount and point this order at the existing invoice ──
                $existingInvoice->increment('amount', $invoiceAmount);

                // Re-evaluate status after the new amount
                $existingInvoice->refresh();
                if ($existingInvoice->paid_amount >= $existingInvoice->amount) {
                    $existingInvoice->status = 'Paid';
                } else {
                    $existingInvoice->status = \Carbon\Carbon::parse($existingInvoice->issue_date)->isPast()
                        ? 'Overdue'
                        : 'Pending';
                }
                $existingInvoice->save();

                $order->update(['invoice_id' => $existingInvoice->id]);
                $invoice = $existingInvoice;

                Log::info('Lab order merged into existing invoice', [
                    'invoice_id'   => $invoice->id,
                    'lab_order_id' => $order->id,
                    'added_amount' => $invoiceAmount,
                    'new_total'    => $invoice->amount,
                ]);

                \App\Models\ActivityLog::log(
                    'Lab Order Merged into Invoice',
                    "Lab order #{$order->id} (amount {$invoiceAmount}) merged into Invoice #{$invoice->id}.",
                    ['invoice_id' => $invoice->id, 'lab_order_id' => $order->id]
                );
            } else {
                // ── Create a brand-new invoice ──
                $invoice = Invoice::create([
                    'patient_name' => $clientName,
                    'patient_id'   => null,
                    'amount'       => $invoiceAmount,
                    'paid_amount'  => 0,
                    'issue_date'   => $today,
                    'due_date'     => $order->due_date ?? now()->addDays(30)->format('Y-m-d'),
                    'branch_id'    => $order->branch_id,
                    'status'       => 'Pending',
                    'category'     => 'lab-income',
                ]);

                $order->update(['invoice_id' => $invoice->id]);

                Log::info('Invoice created for lab order', [
                    'invoice_id'   => $invoice->id,
                    'lab_order_id' => $order->id,
                    'amount'       => $invoiceAmount,
                ]);

                \App\Models\ActivityLog::log(
                    'Invoice Generated from Lab Order',
                    "Invoice #{$invoice->id} generated for lab order #{$order->id}.",
                    ['invoice_id' => $invoice->id, 'lab_order_id' => $order->id]
                );
            }

            // ── Send email notification to client ──
            if ($order->client && $order->client->email) {
                try {
                    Mail::send('emails.lab-order', [
                        'clientName'    => $clientName,
                        'patientName'   => $clientName,
                        'item'          => $order->item,
                        'tooth'         => $order->tooth,
                        'status'        => $order->status,
                        'labOrder'      => $order,
                        'invoice'       => $invoice,
                        'invoiceAmount' => $invoiceAmount,
                        'dueDate'       => $order->due_date,
                    ], function ($message) use ($order, $invoice) {
                        $message->to($order->client->email)
                                ->subject("Invoice #{$invoice->id}: Lab Order #{$order->id} is in Production");
                    });
                } catch (\Exception $e) {
                    Log::error('Failed to send lab order invoice email', [
                        'invoice_id'   => $invoice->id,
                        'lab_order_id' => $order->id,
                        'error'        => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error generating lab order invoice', [
                'lab_order_id' => $order->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Cancel Invoice for Lab Order
     * When lab order is cancelled, void the associated invoice
     */
    private function cancelLabOrderInvoice(LaboratoryOrder $order)
    {
        try {
            // Find invoice by lab_order_id stored in medicines field
            $invoices = Invoice::where('branch_id', $order->branch_id)
                ->where('status', '!=', 'Cancelled')
                ->whereNotNull('medicines')
                ->get();

            foreach ($invoices as $invoice) {
                $medicines = is_string($invoice->medicines) ? json_decode($invoice->medicines, true) : $invoice->medicines;
                if (is_array($medicines) && isset($medicines['lab_order_id']) && $medicines['lab_order_id'] == $order->id) {
                    $invoice->update(['status' => 'Cancelled']);

                    \App\Models\ActivityLog::log(
                        'Invoice Cancelled from Lab Order',
                        "Invoice #{$invoice->id} cancelled due to lab order #{$order->id} cancellation.",
                        ['invoice_id' => $invoice->id, 'lab_order_id' => $order->id]
                    );

                    // Send cancellation notification to client if email exists
                    if ($order->client && $order->client->email) {
                        try {
                            Mail::send('emails.lab-order-cancelled', [
                                'clientName' => $order->client->name,
                                'labOrder' => $order,
                                'invoice' => $invoice,
                            ], function ($message) use ($order) {
                                $message->to($order->client->email)
                                        ->subject("Lab Order Cancelled: Order #{$order->id}");
                            });
                        } catch (\Exception $e) {
                            Log::error('Failed to send lab order cancellation email', [
                                'lab_order_id' => $order->id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    break;
                }
            }
        } catch (\Exception $e) {
            Log::error('Error cancelling lab order invoice', [
                'lab_order_id' => $order->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get Leave Requests
     *
     * @group Leave Management
     * @authenticated
     */
    public function getLeaveRequests(Request $request)
    {
        $query = LeaveRequest::with(['branch']);

        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('start_date')) $query->where('start_date', '>=', $request->start_date);
        if ($request->filled('end_date')) $query->where('end_date', '<=', $request->end_date);

        return response()->json($query->latest()->get());
    }

    /**
     * Update Leave Request
     *
     * @bodyParam status string required Must be Pending, Approved, or Rejected. Example: "Approved"
     */
    public function updateLeaveRequest(Request $request, $id)
    {
        $leaveRequest = LeaveRequest::find($id);
        if (!$leaveRequest) return response()->json(['error' => 'Leave request not found'], 404);

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        $oldStatus = $leaveRequest->status;
        $leaveRequest->update($validated);
        $leaveRequest->load(['employee', 'branch']);

        if ($oldStatus !== $leaveRequest->status && $leaveRequest->employee && $leaveRequest->employee->email) {
            try {
                Mail::to($leaveRequest->employee->email)->send(new \App\Mail\GenericMarkdownMail(
                    'emails.leave-status',
                    [
                        'employeeName' => $leaveRequest->employee->name,
                        'leaveType' => $leaveRequest->leave_type,
                        'startDate' => $leaveRequest->start_date,
                        'endDate' => $leaveRequest->end_date,
                        'reason' => $leaveRequest->reason,
                        'status' => $leaveRequest->status,
                    ],
                    "Leave Request Update ({$leaveRequest->status}) - Dental Management System"
                ));
            } catch (\Exception $e) {
                logger()->error("Failed to send leave status email: " . $e->getMessage());
            }
        }

        return response()->json($leaveRequest);
    }

    /**
     * Request Leave
     *
     * Employees can submit leave requests.
     *
     * @bodyParam employee_id int required Employee ID. Example: 1
     * @bodyParam leave_type string required Type of leave. Example: "Annual"
     * @bodyParam start_date date required Start date. Example: "2024-08-01"
     * @bodyParam end_date date required End date. Example: "2024-08-05"
     * @bodyParam reason string required Reason for leave. Example: "Family vacation"
     * @bodyParam branch_id string required Branch ID. Example: 1
     */
    public function createLeaveRequest(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'required|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $employee = Employee::find($validated['employee_id']);

        $leaveRequest = LeaveRequest::create([
            'employee_id'   => $employee->id,
            'employee_name' => $employee->name,
            'leave_type'    => $validated['leave_type'],
            'start_date'    => $validated['start_date'],
            'end_date'      => $validated['end_date'],
            'reason'        => $validated['reason'],
            'branch_id'     => $validated['branch_id'],
            'status'        => 'Pending',
        ]);

        return response()->json(['message' => 'Leave request submitted successfully.', 'data' => $leaveRequest], 201);
    }

    /**
     * Add Staff Advance
     *
     * Employees can submit staff advance requests.
     *
     * @bodyParam data.object required Contains advance details
     * @bodyParam data.employeeId integer required Employee ID. Example: 1
     * @bodyParam data.amount number required Amount requested. Example: 599
     * @bodyParam data.reason string required Reason for advance. Example: "Medical expenses"
     * @bodyParam data.branchId integer required Branch ID. Example: 1
     * @bodyParam data.date date required Date of advance request. Example: "2025-10-16"
     */
    public function AddStaffAdvance(Request $request)
    {
        $validated = $request->validate([
            'employeeId' => 'required|exists:employees,id',
            'amount' => 'required|numeric|min:1',
            'reason' => 'required|string|max:500',
            'branchId' => 'required|exists:branches,id',
            'date' => 'required|date',
        ]);

        $employee = Employee::find($validated['employeeId']);

        $staffAdvance = StaffAdvance::create([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'branch_id' => $validated['branchId'],
            'date' => $validated['date'],
            'status' => 'pending',
        ]);

        return response()->json($staffAdvance, 201);
    }

    /**
     * Get Staff Advances
     *
     * Retrieve a list of staff advance requests, optionally filtered by branch.
     *
     * @group Staff Management
     * @authenticated
     *
     * @queryParam branch_id integer Optional. Filter staff advances by branch ID. Example: 1
     * @queryParam status string Optional. Filter staff advances by status (pending, approved, rejected). Example: pending
     * @queryParam start_date date Optional. Filter advances from this date. Example: 2025-10-01
     * @queryParam end_date date Optional. Filter advances up to this date. Example: 2025-10-10
     *
     * @response 200 {
     *   "data": [
     *     {
     *       "id": 1,
     *       "employee_id": 5,
     *       "employee_name": "Jane Doe",
     *       "amount": "150.00",
     *       "date": "2025-10-05",
     *       "reason": "Medical expenses",
     *       "status": "pending",
     *       "branch_id": 2,
     *       "created_at": "2025-10-10T12:00:00.000000Z",
     *       "updated_at": "2025-10-10T12:00:00.000000Z",
     *       "employee": {
     *         "id": 5,
     *         "name": "Jane Doe",
     *         "email": "jane@example.com"
     *       },
     *       "branch": {
     *         "id": 2,
     *         "name": "Main Branch"
     *       }
     *     }
     *   ]
     * }
     */
    public function getStaffAdvances(Request $request)
    {
        $query = StaffAdvance::with(['employee', 'branch']);

        if ($request->filled('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        return response()->json($query->latest()->get());
    }

    /**
     * Update Staff Advance
     *
     * Approve or reject a staff advance request.
     *
     * @group Staff Management
     * @authenticated
     *
     * @urlParam id integer required The ID of the staff advance request.
     * @bodyParam status string required Must be one of: Pending, Approved, Rejected. Example: Approved
     *
     * @response 200 scenario="Success" {
     *   "id": 1,
     *   "employee_id": 2,
     *   "employee_name": "Jane Doe",
     *   "amount": "5600.00",
     *   "reason": "bb",
     *   "branch_id": 1,
     *   "date": "2025-10-16",
     *   "status": "Approved",
     *   "created_at": "2025-10-16T12:00:00.000000Z",
     *   "updated_at": "2025-10-16T12:30:00.000000Z",
     *   "employee": {
     *     "id": 2,
     *     "name": "Jane Doe",
     *     "email": "jane@example.com"
     *   },
     *   "branch": {
     *     "id": 1,
     *     "name": "Main Branch"
     *   }
     * }
     *
     * @response 404 scenario="Not Found" {
     *   "error": "Staff advance not found"
     * }
     */
    public function updateStaffAdvance(Request $request, $id)
    {
        $advance = StaffAdvance::with(['employee', 'branch'])->find($id);

        if (!$advance) {
            return response()->json(['error' => 'Staff advance not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        $oldStatus = $advance->status;
        $advance->update($validated);
        $advance->load(['employee', 'branch']);

        if ($oldStatus !== $advance->status && $advance->employee && $advance->employee->email) {
            try {
                Mail::to($advance->employee->email)->send(new \App\Mail\GenericMarkdownMail(
                    'emails.advance-status',
                    [
                        'employeeName' => $advance->employee->name,
                        'amount' => $advance->amount,
                        'reason' => $advance->reason,
                        'date' => $advance->date,
                        'status' => $advance->status,
                    ],
                    "Staff Advance Request Update ({$advance->status}) - Dental Management System"
                ));
            } catch (\Exception $e) {
                logger()->error("Failed to send staff advance status email: " . $e->getMessage());
            }
        }

        return response()->json($advance);
    }


    /**
     * Get Expenses
     *
     * Retrieve all expenses, optionally filtered by branch.
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getExpenses(Request $request)
    {
        $query = Expense::with(['branch']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        $expenses = $query->latest()->paginate($request->input('per_page', 10));
        return response()->json([
            'data' => $expenses->items(),
            'meta' => [
                'current_page' => $expenses->currentPage(),
                'last_page' => $expenses->lastPage(),
                'per_page' => $expenses->perPage(),
                'total' => $expenses->total(),
            ]
        ]);
    }

    /**
     * Get Expense Records
     *
     * Retrieve all payroll and operational expenses for the selected branch.
     *
     * @authenticated
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getExpenseRecords(Request $request)
    {
        $branch = null;
        if ($request->filled('branch_id')) {
            $branch = Branch::find($request->branch_id);
        }

        $payrollRecords = Payroll::with(['employee', 'branch'])
            ->when($request->filled('branch_id'), fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->filled('date_from') && $request->filled('date_to'), fn($q) => $q->whereBetween('pay_date', [
                \Carbon\Carbon::parse($request->date_from)->startOfDay(),
                \Carbon\Carbon::parse($request->date_to)->endOfDay()
            ]))
            ->orderByDesc('pay_date')
            ->get()
            ->map(function ($record) {
                return [
                    'type' => 'payroll',
                    'id' => $record->id,
                    'employee_id' => $record->employee_id,
                    'employee_name' => $record->employee_name,
                    'pay_period' => $record->pay_period,
                    'net_pay' => $record->net_pay,
                    'deductions' => $record->deductions,
                    'pay_date' => $record->pay_date->toDateString(),
                    'branch_id' => $record->branch_id,
                    'branch' => $record->branch ? [
                        'id' => $record->branch->id,
                        'name' => $record->branch->name,
                    ] : null,
                ];
            });

        $operationalRecords = Expense::with(['branch'])
            ->when($request->filled('branch_id'), fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->filled('date_from') && $request->filled('date_to'), fn($q) => $q->whereBetween('date', [
                \Carbon\Carbon::parse($request->date_from)->startOfDay(),
                \Carbon\Carbon::parse($request->date_to)->endOfDay()
            ]))
            ->orderByDesc('date')
            ->get()
            ->map(function ($record) {
                return [
                    'type' => 'operation',
                    'id' => $record->id,
                    'category' => $record->category,
                    'description' => $record->description,
                    'amount' => $record->amount,
                    'date' => $record->date->toDateString(),
                    'branch_id' => $record->branch_id,
                    'branch' => $record->branch ? [
                        'id' => $record->branch->id,
                        'name' => $record->branch->name,
                    ] : null,
                ];
            });

        $records = $payrollRecords->concat($operationalRecords)->sortByDesc(function ($record) {
            return $record['type'] === 'payroll' ? $record['pay_date'] : $record['date'];
        })->values();

        $totalPayroll = $payrollRecords->sum('net_pay');
        $totalOperational = $operationalRecords->sum('amount');
        $totalExpenses = $totalPayroll + $totalOperational;

        return response()->json([
            'branch' => $branch ? [
                'id' => $branch->id,
                'name' => $branch->name,
            ] : null,
            'total' => number_format($totalExpenses, 2, '.', ''),
            'total_payroll' => number_format($totalPayroll, 2, '.', ''),
            'total_operational_expenses' => number_format($totalOperational, 2, '.', ''),
            'records' => $records,
        ]);
    }

    /**
     * Get Chart Data
     *
     * Retrieve chart data for analytics.
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getChartData(Request $request)
    {
        $query = ChartData::with(['branch']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        return response()->json($query->get());
    }

    /**
     * Get Practice Trends
     *
     * Retrieve revenue and activity trends for the selected branch.
     *
     * @authenticated
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getPracticeTrends(Request $request)
    {
        $branchId = $request->branch_id;
        $today = Carbon::today();
        $startDate = $today->copy()->subDays(29);

        $revenueTrend = Invoice::query()
            ->selectRaw('DATE(issue_date) as date, SUM(paid_amount) as total_revenue')
            ->whereBetween('issue_date', [$startDate, $today])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $appointmentsTrend = Appointment::query()
            ->selectRaw('DATE(date) as date, COUNT(*) as total_appointments')
            ->whereBetween('date', [$startDate, $today])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $newPatientsTrend = Patient::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as new_patients')
            ->whereBetween('created_at', [$startDate, $today])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $trendData = [];
        for ($date = $startDate->copy(); $date->lte($today); $date->addDay()) {
            $formattedDate = $date->toDateString();
            $trendData[] = [
                'date' => $formattedDate,
                'revenue' => $revenueTrend->get($formattedDate)->total_revenue ?? 0,
                'appointments' => $appointmentsTrend->get($formattedDate)->total_appointments ?? 0,
                'new_patients' => $newPatientsTrend->get($formattedDate)->new_patients ?? 0,
            ];
        }

        return response()->json([
            'branch_id' => $branchId,
            'from' => $startDate->toDateString(),
            'to' => $today->toDateString(),
            'trend' => $trendData,
        ]);
    }

    /**
     * Get Financial Summary
     *
     * Retrieve total income, total expenses, net profit, and income vs expenses
     * over the last 6 months for the selected branch.
     *
     * @authenticated
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getFinancialSummary(Request $request)
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
                    'status' => 'Paid',
                    'paid_amount' => DB::raw('CASE WHEN paid_amount <= 0 THEN amount ELSE paid_amount END'),
                ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Radiology self-heal error in getFinancialSummary: ' . $e->getMessage());
        }

        $branchId = $request->branch_id;
        $hasBranch = $branchId && $branchId !== 'all';
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $startDate = \Carbon\Carbon::parse($request->date_from)->startOfDay();
            $endDate = \Carbon\Carbon::parse($request->date_to)->endOfDay();
        } else {
            $today = Carbon::today();
            $startDate = $today->copy()->subMonthsNoOverflow(5)->startOfMonth();
            $endDate = $today->copy()->endOfMonth();
        }

        $incomeQuery = Invoice::query()
            ->whereBetween('issue_date', [$startDate, $endDate])
            ->when($hasBranch, fn($q) => $q->where('branch_id', $branchId));

        $expenseQuery = Expense::query()
            ->whereBetween('date', [$startDate, $endDate])
            ->when($hasBranch, fn($q) => $q->where('branch_id', $branchId));

        $payrollQuery = Payroll::query()
            ->whereBetween('pay_date', [$startDate, $endDate])
            ->when($hasBranch, fn($q) => $q->where('branch_id', $branchId));

        $totalIncome = $incomeQuery->sum(DB::raw('CASE WHEN paid_amount > 0 THEN paid_amount ELSE amount END'));
        $totalOtherExpenses = $expenseQuery->sum('amount');
        $totalPayroll = $payrollQuery->sum('net_pay');
        $totalExpenses = $totalOtherExpenses + $totalPayroll;
        $netProfit = $totalIncome - $totalExpenses;

        // Calculate income by category
        $allCategories = ['lab-income', 'pharmacy-income', 'procedure-income', 'radiology-income', 'other'];
        $incomeByCategory = [];
        foreach ($allCategories as $category) {
            $categoryIncome = (clone $incomeQuery)
                ->where(function ($q) use ($category) {
                    if ($category === 'other') {
                        $q->whereNull('category')->orWhere('category', '');
                    } else {
                        $q->where('category', $category);
                    }
                })
                ->sum(DB::raw('CASE WHEN paid_amount > 0 THEN paid_amount ELSE amount END'));
            $incomeByCategory[$category] = number_format($categoryIncome, 2, '.', '');
        }

        $incomeByMonth = $incomeQuery
            ->selectRaw("DATE_FORMAT(issue_date, '%Y-%m') as month, SUM(CASE WHEN paid_amount > 0 THEN paid_amount ELSE amount END) as income")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $otherExpensesByMonth = $expenseQuery
            ->selectRaw("DATE_FORMAT(date, '%Y-%m') as month, SUM(amount) as expense")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $payrollByMonth = $payrollQuery
            ->selectRaw("DATE_FORMAT(pay_date, '%Y-%m') as month, SUM(net_pay) as payroll")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $financialSummary = [];
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addMonthNoOverflow()) {
            $monthKey = $date->format('Y-m');
            $income = $incomeByMonth->get($monthKey)->income ?? 0;
            $expenses = ($otherExpensesByMonth->get($monthKey)->expense ?? 0)
                + ($payrollByMonth->get($monthKey)->payroll ?? 0);

            $financialSummary[] = [
                'month' => $monthKey,
                'income' => number_format($income, 2, '.', ''),
                'expenses' => number_format($expenses, 2, '.', ''),
                'net_profit' => number_format($income - $expenses, 2, '.', ''),
            ];
        }

        return response()->json([
            'branch_id' => $branchId,
            'total_income' => number_format($totalIncome, 2, '.', ''),
            'income_by_category' => $incomeByCategory,
            'total_expenses' => number_format($totalExpenses, 2, '.', ''),
            'net_profit' => number_format($netProfit, 2, '.', ''),
            'from' => $startDate->toDateString(),
            'to' => $endDate->toDateString(),
            'monthlyFinancials' => $financialSummary,
            'financial_summary' => $financialSummary,
        ]);
    }

    /**
     * Get Practice Summary
     *
     * Retrieve dashboard metrics for a selected branch, including total revenue,
     * active patients, appointments in the last 30 days, and new patients.
     * Also returns upcoming appointments for today and recent patients.
     *
     * @authenticated
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     *
     * @response 200 {
     *   "total_revenue": 1200.00,
     *   "active_patients": 45,
     *   "appointments_last_30_days": 28,
     *   "new_patients_last_30_days": 6,
     *   "upcoming_appointments": [
     *     {
     *       "id": "a12",
     *       "patient_name": "John Doe",
     *       "doctor_name": "Dr. Lisa Cuddy",
     *       "time": "09:30 AM",
     *       "status": "Scheduled"
     *     }
     *   ],
     *   "recent_patients": [
     *     {
     *       "id": 7,
     *       "first_name": "Jane",
     *       "last_name": "Smith",
     *       "last_visit": "2026-06-20"
     *     }
     *   ]
     * }
     */
    public function getPracticeSummary(Request $request)
    {
        $branchId = $request->branch_id;
        $today = Carbon::today();
        $last30Days = $today->copy()->subDays(30);

        $invoiceQuery = Invoice::query();
        $patientQuery = Patient::query();
        $appointmentQuery = Appointment::query();

        if ($branchId) {
            $invoiceQuery->where('branch_id', $branchId);
            $patientQuery->where('branch_id', $branchId);
            $appointmentQuery->where('branch_id', $branchId);
        }

        $totalRevenue = $invoiceQuery->sum('paid_amount');
        $activePatients = $patientQuery->where('status', 'Active')->count();
        $appointmentsLast30Days = $appointmentQuery->whereBetween('date', [$last30Days, $today])->count();
        $newPatientsLast30Days = $patientQuery->where('created_at', '>=', $last30Days)->count();

        $upcomingAppointments = Appointment::with(['patient', 'doctor'])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereDate('date', $today)
            ->where('status', 'Scheduled')
            ->orderBy('time')
            ->get()
            ->map(function ($appointment) {
                return [
                    'id' => $appointment->id,
                    'patient_name' => $appointment->patient_name,
                    'doctor_name' => $appointment->doctor_name,
                    'date' => $appointment->date->toDateString(),
                    'time' => $appointment->time,
                    'status' => $appointment->status,
                ];
            });

        $recentPatients = Patient::when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('last_visit')
            ->take(5)
            ->get(['id', 'first_name', 'last_name', 'last_visit']);

        return response()->json([
            'total_revenue' => number_format($totalRevenue, 2, '.', ''),
            'active_patients' => $activePatients,
            'appointments_last_30_days' => $appointmentsLast30Days,
            'new_patients_last_30_days' => $newPatientsLast30Days,
            'practice_overview' => 'A summary of your practice’s performance.',
            'upcoming_appointments' => $upcomingAppointments,
            'recent_patients' => $recentPatients,
        ]);
    }

    /**
     * Get Reports Overview
     *
     * Retrieve a consolidated summary of all report types (Financial, Appointment, Demographics)
     * which serves as the entry point/dashboard for reporting needs.
     *
     * @authenticated
     *
     * @queryParam branch_id integer Optional branch filter. Example: 1
     */
    public function getReportsOverview(Request $request)
    {
        $branchId = $request->branch_id;
        $startDate = $request->start_date;
        $endDate = $request->end_date;

        // 1. Financial Summary
        $invoiceQuery = Invoice::query();
        $expenseQuery = Expense::query();
        $payrollQuery = Payroll::query();

        if ($branchId) {
            $invoiceQuery->where('branch_id', $branchId);
            $expenseQuery->where('branch_id', $branchId);
            $payrollQuery->where('branch_id', $branchId);
        }

        if ($startDate) {
            $invoiceQuery->where('issue_date', '>=', $startDate);
            $expenseQuery->where('date', '>=', $startDate);
            $payrollQuery->where('pay_date', '>=', $startDate);
        }

        if ($endDate) {
            $invoiceQuery->where('issue_date', '<=', $endDate);
            $expenseQuery->where('date', '<=', $endDate);
            $payrollQuery->where('pay_date', '<=', $endDate);
        }

        $totalIncome = $invoiceQuery->sum('paid_amount');
        $totalOtherExpenses = $expenseQuery->sum('amount');
        $totalPayroll = $payrollQuery->sum('net_pay');
        $totalExpenses = $totalOtherExpenses + $totalPayroll;
        $netProfit = $totalIncome - $totalExpenses;

        // 2. Appointment Summary
        $appointmentsQuery = Appointment::query()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        if ($startDate) {
            $appointmentsQuery->where('date', '>=', $startDate);
        }

        if ($endDate) {
            $appointmentsQuery->where('date', '<=', $endDate);
        }

        $appointments = $appointmentsQuery->get(['status']);

        $totalAppointments = $appointments->count();
        $appointmentStatusDistribution = [];
        foreach ($appointments as $app) {
            $status = $app->status ?: 'Unknown';
            $appointmentStatusDistribution[$status] = ($appointmentStatusDistribution[$status] ?? 0) + 1;
        }

        // 3. Patient Summary
        $patientsQuery = Patient::query()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        if ($startDate) {
            $patientsQuery->where('created_at', '>=', $startDate);
        }

        if ($endDate) {
            $patientsQuery->where('created_at', '<=', $endDate);
        }

        $patients = $patientsQuery->get(['date_of_birth', 'status', 'gender']);

        $totalPatients = $patients->count();
        $patientStatusDistribution = [];
        $genderDistribution = [];
        $ageDistribution = [
            'Under 18' => 0,
            '18-35' => 0,
            '36-50' => 0,
            'Over 50' => 0,
            'Unknown' => 0,
        ];

        foreach ($patients as $p) {
            $status = $p->status ?: 'Unknown';
            $patientStatusDistribution[$status] = ($patientStatusDistribution[$status] ?? 0) + 1;

            $gender = $p->gender ?: 'Unknown';
            $genderDistribution[$gender] = ($genderDistribution[$gender] ?? 0) + 1;

            if ($p->date_of_birth) {
                $age = \Carbon\Carbon::parse($p->date_of_birth)->age;
                if ($age < 18) {
                    $ageDistribution['Under 18']++;
                } elseif ($age <= 35) {
                    $ageDistribution['18-35']++;
                } elseif ($age <= 50) {
                    $ageDistribution['36-50']++;
                } else {
                    $ageDistribution['Over 50']++;
                }
            } else {
                $ageDistribution['Unknown']++;
            }
        }

        return response()->json([
            'categories' => [
                'financial' => [
                    'title' => 'Financial Reports',
                    'description' => 'Analyze clinic income, expenses, and net profit trends.',
                ],
                'appointments' => [
                    'title' => 'Appointment Statistics',
                    'description' => 'Track appointment volumes, status distribution, and attendance.',
                ],
                'demographics' => [
                    'title' => 'Patient Demographics',
                    'description' => 'Understand patient distributions by age, gender, and status.',
                ],
            ],
            'financial_summary' => [
                'total_income' => number_format($totalIncome, 2, '.', ''),
                'total_expenses' => number_format($totalExpenses, 2, '.', ''),
                'net_profit' => number_format($netProfit, 2, '.', ''),
            ],
            'appointment_summary' => [
                'total_appointments' => $totalAppointments,
                'status_distribution' => $appointmentStatusDistribution,
            ],
            'patient_summary' => [
                'total_patients' => $totalPatients,
                'status_distribution' => $patientStatusDistribution,
                'gender_distribution' => $genderDistribution,
                'age_distribution' => $ageDistribution,
            ],
        ]);
    }

        private function resolveOrCreateClient(Request $request, ?array $itemData = null): ?Client
    {
        // 1. Try finding by numeric clientId / client_id
        $clientId = $itemData['clientId'] ?? $itemData['client_id'] ?? $request->input('clientId', $request->input('client_id'));
        if (!empty($clientId) && is_numeric($clientId)) {
            $client = Client::find($clientId);
            if ($client) {
                return $client;
            }
        }

        // 2. Extract client name from various possible fields
        $rawClient = $itemData['client'] ?? $request->input('client');
        $clientName = null;

        if (is_array($rawClient)) {
            $clientName = $rawClient['name'] ?? null;
        } elseif (is_string($rawClient) && !empty(trim($rawClient))) {
            $clientName = trim($rawClient);
        }

        if (empty($clientName)) {
            $clientName = $itemData['clientName'] 
                ?? $itemData['client_name'] 
                ?? $request->input('clientName', $request->input('client_name'));
        }

        // If clientId is a non-numeric string (e.g. "Apex Dental"), treat it as the name
        if (empty($clientName) && !empty($clientId) && !is_numeric($clientId)) {
            $clientName = trim((string)$clientId);
        }

        if (empty($clientName)) {
            $clientName = $itemData['name'] ?? $request->input('name');
        }

        // 3. If client name found, search or create
        if (!empty($clientName)) {
            $clientName = trim((string)$clientName);

            // Case-insensitive lookup
            $client = Client::whereRaw('LOWER(name) = ?', [strtolower($clientName)])->first();

            if (!$client) {
                $email = $itemData['clientEmail'] 
                    ?? $itemData['client_email'] 
                    ?? $itemData['email'] 
                    ?? (is_array($rawClient) ? ($rawClient['email'] ?? null) : null)
                    ?? $request->input('clientEmail', $request->input('client_email', $request->input('email')));

                $phone = $itemData['clientPhone'] 
                    ?? $itemData['client_phone'] 
                    ?? $itemData['phone'] 
                    ?? (is_array($rawClient) ? ($rawClient['phone'] ?? null) : null)
                    ?? $request->input('clientPhone', $request->input('client_phone', $request->input('phone')));

                $address = $itemData['clientAddress'] 
                    ?? $itemData['client_address'] 
                    ?? $itemData['address'] 
                    ?? (is_array($rawClient) ? ($rawClient['address'] ?? null) : null)
                    ?? $request->input('clientAddress', $request->input('client_address', $request->input('address')));

                $client = Client::create([
                    'name'    => $clientName,
                    'email'   => !empty($email) ? trim($email) : null,
                    'phone'   => !empty($phone) ? trim($phone) : null,
                    'address' => !empty($address) ? trim($address) : null,
                ]);

                \App\Models\ActivityLog::log(
                    'Client Created',
                    "Client {$client->name} was automatically created.",
                    ['client_id' => $client->id]
                );
            }

            return $client;
        }

        return null;
    }

    
    /**
     * Resolve existing LabService by ID or Name, or create it automatically if not found.
     * Also creates initial LabServicePrice if a price is supplied.
     */
    private function resolveOrCreateLabService($itemData, $branchId, ?float $appliedPrice = null): ?LabService
    {
        // 1. Try finding by numeric lab_service_id / labServiceId
        $serviceId = is_array($itemData)
            ? ($itemData['lab_service_id'] ?? $itemData['labServiceId'] ?? null)
            : ($itemData instanceof Request ? $itemData->input('labServiceId', $itemData->input('lab_service_id')) : null);

        if (!empty($serviceId) && is_numeric($serviceId)) {
            $service = LabService::find($serviceId);
            if ($service) {
                return $service;
            }
        }

        // 2. Extract service name from various possible fields
        $serviceName = null;
        if (is_array($itemData)) {
            $serviceName = $itemData['service_name'] 
                ?? $itemData['serviceName'] 
                ?? $itemData['lab_service_name'] 
                ?? $itemData['labServiceName'] 
                ?? null;

            if (empty($serviceName) && !empty($serviceId) && !is_numeric($serviceId)) {
                $serviceName = trim((string)$serviceId);
            }

            if (empty($serviceName) && !empty($itemData['item'])) {
                $serviceName = trim((string)$itemData['item']);
            }
        } elseif ($itemData instanceof Request) {
            $serviceName = $itemData->input('serviceName', $itemData->input('service_name', $itemData->input('labServiceName', $itemData->input('lab_service_name'))));
            if (empty($serviceName) && !empty($serviceId) && !is_numeric($serviceId)) {
                $serviceName = trim((string)$serviceId);
            }
            if (empty($serviceName) && $itemData->filled('item')) {
                $serviceName = trim((string)$itemData->input('item'));
            }
        }

        if (empty($serviceName)) {
            return null;
        }

        $serviceName = trim((string)$serviceName);

        // 3. Search case-insensitive by service_name in branch, then globally
        $service = LabService::where('branch_id', $branchId)
            ->whereRaw('LOWER(service_name) = ?', [strtolower($serviceName)])
            ->first();

        if (!$service) {
            $service = LabService::whereRaw('LOWER(service_name) = ?', [strtolower($serviceName)])
                ->first();
        }

        // 4. If not found, automatically create the LabService
        if (!$service) {
            $category = is_array($itemData) ? ($itemData['category'] ?? 'General') : ($itemData->input('category', 'General'));
            $service = LabService::create([
                'service_name' => $serviceName,
                'category'     => $category ?: 'General',
                'branch_id'    => $branchId,
                'is_active'    => true,
            ]);

            // If a price was provided, create active price record for this branch
            if ($appliedPrice !== null && $appliedPrice > 0) {
                LabServicePrice::create([
                    'lab_service_id' => $service->id,
                    'lab'            => 'Default',
                    'base_price'     => $appliedPrice,
                    'branch_id'      => $branchId,
                    'is_active'      => true,
                ]);
            }

            \App\Models\ActivityLog::log(
                'Lab Service Created',
                "Lab service {$service->service_name} was automatically created.",
                ['lab_service_id' => $service->id, 'branch_id' => $branchId]
            );
        }

        return $service;
    }

    public function createBatchLabOrders(Request $request)
    {
        $raw = $request->all();

        // Support array list payload: [ {...}, {...} ]
        if (array_is_list($raw) || (isset($raw[0]) && is_array($raw[0]))) {
            $createdOrders = [];
            DB::beginTransaction();
            try {
                foreach ($raw as $orderData) {
                    $itemClient = $this->resolveOrCreateClient($request, $orderData);
                    $clientId = $itemClient?->id ?? ($orderData['clientId'] ?? $orderData['client_id'] ?? null);
                    $branchId = $orderData['branchId'] ?? $orderData['branch_id'] ?? null;
                    $doctorName = $orderData['doctorName'] ?? $orderData['doctor_name'] ?? null;
                    $item = $orderData['item'] ?? null;
                    $tooth = !empty($orderData['tooth']) ? (int) $orderData['tooth'] : null;
                    $status = $orderData['status'] ?? 'Draft';
                    $sentDate = $orderData['sentDate'] ?? $orderData['sent_date'] ?? null;
                    $dueDate = $orderData['dueDate'] ?? $orderData['due_date'] ?? null;
                    $receivedDate = $orderData['receivedDate'] ?? $orderData['received_date'] ?? null;
                    $shade = $orderData['shade'] ?? null;
                    $material = $orderData['material'] ?? null;
                    $instructions = $orderData['instructions'] ?? null;
                    $appliedPrice = $orderData['applied_price'] ?? $orderData['appliedPrice'] ?? null;
                    $service = $this->resolveOrCreateLabService($orderData, $branchId, $appliedPrice !== null ? (float)$appliedPrice : null);
                    $labServiceId = $service?->id ?? ($orderData['lab_service_id'] ?? $orderData['labServiceId'] ?? null);

                    if ($service && ($appliedPrice === null || $appliedPrice === '' || $appliedPrice == 0)) {
                        $activePrice = $service->getActivePrice($branchId);
                        $appliedPrice = $activePrice ? (float) $activePrice->base_price : 0;
                    }
                    if (empty($item) && $service) {
                        $item = $service->service_name;
                    }

                $labOrder = LaboratoryOrder::create([
                        'client_id' => $clientId,
                        'doctor_name' => $doctorName,
                        'item' => $item,
                        'tooth' => $tooth,
                        'status' => $status,
                        'sent_date' => $sentDate,
                        'due_date' => $dueDate,
                        'branch_id' => $branchId,
                        'received_date' => $receivedDate,
                        'shade' => $shade,
                        'material' => $material,
                        'instructions' => $instructions,
                        'lab_service_id' => $labServiceId,
                        'applied_price' => $appliedPrice,
                    ]);

                    if ($status === 'In Production') {
                        $this->generateLabOrderInvoice($labOrder);
                    }

                    $createdOrders[] = $labOrder->load(['client', 'branch', 'labService']);
                }

                DB::commit();

                \App\Models\ActivityLog::log(
                    'Lab Orders Created (Batch)',
                    count($createdOrders) . " lab order(s) created.",
                    [
                        'lab_order_ids' => collect($createdOrders)->pluck('id')->toArray(),
                        'count' => count($createdOrders),
                    ]
                );

                return response()->json($createdOrders, 201);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error creating batch lab orders (list payload)', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                return response()->json(['message' => 'Server Error'], 500);
            }
        }

        // Support single order with 'item' passed to batch endpoint
        if (!$request->has('items') && $request->filled('item')) {
            $request->merge([
                'items' => [[
                    'item' => $request->input('item'),
                    'tooth' => $request->input('tooth'),
                    'lab_service_id' => $request->input('lab_service_id', $request->input('labServiceId')),
                    'applied_price' => $request->input('applied_price', $request->input('appliedPrice')),
                    'material' => $request->input('material'),
                    'shade' => $request->input('shade'),
                    'instructions' => $request->input('instructions'),
                ]]
            ]);
        }

        // Auto-resolve or create client if not found
        $client = $this->resolveOrCreateClient($request);
        if ($client) {
            $request->merge(['clientId' => $client->id]);
        }

        // Auto-resolve or create lab services for items
        $items = $request->input('items', []);
        $batchBranchId = $request->input('branchId', $request->input('branch_id'));
        foreach ($items as &$it) {
            $itPrice = $it['applied_price'] ?? $it['appliedPrice'] ?? null;
            $itService = $this->resolveOrCreateLabService($it, $batchBranchId, $itPrice !== null ? (float)$itPrice : null);
            if ($itService) {
                $it['lab_service_id'] = $itService->id;
                $it['labServiceId'] = $itService->id;
                if ($itPrice === null || $itPrice === '' || $itPrice == 0) {
                    $activePrice = $itService->getActivePrice($batchBranchId);
                    if ($activePrice) {
                        $it['applied_price'] = (float) $activePrice->base_price;
                        $it['appliedPrice'] = (float) $activePrice->base_price;
                    }
                }
                if (empty($it['item'])) {
                    $it['item'] = $itService->service_name;
                }
            }
        }
        unset($it);
        $request->merge(['items' => $items]);

        // Normalize camelCase / snake_case top-level keys
        $request->merge([
            'clientId' => $request->input('clientId', $request->input('client_id')),
            'branchId' => $request->input('branchId', $request->input('branch_id')),
            'doctorName' => $request->input('doctorName', $request->input('doctor_name')),
            'sentDate' => $request->input('sentDate', $request->input('sent_date')),
            'dueDate' => $request->input('dueDate', $request->input('due_date')),
            'receivedDate' => $request->input('receivedDate', $request->input('received_date')),
            'status' => $request->input('status', 'Draft'),
        ]);

        $validated = $request->validate([
            'clientId' => 'required|exists:clients,id',
            'branchId' => 'required|exists:branches,id',
            'doctorName' => 'nullable|string',
            'status' => 'required|in:Draft,Received,In Production,Ready for Delivery,Delivered,Cancelled',
            'sentDate' => 'nullable|date',
            'dueDate' => 'nullable|date',
            'receivedDate' => 'nullable|date',
            'instructions' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item' => 'required|string',
            'items.*.tooth' => 'nullable|integer',
            'items.*.lab_service_id' => 'nullable',
            'items.*.labServiceId' => 'nullable',
            'items.*.service_name' => 'nullable|string',
            'items.*.serviceName' => 'nullable|string',
            'items.*.applied_price' => 'nullable|numeric|min:0',
            'items.*.appliedPrice' => 'nullable|numeric|min:0',
            'items.*.material' => 'nullable|string',
            'items.*.shade' => 'nullable|string',
            'items.*.instructions' => 'nullable|string',
        ]);

        $client = Client::find($validated['clientId']);
        $createdOrders = [];

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $itemData) {
                $branchId = $itemData['branch_id'] ?? $itemData['branchId'] ?? $validated['branchId'];
                $orderStatus = $itemData['status'] ?? $validated['status'];
                $sentDate = $itemData['sent_date'] ?? $itemData['sentDate'] ?? ($validated['sentDate'] ?? null);
                $dueDate = $itemData['due_date'] ?? $itemData['dueDate'] ?? ($validated['dueDate'] ?? null);
                $receivedDate = $itemData['received_date'] ?? $itemData['receivedDate'] ?? ($validated['receivedDate'] ?? null);
                $doctorName = $itemData['doctor_name'] ?? $itemData['doctorName'] ?? ($validated['doctorName'] ?? null);
                $instructions = $itemData['instructions'] ?? ($validated['instructions'] ?? null);

                $appliedPrice = $itemData['applied_price'] ?? $itemData['appliedPrice'] ?? null;
                $service = $this->resolveOrCreateLabService($itemData, $branchId, $appliedPrice !== null ? (float)$appliedPrice : null);
                $labServiceId = $service?->id ?? ($itemData['lab_service_id'] ?? $itemData['labServiceId'] ?? null);

                if ($service && ($appliedPrice === null || $appliedPrice === '' || $appliedPrice == 0)) {
                    $activePrice = $service->getActivePrice($branchId);
                    $appliedPrice = $activePrice ? (float) $activePrice->base_price : 0;
                }

                // If item specifies client name or client ID
                $itemClient = $this->resolveOrCreateClient($request, $itemData) ?? $client;
                $finalClientId = $itemClient?->id ?? $validated['clientId'];

                $labOrder = LaboratoryOrder::create([
                    'client_id' => $finalClientId,
                    'doctor_name' => $doctorName,
                    'item' => $itemData['item'],
                    'tooth' => !empty($itemData['tooth']) ? (int) $itemData['tooth'] : null,
                    'status' => $orderStatus,
                    'sent_date' => $sentDate,
                    'due_date' => $dueDate,
                    'branch_id' => $branchId,
                    'received_date' => $receivedDate,
                    'shade' => $itemData['shade'] ?? null,
                    'material' => $itemData['material'] ?? null,
                    'instructions' => $instructions,
                    'lab_service_id' => $labServiceId,
                    'applied_price' => $appliedPrice,
                ]);

                if ($orderStatus === 'In Production') {
                    $this->generateLabOrderInvoice($labOrder);
                }

                $createdOrders[] = $labOrder->load(['client', 'branch', 'labService']);
            }

            DB::commit();

            \App\Models\ActivityLog::log(
                'Lab Orders Created (Batch)',
                count($createdOrders) . " lab order(s) for {$client?->name} were created.",
                [
                    'lab_order_ids' => collect($createdOrders)->pluck('id')->toArray(),
                    'client_id' => $finalClientId,
                    'count' => count($createdOrders),
                ]
            );

            return response()->json($createdOrders, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating batch lab orders', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    public function createLabOrder(Request $request)
    {
        if ($request->has('items') && is_array($request->input('items'))) {
            return $this->createBatchLabOrders($request);
        }

        // Auto-resolve or create client if not found
        $client = $this->resolveOrCreateClient($request);
        if ($client) {
            $request->merge(['clientId' => $client->id]);
        }

        // Auto-resolve or create lab service if needed
        $singleBranchId = $request->input('branchId', $request->input('branch_id'));
        $singleAppliedPrice = $request->input('appliedPrice', $request->input('applied_price'));
        $singleService = $this->resolveOrCreateLabService($request, $singleBranchId, $singleAppliedPrice !== null ? (float)$singleAppliedPrice : null);
        if ($singleService) {
            $request->merge(['labServiceId' => $singleService->id]);
            if (!$request->filled('appliedPrice') && !$request->filled('applied_price')) {
                $activePrice = $singleService->getActivePrice($singleBranchId);
                if ($activePrice) {
                    $request->merge(['appliedPrice' => (float) $activePrice->base_price]);
                }
            }
            if (!$request->filled('item')) {
                $request->merge(['item' => $singleService->service_name]);
            }
        }

        $validated = $request->validate([
            'clientId' => 'required|exists:clients,id',
            'doctorName' => 'nullable|string',
            'item' => 'required|string',
            'tooth' => 'nullable|integer',
            'status' => 'required|in:Draft,Received,In Production,Ready for Delivery,Delivered,Cancelled',
            'sentDate' => 'nullable|date',
            'dueDate' => 'nullable|date',
            'branchId' => 'required|exists:branches,id',
            'receivedDate' => 'nullable|date',
            'shade' => 'nullable|string',
            'material' => 'nullable|string',
            'instructions' => 'nullable|string',
            'labServiceId' => 'nullable',
            'appliedPrice' => 'nullable|numeric|min:0',
            'serviceName' => 'nullable|string',
            'service_name' => 'nullable|string',
        ]);

        try {
            $client = Client::find($validated['clientId']);

            // Determine applied price
            $appliedPrice = null;
            if ($validated['labServiceId'] ?? null) {
                $service = LabService::find($validated['labServiceId']);
                $activePrice = $service?->getActivePrice();

                // Use provided price or active price from service
                $appliedPrice = $validated['appliedPrice'] ?? $activePrice?->base_price;
            } else {
                // Use manually provided price
                $appliedPrice = $validated['appliedPrice'] ?? null;
            }

            $labOrder = LaboratoryOrder::create([
                'client_id' => $validated['clientId'],
                'doctor_name' => $validated['doctorName'] ?? null,
                'item' => $validated['item'],
                'tooth' => $validated['tooth'] ?? null,
                'status' => $validated['status'],
                'sent_date' => $validated['sentDate'] ?? null,
                'due_date' => $validated['dueDate'] ?? null,
                'branch_id' => $validated['branchId'],
                'received_date' => $validated['receivedDate'] ?? null,
                'shade' => $validated['shade'] ?? null,
                'material' => $validated['material'] ?? null,
                'instructions' => $validated['instructions'] ?? null,
                'lab_service_id' => $validated['labServiceId'] ?? null,
                'applied_price' => $appliedPrice,
            ]);

            // Generate invoice if status is "In Production"
            if ($validated['status'] === 'In Production') {
                $this->generateLabOrderInvoice($labOrder);
            }

            \App\Models\ActivityLog::log(
                'Lab Order Created',
                "Lab order for {$client?->name} was created.",
                ['lab_order_id' => $labOrder->id, 'client_id' => $validated['clientId']]
            );

            return response()->json($labOrder->load(['client', 'branch']), 201);
        } catch (\Exception $e) {
            Log::error('Error creating lab order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    public function updateLabOrder(Request $request, $id)
    {
        $labOrder = LaboratoryOrder::find($id);
        if (!$labOrder) {
            return response()->json(['message' => 'Lab order not found'], 404);
        }

        $validated = $request->validate([
            'clientId' => 'sometimes|required|exists:clients,id',
            'doctorName' => 'nullable|string',
            'item' => 'sometimes|required|string',
            'tooth' => 'nullable|integer',
            'status' => 'sometimes|required|in:Draft,Received,In Production,Ready for Delivery,Delivered,Cancelled',
            'sentDate' => 'sometimes|nullable|date',
            'dueDate' => 'sometimes|nullable|date',
            'receivedDate' => 'nullable|date',
            'shade' => 'nullable|string',
            'material' => 'nullable|string',
            'instructions' => 'nullable|string',
            'labServiceId' => 'nullable|exists:lab_services,id',
            'appliedPrice' => 'nullable|numeric|min:0',
            'invoiceId' => 'nullable|exists:invoices,id',
        ]);

        try {
            // Handle applied price logic
            $upBranchId = $labOrder->branch_id;
            $upPrice = $validated['appliedPrice'] ?? $request->input('applied_price', null);
            $service = $this->resolveOrCreateLabService($request, $upBranchId, $upPrice !== null ? (float)$upPrice : null);
            if ($service) {
                $validated['lab_service_id'] = $service->id;
                $validated['labServiceId'] = $service->id;
                if ($upPrice === null || $upPrice === '' || $upPrice == 0) {
                    $activePrice = $service->getActivePrice($upBranchId);
                    $validated['applied_price'] = $activePrice ? (float) $activePrice->base_price : 0;
                } else {
                    $validated['applied_price'] = (float) $upPrice;
                }
            } elseif (isset($validated['appliedPrice'])) {
                $validated['applied_price'] = (float) $validated['appliedPrice'];
            }

            // Convert camelCase to snake_case
            $updates = [
                'client_id' => $validated['clientId'] ?? $labOrder->client_id,
                'doctor_name' => $validated['doctorName'] ?? $labOrder->doctor_name,
                'item' => $validated['item'] ?? $labOrder->item,
                'tooth' => $validated['tooth'] ?? $labOrder->tooth,
                'status' => $validated['status'] ?? $labOrder->status,
                'sent_date' => $validated['sentDate'] ?? $labOrder->sent_date,
                'due_date' => $validated['dueDate'] ?? $labOrder->due_date,
                'received_date' => $validated['receivedDate'] ?? $labOrder->received_date,
                'shade' => $validated['shade'] ?? $labOrder->shade,
                'material' => $validated['material'] ?? $labOrder->material,
                'instructions' => $validated['instructions'] ?? $labOrder->instructions,
                'lab_service_id' => $validated['labServiceId'] ?? $labOrder->lab_service_id,
                'applied_price' => $validated['applied_price'] ?? $labOrder->applied_price,
                'invoice_id' => $validated['invoiceId'] ?? $labOrder->invoice_id,
            ];

            $labOrder->update($updates);

            \App\Models\ActivityLog::log(
                'Lab Order Updated',
                "Lab order #{$labOrder->id} was updated.",
                ['lab_order_id' => $labOrder->id]
            );

            return response()->json($labOrder->load(['client', 'branch', 'labService']), 200);
        } catch (\Throwable $e) {
            Log::error('Error updating lab order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    public function deleteLabOrder($id)
    {
        try {
            $labOrder = LaboratoryOrder::find($id);
            if (!$labOrder) {
                return response()->json(['message' => 'Lab order not found'], 404);
            }

            \App\Models\ActivityLog::log(
                'Lab Order Deleted',
                "Lab order #{$labOrder->id} was deleted.",
                ['lab_order_id' => $labOrder->id]
            );

            $labOrder->delete();

            return response()->json(['message' => 'Lab order deleted successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Error deleting lab order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'Server Error'], 500);
        }
    }

    public function createInventoryItem(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'supplier' => 'required|string',
            'stock' => 'required|integer',
            'reorderLevel' => 'required|integer',
            'price' => 'required|numeric',
            'branchId' => 'required|exists:branches,id',
        ]);

        $item = InventoryItem::create([
            'name' => $validated['name'],
            'supplier' => $validated['supplier'],
            'stock' => $validated['stock'],
            'reorder_level' => $validated['reorderLevel'],
            'price' => $validated['price'],
            'branch_id' => $validated['branchId'],
            'status' => $validated['stock'] <= 0
                ? 'Out of Stock'
                : ($validated['stock'] <= $validated['reorderLevel'] ? 'Low Stock' : 'In Stock'),
        ]);

        return response()->json($item->load('branch'), 201);
    }

    public function createBranch(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'currency' => 'sometimes|string|max:10',
        ]);

        $branch = Branch::create($validated);

        return response()->json($branch, 201);
    }

    public function createExpense(Request $request)
    {
        $validated = $request->validate([
            'branchId' => 'required|exists:branches,id',
            'date' => 'required|date',
            'category' => 'required|string',
            'description' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        $expense = Expense::create([
            'branch_id' => $validated['branchId'],
            'date' => $validated['date'],
            'category' => $validated['category'],
            'description' => $validated['description'],
            'amount' => $validated['amount'],
        ]);

        return response()->json($expense->load('branch'), 201);
    }

    /**
     * Update Expense
     *
     * Update an existing expense record.
     *
     * @authenticated
     * @urlParam id int required The expense ID. Example: 1
     * @bodyParam date date required Expense date. Example: 2026-08-28
     * @bodyParam category string required Expense category. Example: "Utilities"
     * @bodyParam description string required Expense description. Example: "Monthly electricity bill"
     * @bodyParam amount numeric required Expense amount. Example: 150.00
     * @response 200 {
     *   "id": 1,
     *   "branch_id": 1,
     *   "date": "2026-08-28",
     *   "category": "Utilities",
     *   "description": "Monthly electricity bill",
     *   "amount": "150.00",
     *   "branch": {
     *     "id": 1,
     *     "name": "Main Branch",
     *     "currency": "USD"
     *   }
     * }
     */
    public function updateExpense(Request $request, $id)
    {
        $expense = Expense::find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], 404);
        }

        $validated = $request->validate([
            'date' => 'sometimes|required|date',
            'category' => 'sometimes|required|string',
            'description' => 'sometimes|required|string',
            'amount' => 'sometimes|required|numeric',
        ]);

        $expense->update($validated);

        return response()->json($expense->load('branch'), 200);
    }

    /**
     * Delete Expense
     *
     * Delete an expense record.
     *
     * @authenticated
     * @urlParam id int required The expense ID. Example: 1
     * @response 200 {
     *   "message": "Expense deleted successfully"
     * }
     * @response 404 {
     *   "error": "Expense not found"
     * }
     */
    public function deleteExpense($id)
    {
        $expense = Expense::find($id);

        if (!$expense) {
            return response()->json(['error' => 'Expense not found'], 404);
        }

        $expense->delete();

        return response()->json(['message' => 'Expense deleted successfully'], 200);
    }

    public function updateBranch(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'currency' => 'sometimes|required|string|max:10',
        ]);

        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        $branch->update($validated);

        return response()->json($branch);
    }

    public function deleteBranch($id)
    {
        $branch = Branch::find($id);

        if (!$branch) {
            return response()->json(['message' => 'Branch not found'], 404);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($branch) {
                // Each patient has its own web of dependent records (invoices,
                // appointments, dental records, ...); reuse the same cascade
                // used when deleting a patient directly rather than a bare
                // bulk delete, which leaves those dependents behind and trips
                // their foreign keys.
                $patientDeletionService = app(\App\Services\PatientDeletionService::class);
                foreach ($branch->patients()->get() as $patient) {
                    $patientDeletionService->delete($patient);
                }

                // Clean up anything left over that isn't tied to a patient
                // (e.g. lab-order invoices), plus their sub-records.
                $branch->appointments()->delete();
                foreach ($branch->invoices()->withTrashed()->get() as $invoice) {
                    $invoice->treatments()->delete();
                    $invoice->otherCharges()->delete();
                    $invoice->transactions()->delete();
                    $invoice->forceDelete();
                }

                // Employee-dependent records must go before employees, since
                // payrolls/attendance/leave/advances reference employee_id
                // with no cascade.
                $branch->payrolls()->delete();
                $branch->attendanceRecords()->delete();
                $branch->leaveRequests()->delete();
                $branch->staffAdvances()->delete();
                $branch->employees()->delete();

                $branch->labServicePrices()->delete();
                $branch->labServices()->delete();
                $branch->laboratoryOrders()->delete();
                $branch->expenses()->delete();
                $branch->inventoryItems()->delete();
                $branch->prescriptions()->delete();
                $branch->medicines()->delete();

                // Delete branch setting if exists
                if ($branch->setting) {
                    $branch->setting->delete();
                }

                // Delete the branch itself
                $branch->delete();
            });

            \App\Models\ActivityLog::log(
                'Branch Deleted',
                "Branch #{$branch->id} ({$branch->name}) and all associated data were deleted.",
                ['branch_id' => $branch->id]
            );
        } catch (\Exception $e) {
            Log::error('Error deleting branch and related data', [
                'branch_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'An error occurred while deleting the branch and its data.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Branch and all associated data deleted successfully']);
    }

    /**
     * Get Currencies
     *
     * Retrieve supported currencies.
     */
    public function getCurrencies()
    {
        $currencies = [
            ['code' => 'USD', 'symbol' => '$', 'name' => 'United States Dollar (USD)', 'locale' => 'en-US', 'digits' => 2],
            ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro (EUR)', 'locale' => 'de-DE', 'digits' => 2],
            ['code' => 'UGX', 'symbol' => 'USh', 'name' => 'Ugandan Shilling (UGX)', 'locale' => 'en-UG', 'digits' => 0],
            ['code' => 'KES', 'symbol' => 'KSh', 'name' => 'Kenyan Shilling (KES)', 'locale' => 'en-KE', 'digits' => 0],
            ['code' => 'TZS', 'symbol' => 'TSh', 'name' => 'Tanzanian Shilling (TZS)', 'locale' => 'en-TZ', 'digits' => 0],
            ['code' => 'RWF', 'symbol' => 'RF', 'name' => 'Rwandan Franc (RWF)', 'locale' => 'rw-RW', 'digits' => 0],
            ['code' => 'BIF', 'symbol' => 'FBu', 'name' => 'Burundian Franc (BIF)', 'locale' => 'fr-BI', 'digits' => 0],
            ['code' => 'SSP', 'symbol' => 'SSP', 'name' => 'South Sudanese Pound (SSP)', 'locale' => 'en-SS', 'digits' => 2],
            ['code' => 'CDF', 'symbol' => 'FC', 'name' => 'Congolese Franc (CDF)', 'locale' => 'fr-CD', 'digits' => 2],
            ['code' => 'SOS', 'symbol' => 'Sh.So.', 'name' => 'Somali Shilling (SOS)', 'locale' => 'so-SO', 'digits' => 0],
        ];

        return response()->json($currencies);
    }

    public function sendLabOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'channel' => 'required|in:email,whatsapp,sms',
            'customMessage' => 'nullable|string'
        ]);

        $order = LaboratoryOrder::with(['client', 'branch'])->find($id);
        if (!$order) {
            return response()->json(['error' => 'Lab order not found'], 404);
        }

        $client = $order->client;
        if (!$client) {
            return response()->json(['error' => 'No client associated with this order'], 400);
        }

        $channel = $validated['channel'];
        $recipient = $channel === 'email' ? $client->email : $client->phone;

        if (!$recipient) {
            return response()->json(['error' => "Client does not have a valid {$channel} configured"], 400);
        }

        $settings = \App\Models\GlobalSetting::getSettings();
        $clinicName = $order->branch ? $order->branch->name : 'Our Clinic';
        $defaultMsg = "Hello {$client->name},\n\nA new lab order has been assigned to your laboratory.\nItem: {$order->item}\n" . ($order->tooth ? "Tooth: {$order->tooth}\n" : "") . "Status: {$order->status}\nDue Date: {$order->due_date}\n\nThank you.\n{$clinicName}";
        $msg = $validated['customMessage'] ?? $defaultMsg;

        if ($channel === 'whatsapp') {
            if (!$settings->isWhatsappActive()) {
                return response()->json(['error' => 'WhatsApp integration is not active'], 400);
            }
            \App\Services\NotificationService::sendWhatsApp($recipient, $msg);
        } else if ($channel === 'sms') {
            if (!$settings->deductSms()) {
                return response()->json(['error' => 'Insufficient SMS balance or SMS integration not active'], 400);
            }
            \App\Services\NotificationService::sendSms($recipient, $msg);
        } else {
            $viewData = [
                'clientName' => $client->name,
                'patientName' => $client->name,
                'item' => $order->item,
                'tooth' => $order->tooth,
                'status' => $order->status,
                'dueDate' => $order->due_date,
                'customMessage' => isset($validated['customMessage']) ? nl2br($validated['customMessage']) : null,
            ];
            $subject = isset($validated['customMessage']) ? "Message regarding Lab Order #{$order->id}" : 'New Lab Order';
            \Illuminate\Support\Facades\Mail::to($recipient)->send(new \App\Mail\GenericMarkdownMail('emails.lab-order', $viewData, $subject));
        }

        \App\Models\ActivityLog::log(
            "Lab Order Sent",
            "Lab order #{$order->id} sent to client {$client->name} via {$channel}.",
            ['lab_order_id' => $order->id, 'channel' => $channel]
        );

        return response()->json(['message' => "Lab order sent successfully via {$channel}"]);
    }
}

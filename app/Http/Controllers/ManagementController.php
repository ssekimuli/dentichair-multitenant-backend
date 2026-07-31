<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\AttendanceRecord;
use App\Models\InventoryItem;
use App\Models\LaboratoryOrder;
use App\Models\LeaveRequest;
use App\Models\StaffAdvance;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ChartData;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Appointment;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

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
        $query = LaboratoryOrder::with(['client', 'branch', 'doctor']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        $orders = $query->latest()->paginate($request->input('per_page', 10));
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
     * @bodyParam status string required Must be Sent, Received, Completed, or Cancelled. Example: "Completed"
     */
    public function updateLabOrderStatus(Request $request, $id)
    {
        $order = LaboratoryOrder::find($id);
        if (!$order) return response()->json(['error' => 'Lab order not found'], 404);

        $validated = $request->validate([
            'status' => 'required|in:Sent,Received,Completed,Cancelled',
        ]);

        $order->update($validated);
        
        \App\Models\ActivityLog::log(
            'Lab Order Status Updated', 
            "Lab order #{$order->id} status updated to {$validated['status']}.", 
            ['lab_order_id' => $order->id, 'status' => $validated['status']]
        );

        return response()->json($order->load(['client', 'branch']));
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
        $branchId = $request->branch_id;
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
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $expenseQuery = Expense::query()
            ->whereBetween('date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $payrollQuery = Payroll::query()
            ->whereBetween('pay_date', [$startDate, $endDate])
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId));

        $totalIncome = $incomeQuery->sum('paid_amount');
        $totalOtherExpenses = $expenseQuery->sum('amount');
        $totalPayroll = $payrollQuery->sum('net_pay');
        $totalExpenses = $totalOtherExpenses + $totalPayroll;
        $netProfit = $totalIncome - $totalExpenses;

        $incomeByMonth = $incomeQuery
            ->selectRaw("DATE_FORMAT(issue_date, '%Y-%m') as month, SUM(paid_amount) as income")
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
            'total_expenses' => number_format($totalExpenses, 2, '.', ''),
            'net_profit' => number_format($netProfit, 2, '.', ''),
            'from' => $startDate->toDateString(),
            'to' => $endDate->toDateString(),
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

    public function createLabOrder(Request $request)
    {
        $validated = $request->validate([
            'clientId' => 'required|exists:clients,id',
            'patientName' => 'required|string',
            'lab' => 'required|string',
            'item' => 'required|string',
            'tooth' => 'nullable|integer',
            'status' => 'required|in:Sent,Received,Completed,Cancelled',
            'sentDate' => 'required|date',
            'dueDate' => 'required|date',
            'branchId' => 'required|exists:branches,id',
            'receivedDate' => 'nullable|date',
            'shade' => 'nullable|string',
            'material' => 'nullable|string',
            'instructions' => 'nullable|string',
            'cost' => 'nullable|numeric',
            'invoiceNumber' => 'nullable|string',
            'doctorId' => 'nullable|exists:employees,id',
        ]);

        $labOrder = LaboratoryOrder::create([
            'client_id' => $validated['clientId'],
            'patient_name' => $validated['patientName'],
            'lab' => $validated['lab'],
            'item' => $validated['item'],
            'tooth' => $validated['tooth'] ?? null,
            'status' => $validated['status'],
            'sent_date' => $validated['sentDate'],
            'due_date' => $validated['dueDate'],
            'branch_id' => $validated['branchId'],
            'received_date' => $validated['receivedDate'] ?? null,
            'shade' => $validated['shade'] ?? null,
            'material' => $validated['material'] ?? null,
            'instructions' => $validated['instructions'] ?? null,
            'cost' => $validated['cost'] ?? null,
            'invoice_number' => $validated['invoiceNumber'] ?? null,
            'doctor_id' => $validated['doctorId'] ?? null,
        ]);

        \App\Models\ActivityLog::log(
            'Lab Order Created', 
            "Lab order for patient {$validated['patientName']} was created.", 
            ['lab_order_id' => $labOrder->id, 'client_id' => $validated['clientId']]
        );

        return response()->json($labOrder->load(['client', 'branch']), 201);
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
            $branch->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() == 23000) {
                return response()->json(['message' => 'Cannot delete this branch because it has related records (e.g., patients, employees, appointments).'], 400);
            }
            return response()->json(['message' => 'An error occurred while deleting the branch.'], 500);
        }

        return response()->json(['success' => true]);
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
}

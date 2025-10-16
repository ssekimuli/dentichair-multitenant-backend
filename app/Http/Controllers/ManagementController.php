<?php

namespace App\Http\Controllers;

use App\Models\Payroll;
use App\Models\AttendanceRecord;
use App\Models\InventoryItem;
use App\Models\LaboratoryOrder;
use App\Models\LeaveRequest;
use App\Models\StaffAdvance;
use App\Models\Expense;
use App\Models\ChartData;
use App\Models\Employee;
use Illuminate\Http\Request;

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
        return response()->json($query->get());
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
        return response()->json($query->get());
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

    $employeeIds = is_array($data['employee_id']) ? $data['employee_id'] : [$data['employee_id']];
    $responses = [];

    foreach ($employeeIds as $employeeId) {
        $validated = validator([
            'employee_id' => $employeeId,
            'employee_name' => $data['employee_name'] ?? null,
            'date' => $data['date'],
            'status' => $data['status'],
            'check_in' => $data['check_in'] ?? null,
            'check_out' => $data['check_out'] ?? null,
            'branch_id' => $data['branch_id'],
        ], [
            'employee_id' => 'required|exists:employees,id',
            'employee_name' => 'nullable|string',
            'date' => 'required|date',
            'status' => 'required|in:Present,Absent,On Leave',
            'check_in' => 'nullable|string',
            'check_out' => 'nullable|string',
            'branch_id' => 'required|exists:branches,id',
        ])->validate();

        if (empty($validated['employee_name'])) {
            $employee = Employee::find($validated['employee_id']);
            $validated['employee_name'] = $employee->name;
        }

        $existing = AttendanceRecord::where('employee_id', $validated['employee_id'])
            ->where('date', $validated['date'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            $responses[] = $existing->load(['employee', 'branch']);
        } else {
            $attendance = AttendanceRecord::create([
                ...$validated,
                'id' => 'att' . (AttendanceRecord::count() + 1),
            ]);
            $responses[] = $attendance->load(['employee', 'branch']);
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
        return response()->json($query->get());
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
        $query = LaboratoryOrder::with(['patient', 'branch']);
        if ($request->filled('branch_id')) $query->where('branch_id', $request->branch_id);
        return response()->json($query->get());
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
        return response()->json($order->load(['patient', 'branch']));
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

        return response()->json($query->get());
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

        $leaveRequest->update($validated);
        return response()->json($leaveRequest->load(['employee', 'branch']));
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

        return response()->json($query->get());
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

        $advance->update($validated);

        return response()->json($advance->load(['employee', 'branch']));
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
        return response()->json($query->get());
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
}

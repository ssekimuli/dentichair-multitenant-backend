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
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ManagementController extends Controller
{
    // Payroll Management
    public function getPayrolls(Request $request)
    {
        $query = Payroll::with(['employee', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

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
            'id' => 'pr' . (Payroll::count() + 1),
            'status' => 'Pending',
        ]);

        return response()->json($payroll->load(['employee', 'branch']), 201);
    }

    // Attendance Management
    public function getAttendance(Request $request)
    {
        $query = AttendanceRecord::with(['employee', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('date')) {
            $query->where('date', $request->date);
        }

        return response()->json($query->get());
    }

    public function markAttendance(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'employee_name' => 'required|string',
            'date' => 'required|date',
            'status' => 'required|in:Present,Absent,On Leave',
            'check_in' => 'nullable|string',
            'check_out' => 'nullable|string',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $existing = AttendanceRecord::where('employee_id', $validated['employee_id'])
            ->where('date', $validated['date'])
            ->first();

        if ($existing) {
            $existing->update($validated);
            return response()->json($existing->load(['employee', 'branch']));
        }

        $attendance = AttendanceRecord::create([
            ...$validated,
            'id' => 'att' . (AttendanceRecord::count() + 1),
        ]);

        return response()->json($attendance->load(['employee', 'branch']), 201);
    }

    // Inventory Management
    public function getInventory(Request $request)
    {
        $query = InventoryItem::with(['branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

    public function updateInventory(Request $request, $id)
    {
        $item = InventoryItem::find($id);
        
        if (!$item) {
            return response()->json(['error' => 'Inventory item not found'], 404);
        }

        $validated = $request->validate([
            'stock' => 'required|integer',
            'reorder_level' => 'sometimes|integer',
            'price' => 'sometimes|numeric',
        ]);

        $item->update([
            ...$validated,
            'status' => $validated['stock'] <= 0 ? 'Out of Stock' : 
                       ($validated['stock'] <= $item->reorder_level ? 'Low Stock' : 'In Stock'),
        ]);

        return response()->json($item->load('branch'));
    }

    // Laboratory Orders
    public function getLabOrders(Request $request)
    {
        $query = LaboratoryOrder::with(['patient', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

    public function updateLabOrderStatus(Request $request, $id)
    {
        $order = LaboratoryOrder::find($id);
        
        if (!$order) {
            return response()->json(['error' => 'Lab order not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Sent,Received,Completed,Cancelled',
        ]);

        $order->update($validated);

        return response()->json($order->load(['patient', 'branch']));
    }

    // Leave Requests
    public function getLeaveRequests(Request $request)
    {
        $query = LeaveRequest::with(['employee', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

    public function updateLeaveRequest(Request $request, $id)
    {
        $leaveRequest = LeaveRequest::find($id);
        
        if (!$leaveRequest) {
            return response()->json(['error' => 'Leave request not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        $leaveRequest->update($validated);

        return response()->json($leaveRequest->load(['employee', 'branch']));
    }

    // Staff Advances
    public function getStaffAdvances(Request $request)
    {
        $query = StaffAdvance::with(['employee', 'branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

    public function updateStaffAdvance(Request $request, $id)
    {
        $advance = StaffAdvance::find($id);
        
        if (!$advance) {
            return response()->json(['error' => 'Staff advance not found'], 404);
        }

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);

        $advance->update($validated);

        return response()->json($advance->load(['employee', 'branch']));
    }

    // Expenses
    public function getExpenses(Request $request)
    {
        $query = Expense::with(['branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }

    // Chart Data
    public function getChartData(Request $request)
    {
        $query = ChartData::with(['branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        return response()->json($query->get());
    }
}
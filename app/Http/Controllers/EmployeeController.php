<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::with(['branch']);

        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        $employees = $query->get();

        return response()->json($employees);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'avatar' => 'nullable|string',
            'email' => 'required|email|unique:employees,email',
            'role' => 'required|string',
            'salary' => 'required|numeric',
            'hire_date' => 'required|date',
            'branch_id' => 'required|exists:branches,id',
        ]);

        $employee = Employee::create([
            ...$validated,
            'id' => 'emp' . (Employee::count() + 1),
            'status' => 'Active',
        ]);

        return response()->json($employee->load('branch'), 201);
    }

    public function update(Request $request, $id)
    {
        $employee = Employee::find($id);
        
        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string',
            'avatar' => 'nullable|string',
            'email' => 'sometimes|email|unique:employees,email,' . $id,
            'role' => 'sometimes|string',
            'status' => 'sometimes|in:Active,On Leave',
            'salary' => 'sometimes|numeric',
            'hire_date' => 'sometimes|date',
            'branch_id' => 'sometimes|exists:branches,id',
        ]);

        $employee->update($validated);

        return response()->json($employee->load('branch'));
    }

    public function destroy($id)
    {
        $employee = Employee::find($id);
        
        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        $employee->delete();

        return response()->json(['success' => true]);
    }
}
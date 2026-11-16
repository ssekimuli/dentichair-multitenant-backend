<?php

namespace App\Http\Controllers;

use App\Models\Treatment;
use Illuminate\Http\Request;

class ProcedureController extends Controller
{
    /**
     * List all procedures.
     */
    public function index(Request $request)
    {
        $query = Treatment::query();

        if ($request->has('search') && $request->search != '') {
            $query->where('procedure', 'like', '%' . $request->search . '%');
        }

        if ($request->has('category') && $request->category != '') {
            $query->where('category', $request->category);
        }

        return response()->json($query->orderBy('procedure', 'asc')->get());
    }

    /**
     * Show a specific procedure.
     */
    public function show($id)
    {
        $procedure = Treatment::find($id);

        if (! $procedure) {
            return response()->json(['error' => 'Procedure not found'], 404);
        }

        return response()->json($procedure);
    }

    /**
     * Store a new procedure.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'treatment_code' => 'nullable|string|max:255',
            'procedure' => 'required|string|max:255',
            'cost' => 'required|numeric|min:0',
            'category' => 'nullable|string|max:255',
        ]);

        $procedure = Treatment::create($validated);

        return response()->json($procedure, 201);
    }

    /**
     * Update an existing procedure.
     */
    public function update(Request $request, $id)
    {
        $procedure = Treatment::find($id);

        if (! $procedure) {
            return response()->json(['error' => 'Procedure not found'], 404);
        }

        $validated = $request->validate([
            'treatment_code' => 'nullable|string|max:255',
            'procedure' => 'sometimes|required|string|max:255',
            'cost' => 'sometimes|required|numeric|min:0',
            'category' => 'nullable|string|max:255',
        ]);

        $procedure->update($validated);

        return response()->json($procedure);
    }

    /**
     * Delete a procedure.
     */
    public function destroy($id)
    {
        $procedure = Treatment::find($id);

        if (! $procedure) {
            return response()->json(['error' => 'Procedure not found'], 404);
        }

        try {
            $procedure->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            // SQLSTATE 23000 = integrity constraint: still referenced (e.g. by an insurance rule)
            if (($e->errorInfo[0] ?? null) === '23000') {
                return response()->json([
                    'message' => 'This procedure is in use (for example by an insurance rule) and cannot be deleted.',
                ], 409);
            }
            throw $e;
        }

        return response()->json(['message' => 'Procedure deleted successfully']);
    }
}

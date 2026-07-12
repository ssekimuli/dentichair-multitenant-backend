<?php

namespace App\Http\Controllers;

use App\Models\Treatment;
use Illuminate\Http\Request;

class ProcedureController extends Controller
{
    /**
     * List all procedures.
     */
    public function index()
    {
        return response()->json(Treatment::latest()->get());
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

        $procedure->delete();

        return response()->json(['message' => 'Procedure deleted successfully']);
    }
}

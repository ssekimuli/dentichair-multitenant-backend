<?php

namespace App\Http\Controllers;

use App\Models\Diagnosis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Diagnoses
 *
 * Browse and search the dental diagnosis catalogue.
 */
class DiagnosisController extends Controller
{
    /**
     * List Diagnoses
     *
     * Search and filter the diagnosis catalogue. Results are paginated and
     * ordered by category, then name. Only active diagnoses are returned unless
     * `is_active` is given.
     *
     * @authenticated
     *
     * @queryParam q string Search text, matched against the name and ICD code. Example: caries
     * @queryParam category string Exact category name. Example: Dental Caries
     * @queryParam is_active boolean Filter by active status. Omit to get active diagnoses only. Example: true
     * @queryParam per_page integer Items per page, 1 to 200. Default 50. Example: 20
     * @queryParam page integer Page number. Example: 1
     *
     * @response 200 {
     *   "data": [
     *     {"id": 1, "name": "Dental caries", "category": "Dental Caries", "icd_code": null, "is_active": true}
     *   ],
     *   "current_page": 1,
     *   "last_page": 1,
     *   "per_page": 50,
     *   "total": 1
     * }
     * @response 422 {"message": "The per page field must be between 1 and 200.", "errors": {"per_page": ["The per page field must be between 1 and 200."]}}
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'is_active' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Diagnosis::query();

        if (!empty($validated['q'])) {
            // Escape LIKE wildcards so the user's text is matched literally.
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($validated['q']));
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('icd_code', 'like', "%{$term}%");
            });
        }

        if (!empty($validated['category'])) {
            $query->where('category', $validated['category']);
        }

        $query->where('is_active', $request->has('is_active')
            ? $request->boolean('is_active')
            : true);

        $diagnoses = $query
            ->orderBy('category')
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 50);

        return response()->json($diagnoses);
    }

    /**
     * List Diagnosis Categories
     *
     * Returns each category with the number of active diagnoses in it.
     * Use this to populate a category filter.
     *
     * @authenticated
     *
     * @response 200 {
     *   "data": [
     *     {"category": "Dental Caries", "count": 10},
     *     {"category": "Pulpal Diseases", "count": 10}
     *   ]
     * }
     */
    public function categories(): JsonResponse
    {
        $categories = Diagnosis::query()
            ->where('is_active', true)
            ->selectRaw('category, COUNT(*) as count')
            ->groupBy('category')
            ->orderBy('category')
            ->get();

        return response()->json(['data' => $categories]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabServicePriceRequest;
use App\Http\Resources\LabServicePriceResource;
use App\Models\LabServicePrice;
use Illuminate\Http\Request;

class LabServicePriceController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user()->branch_id ?? $request->branch_id;
        $search = $request->query('search');
        $lab = $request->query('lab');
        $minPrice = $request->query('min_price');
        $maxPrice = $request->query('max_price');
        $active = $request->query('active');
        $serviceId = $request->query('service_id');

        $query = LabServicePrice::where('branch_id', $branchId);

        if ($search) {
            $query->search($search);
        }

        if ($lab) {
            $query->byLab($lab);
        }

        if ($minPrice !== null && $maxPrice !== null) {
            $query->priceBetween($minPrice, $maxPrice);
        } elseif ($minPrice !== null) {
            $query->where('base_price', '>=', $minPrice);
        } elseif ($maxPrice !== null) {
            $query->where('base_price', '<=', $maxPrice);
        }

        if ($active !== null) {
            $query->where('is_active', filter_var($active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($serviceId !== null) {
            $query->where('lab_service_id', $serviceId);
        }

        $prices = $query->with('labService')->get();

        return response()->json(LabServicePriceResource::collection($prices));
    }

    public function store(StoreLabServicePriceRequest $request)
    {
        $branchId = $request->user()->branch_id ?? $request->branch_id;

        if ($request->input('is_active', false)) {
            LabServicePrice::where('lab_service_id', $request->lab_service_id)
                ->where('branch_id', $branchId)
                ->update(['is_active' => false]);
        }

        $price = LabServicePrice::create([
            ...$request->validated(),
            'branch_id' => $branchId,
        ]);

        \App\Models\ActivityLog::log(
            'Lab Service Price Created',
            "Price of {$price->base_price} was set for lab service.",
            ['lab_service_price_id' => $price->id]
        );

        return response()->json(new LabServicePriceResource($price), 201);
    }

    public function update(StoreLabServicePriceRequest $request, LabServicePrice $price)
    {
        $branchId = $request->user()->branch_id ?? $request->branch_id;

        if ($request->input('is_active', false) && !$price->is_active) {
            LabServicePrice::where('lab_service_id', $price->lab_service_id)
                ->where('branch_id', $branchId)
                ->where('id', '!=', $price->id)
                ->update(['is_active' => false]);
        }

        $price->update($request->validated());

        \App\Models\ActivityLog::log(
            'Lab Service Price Updated',
            "Price of {$price->base_price} was updated.",
            ['lab_service_price_id' => $price->id]
        );

        return response()->json(new LabServicePriceResource($price));
    }

    public function destroy(LabServicePrice $price)
    {
        \App\Models\ActivityLog::log(
            'Lab Service Price Deleted',
            "Lab service price was deleted.",
            ['lab_service_price_id' => $price->id]
        );

        $price->delete();
        return response()->json(null, 204);
    }

    public function priceForDate(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:lab_services,id',
        ]);

        $price = LabServicePrice::where('lab_service_id', $validated['service_id'])
            ->active()
            ->first();

        if (!$price) {
            return response()->json(['error' => 'No active price found'], 404);
        }

        return response()->json(new LabServicePriceResource($price));
    }
}

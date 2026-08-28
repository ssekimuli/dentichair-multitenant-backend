<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLabServiceRequest;
use App\Http\Resources\LabServiceResource;
use App\Models\LabService;
use Illuminate\Http\Request;

class LabServiceController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->user()->branch_id ?? $request->branch_id;

        $services = LabService::where('branch_id', $branchId)
            ->with('prices')
            ->get();

        return response()->json(LabServiceResource::collection($services));
    }

    public function store(StoreLabServiceRequest $request)
    {
        $branchId = $request->user()->branch_id ?? $request->branch_id;

        $service = LabService::create([
            ...$request->validated(),
            'branch_id' => $branchId,
        ]);

        \App\Models\ActivityLog::log(
            'Lab Service Created',
            "Lab service {$service->service_name} was created.",
            ['lab_service_id' => $service->id]
        );

        return response()->json(new LabServiceResource($service), 201);
    }

    public function show(LabService $service)
    {
        $service->load('prices');
        return response()->json(new LabServiceResource($service));
    }

    public function update(StoreLabServiceRequest $request, LabService $service)
    {
        $service->update($request->validated());

        \App\Models\ActivityLog::log(
            'Lab Service Updated',
            "Lab service {$service->service_name} was updated.",
            ['lab_service_id' => $service->id]
        );

        $service->load('prices');
        return response()->json(new LabServiceResource($service));
    }

    public function destroy(LabService $service)
    {
        \App\Models\ActivityLog::log(
            'Lab Service Deleted',
            "Lab service {$service->service_name} was deleted.",
            ['lab_service_id' => $service->id]
        );

        $service->delete();
        return response()->json(null, 204);
    }

    public function prices(LabService $service)
    {
        $service->load('prices');
        return response()->json($service->prices);
    }
}

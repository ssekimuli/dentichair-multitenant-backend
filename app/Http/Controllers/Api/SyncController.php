<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\LaboratoryOrder;
use App\Models\XrayRequest;
use App\Models\Patient;
use App\Models\TreatmentPlan;
use App\Models\DentalRecord;
use App\Models\MedicalHistory;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    /**
     * Health check endpoint for Flutter client availability detection.
     * GET /api/health
     */
    public function health()
    {
        return response()->json([
            'status' => 'ok',
            'server' => 'DentiChair API',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Batch synchronization endpoint.
     * POST /api/sync
     */
    public function sync(Request $request)
    {
        $changes = $request->input('changes', []);
        $results = [];

        foreach ($changes as $item) {
            $clientId = $item['id'] ?? null;
            $entity = $item['entity'] ?? '';
            $operation = strtolower($item['operation'] ?? 'create');
            $data = $item['data'] ?? [];

            try {
                $res = $this->processChange($entity, $operation, $data, $clientId);
                $results[] = array_merge([
                    'id' => $clientId,
                    'entity' => $entity,
                    'status' => 'synced',
                ], $res);
            } catch (\Throwable $e) {
                Log::error("[SyncController] Error syncing change {$clientId} ({$entity}/{$operation}): " . $e->getMessage());
                $results[] = [
                    'id' => $clientId,
                    'entity' => $entity,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'processed' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Pull latest updates from server for offline synchronization.
     * GET /api/sync/pull
     */
    public function pull(Request $request)
    {
        $updatedSince = $request->query('updated_since');
        $branchId = $request->query('branch_id');

        $queryPatients = Patient::query();
        $queryAppointments = Appointment::query();
        $queryInvoices = Invoice::query();
        $queryClients = Client::query();
        $queryLabOrders = LaboratoryOrder::with(['client', 'branch', 'labService']);
        $queryXrayRequests = XrayRequest::with(['patientRecord', 'branch', 'doctor', 'invoice']);
        $queryMedicalHistories = MedicalHistory::with(['patient', 'doctor']);

        if ($branchId) {
            $queryPatients->where('branch_id', $branchId);
            $queryAppointments->where('branch_id', $branchId);
            $queryInvoices->where('branch_id', $branchId);
            $queryLabOrders->where('branch_id', $branchId);
            $queryXrayRequests->where('branch_id', $branchId);
            $queryMedicalHistories->whereHas('patient', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        if ($updatedSince) {
            $since = date('Y-m-d H:i:s', strtotime($updatedSince));
            $queryPatients->where('updated_at', '>=', $since);
            $queryAppointments->where('updated_at', '>=', $since);
            $queryInvoices->where('updated_at', '>=', $since);
            $queryClients->where('updated_at', '>=', $since);
            $queryLabOrders->where('updated_at', '>=', $since);
            $queryXrayRequests->where('updated_at', '>=', $since);
            $queryMedicalHistories->where('updated_at', '>=', $since);
        } else {
            $queryPatients->limit(100);
            $queryAppointments->limit(100);
            $queryInvoices->limit(100);
            $queryClients->limit(100);
            $queryLabOrders->limit(100);
            $queryXrayRequests->limit(100);
            $queryMedicalHistories->limit(100);
        }

        return response()->json([
            'status' => 'success',
            'timestamp' => now()->toIso8601String(),
            'data' => [
                'patients' => $queryPatients->orderBy('updated_at', 'desc')->get(),
                'appointments' => $queryAppointments->orderBy('updated_at', 'desc')->get(),
                'invoices' => $queryInvoices->orderBy('updated_at', 'desc')->get(),
                'clients' => $queryClients->orderBy('updated_at', 'desc')->get(),
                'laboratory_orders' => $queryLabOrders->orderBy('updated_at', 'desc')->get(),
                'xray_requests' => $queryXrayRequests->orderBy('updated_at', 'desc')->get(),
                'medical_histories' => $queryMedicalHistories->orderBy('updated_at', 'desc')->get(),
            ],
        ]);
    }

    protected function processChange(string $entity, string $operation, array $data, ?string $clientId): array
    {
        switch (strtolower($entity)) {
            case 'patient':
            case 'patients':
                return $this->processPatient($operation, $data, $clientId);

            case 'appointment':
            case 'appointments':
                return $this->processAppointment($operation, $data, $clientId);

            case 'client':
            case 'clients':
                return $this->processClient($operation, $data, $clientId);

            case 'laboratory_order':
            case 'laboratory_orders':
            case 'lab_order':
            case 'lab_orders':
                return $this->processLaboratoryOrder($operation, $data, $clientId);

            case 'laboratory_orders_batch':
                return $this->processLaboratoryOrdersBatch($data, $clientId);

            case 'xray_request':
            case 'xray_requests':
            case 'radiology_request':
            case 'radiology_requests':
                return $this->processXrayRequest($operation, $data, $clientId);

            case 'expense':
            case 'expenses':
            case 'cash_out':
                return $this->processExpense($operation, $data, $clientId);

            case 'invoice':
            case 'invoices':
                return $this->processInvoice($operation, $data, $clientId);

            case 'treatment':
            case 'treatments':
                return $this->processTreatment($operation, $data, $clientId);

            case 'dental_record':
            case 'dental_records':
                return $this->processDentalRecord($operation, $data, $clientId);

            case 'medical_history':
            case 'medical_histories':
            case 'clinical_record':
            case 'clinical_records':
                return $this->processMedicalHistory($operation, $data, $clientId);

            default:
                return ['message' => "Entity {$entity} processed"];
        }
    }

    protected function processPatient(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            if (!empty($data['email']) && $existing = Patient::where('email', $data['email'])->first()) {
                return ['server_id' => $existing->id, 'action' => 'matched_existing'];
            }
            if (!empty($data['phone']) && !empty($data['first_name'])) {
                $existing = Patient::where('phone', $data['phone'])
                    ->where('first_name', $data['first_name'])
                    ->first();
                if ($existing) {
                    return ['server_id' => $existing->id, 'action' => 'matched_existing'];
                }
            }

            $patient = Patient::create($data);
            return ['server_id' => $patient->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $patient = Patient::find($id)) {
                $patient->update($data);
                return ['server_id' => $patient->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                Patient::destroy($id);
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processAppointment(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            $appointment = Appointment::create($data);
            return ['server_id' => $appointment->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $app = Appointment::find($id)) {
                $app->update($data);
                return ['server_id' => $app->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                Appointment::destroy($id);
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processClient(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            if (!empty($data['name']) && $existing = Client::where('name', $data['name'])->first()) {
                return ['server_id' => $existing->id, 'action' => 'matched_existing'];
            }
            $client = Client::create($data);
            return ['server_id' => $client->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $client = Client::find($id)) {
                $client->update($data);
                return ['server_id' => $client->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                Client::destroy($id);
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processLaboratoryOrder(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            $order = LaboratoryOrder::create($data);
            if (($order->status ?? '') === 'In Production' && !$order->invoice_id) {
                try {
                    app(\App\Http\Controllers\ManagementController::class)->generateLabOrderInvoice($order);
                } catch (\Throwable $e) {
                    Log::error('[SyncController] Error generating invoice for lab order on create: ' . $e->getMessage());
                }
            }
            return ['server_id' => $order->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $order = LaboratoryOrder::find($id)) {
                $oldStatus = $order->status;
                $order->update($data);
                $newStatus = $order->status;
                if ($newStatus === 'In Production' && ($oldStatus !== 'In Production' || !$order->invoice_id)) {
                    try {
                        app(\App\Http\Controllers\ManagementController::class)->generateLabOrderInvoice($order);
                    } catch (\Throwable $e) {
                        Log::error('[SyncController] Error generating invoice for lab order on update: ' . $e->getMessage());
                    }
                } elseif ($newStatus === 'Cancelled' && $oldStatus !== 'Cancelled') {
                    try {
                        app(\App\Http\Controllers\ManagementController::class)->cancelLabOrderInvoice($order);
                    } catch (\Throwable $e) {
                        Log::error('[SyncController] Error cancelling invoice for lab order on update: ' . $e->getMessage());
                    }
                }
                return ['server_id' => $order->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                LaboratoryOrder::destroy($id);
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processLaboratoryOrdersBatch(array $data, ?string $clientId): array
    {
        $orders = $data['orders'] ?? $data;
        $createdIds = [];

        if (is_array($orders)) {
            foreach ($orders as $orderData) {
                if (is_array($orderData)) {
                    $order = LaboratoryOrder::create($orderData);
                    $createdIds[] = $order->id;
                    if (($order->status ?? '') === 'In Production' && !$order->invoice_id) {
                        try {
                            app(\App\Http\Controllers\ManagementController::class)->generateLabOrderInvoice($order);
                        } catch (\Throwable $e) {
                            Log::error('[SyncController] Error generating invoice for batch lab order: ' . $e->getMessage());
                        }
                    }
                }
            }
        }

        return ['server_ids' => $createdIds, 'action' => 'batch_created'];
    }

    protected function processXrayRequest(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            $req = XrayRequest::create($data);
            if ((float)($req->amount ?? 0) > 0 && !$req->invoice_id) {
                try {
                    app(\App\Http\Controllers\XrayRequestController::class)->generateXrayInvoice($req);
                } catch (\Throwable $e) {
                    Log::error('[SyncController] Error generating invoice for xray request: ' . $e->getMessage());
                }
            }
            return ['server_id' => $req->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $req = XrayRequest::find($id)) {
                $oldStatus = $req->status;
                $req->update($data);
                if (($req->status ?? '') === 'cancelled' && $oldStatus !== 'cancelled') {
                    try {
                        app(\App\Http\Controllers\XrayRequestController::class)->cancelXrayInvoice($req);
                    } catch (\Throwable $e) {}
                } elseif ((float)($req->amount ?? 0) > 0 && !$req->invoice_id) {
                    try {
                        app(\App\Http\Controllers\XrayRequestController::class)->generateXrayInvoice($req);
                    } catch (\Throwable $e) {}
                }
                return ['server_id' => $req->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id) {
                if ($req = XrayRequest::find($id)) {
                    try {
                        app(\App\Http\Controllers\XrayRequestController::class)->cancelXrayInvoice($req);
                    } catch (\Throwable $e) {}
                }
                XrayRequest::destroy($id);
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processExpense(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            $expense = Expense::create($data);
            return ['server_id' => $expense->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $exp = Expense::find($id)) {
                $exp->update($data);
                return ['server_id' => $exp->id, 'action' => 'updated'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processInvoice(string $operation, array $data, ?string $clientId): array
    {
        if ($operation === 'create') {
            $inv = Invoice::create($data);
            return ['server_id' => $inv->id, 'action' => 'created'];
        }

        return ['action' => 'skipped'];
    }

    protected function processTreatment(string $operation, array $data, ?string $clientId): array
    {
        $patientId = $data['patient_id'] ?? $data['patientId'] ?? null;
        if (!$patientId) {
            return ['action' => 'skipped', 'reason' => 'missing_patient_id'];
        }

        $patient = Patient::find($patientId);
        if (!$patient) {
            return ['action' => 'skipped', 'reason' => 'patient_not_found'];
        }

        if ($operation === 'create') {
            $treatmentData = Arr::only($data, [
                'procedure', 'tooth', 'status', 'cost', 'date', 'treatment_id', 'doctor_id', 'notes'
            ]);
            $treatmentData['status'] = in_array($treatmentData['status'] ?? '', ['Proposed', 'Completed'])
                ? $treatmentData['status']
                : 'Proposed';

            if (empty($treatmentData['doctor_id']) && auth()->check() && auth()->user() instanceof \App\Models\Employee) {
                $treatmentData['doctor_id'] = auth()->id();
            }

            $treatment = $patient->treatmentPlans()->create($treatmentData);

            if (!empty($data['condition']) && !empty($data['tooth'])) {
                $existingRecord = $patient->dentalRecords()
                    ->where('tooth_id', $data['tooth'])
                    ->first();

                if ($existingRecord) {
                    $existingRecord->update([
                        'condition' => $data['condition'],
                        'tooth_id'  => $data['tooth'],
                        'notes'     => $data['notes'] ?? $existingRecord->notes,
                    ]);
                } else {
                    $patient->dentalRecords()->create([
                        'condition' => $data['condition'],
                        'tooth_id'  => $data['tooth'],
                        'notes'     => $data['notes'] ?? ($data['procedure'] ?? ''),
                    ]);
                }
            }

            return ['server_id' => $treatment->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            if ($id && $treatment = TreatmentPlan::find($id)) {
                $treatmentData = Arr::only($data, [
                    'procedure', 'tooth', 'status', 'cost', 'date', 'treatment_id', 'doctor_id', 'notes'
                ]);
                if (isset($treatmentData['status'])) {
                    $treatmentData['status'] = in_array($treatmentData['status'], ['Proposed', 'Completed'])
                        ? $treatmentData['status']
                        : $treatment->status;
                }
                $treatment->update($treatmentData);
                return ['server_id' => $treatment->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            if ($id && $treatment = TreatmentPlan::find($id)) {
                $treatment->delete();
                return ['server_id' => $id, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }

    protected function processDentalRecord(string $operation, array $data, ?string $clientId): array
    {
        $patientId = $data['patient_id'] ?? $data['patientId'] ?? null;
        $toothId = $data['tooth_id'] ?? null;
        if (!$patientId || !$toothId) {
            return ['action' => 'skipped', 'reason' => 'missing_required_fields'];
        }

        $patient = Patient::find($patientId);
        if (!$patient) {
            return ['action' => 'skipped', 'reason' => 'patient_not_found'];
        }

        $existing = $patient->dentalRecords()->where('tooth_id', $toothId)->first();
        if ($existing) {
            $existing->update([
                'condition' => $data['condition'] ?? $existing->condition,
                'notes' => $data['notes'] ?? $existing->notes,
                'bridge_group_id' => $data['bridge_group_id'] ?? $existing->bridge_group_id,
            ]);
            return ['server_id' => $existing->id, 'action' => 'updated'];
        } else {
            $record = $patient->dentalRecords()->create([
                'tooth_id' => $toothId,
                'condition' => $data['condition'] ?? 'Healthy',
                'notes' => $data['notes'] ?? null,
                'bridge_group_id' => $data['bridge_group_id'] ?? null,
            ]);
            return ['server_id' => $record->id, 'action' => 'created'];
        }
    }

    protected function processMedicalHistory(string $operation, array $data, ?string $clientId): array
    {
        $patientId = $data['patient_id'] ?? $data['patientId'] ?? null;
        $clientUuid = $data['client_uuid'] ?? $data['clientUuid'] ?? $clientId;

        if ($operation === 'create') {
            if (!empty($clientUuid)) {
                $existing = MedicalHistory::where('client_uuid', $clientUuid)->first();
                if ($existing) {
                    return ['server_id' => $existing->id, 'action' => 'matched_existing'];
                }
            }

            if (!$patientId) {
                return ['action' => 'skipped', 'reason' => 'missing_patient_id'];
            }

            $patient = Patient::find($patientId);
            if (!$patient) {
                return ['action' => 'skipped', 'reason' => 'patient_not_found'];
            }

            $historyData = Arr::only($data, [
                'date', 'chief_complaint', 'history_complaint', 'extra_oral_exam',
                'intra_oral_exam', 'diagnosis', 'treatment_plan', 'treatment_given',
                'consent', 'consent_notes', 'treatment_note', 'doctor_name', 'doctor_id',
                'client_uuid'
            ]);
            $historyData['client_uuid'] = $clientUuid;
            $historyData['patient_id'] = $patientId;
            $historyData['date'] = $historyData['date'] ?? now()->toDateString();
            $historyData['consent'] = isset($data['consent']) ? (bool)$data['consent'] : true;

            $record = MedicalHistory::create($historyData);

            if (!empty($data['next_visit'])) {
                $patient->next_visit = $data['next_visit'];
                $patient->save();
            }
            if (isset($data['medical_history']) && $data['medical_history'] !== null) {
                $patient->history = $data['medical_history'];
                $patient->save();
            }

            return ['server_id' => $record->id, 'action' => 'created'];
        } elseif ($operation === 'update') {
            $id = $data['id'] ?? null;
            $record = null;
            if ($id && is_numeric($id)) {
                $record = MedicalHistory::find($id);
            }
            if (!$record && !empty($clientUuid)) {
                $record = MedicalHistory::where('client_uuid', $clientUuid)->first();
            }

            if ($record) {
                $historyData = Arr::only($data, [
                    'date', 'chief_complaint', 'history_complaint', 'extra_oral_exam',
                    'intra_oral_exam', 'diagnosis', 'treatment_plan', 'treatment_given',
                    'consent', 'consent_notes', 'treatment_note', 'doctor_name', 'doctor_id'
                ]);
                $record->update($historyData);

                if (!empty($data['next_visit']) || isset($data['medical_history'])) {
                    $patient = $record->patient ?? Patient::find($record->patient_id);
                    if ($patient) {
                        if (!empty($data['next_visit'])) {
                            $patient->next_visit = $data['next_visit'];
                        }
                        if (isset($data['medical_history']) && $data['medical_history'] !== null) {
                            $patient->history = $data['medical_history'];
                        }
                        $patient->save();
                    }
                }

                return ['server_id' => $record->id, 'action' => 'updated'];
            }
        } elseif ($operation === 'delete') {
            $id = $data['id'] ?? null;
            $record = null;
            if ($id && is_numeric($id)) {
                $record = MedicalHistory::find($id);
            }
            if (!$record && !empty($clientUuid)) {
                $record = MedicalHistory::where('client_uuid', $clientUuid)->first();
            }
            if ($record) {
                $deletedId = $record->id;
                $record->delete();
                return ['server_id' => $deletedId, 'action' => 'deleted'];
            }
        }

        return ['action' => 'skipped'];
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncController extends Controller
{
    // The list of tables the client can pull from
    protected $syncableTables = [
        'patients',
        'appointments',
        'attendance_records',
        'leave_requests',
        'staff_advances',
        'treatments',
        'treatment_plans',
        'payrolls',
        'invoices',
        'payments',
        'prescriptions',
        'pharmacy_products',
        'pharmacy_sales',
        'dental_records',
        'restorative_records',
        'endodontic_records',
        'orthodontic_records',
        'periodontal_records',
        'soft_tissue_records',
        'patient_images',
        'procedures',
        'expenses',
        'documents'
    ];

    protected function getEntityTable($entity) {
        $map = [
            'patient' => 'patients',
            'appointment' => 'appointments',
            'attendance' => 'attendance_records',
            'leave' => 'leave_requests',
            'advance' => 'staff_advances',
            'treatment' => 'treatments',
            'treatment_plan' => 'treatment_plans',
            'payroll' => 'payrolls',
            'invoice' => 'invoices',
            'payment' => 'payments',
            'prescription' => 'prescriptions',
            'product' => 'pharmacy_products',
            'sale' => 'pharmacy_sales',
            'dental_record' => 'dental_records',
            'expense' => 'expenses',
            'document' => 'documents',
        ];
        return $map[$entity] ?? $entity;
    }

    public function push(Request $request)
    {
        $changes = $request->input('changes', []);
        $results = [];

        DB::beginTransaction();
        try {
            foreach ($changes as $change) {
                $table = $this->getEntityTable($change['entity']);
                $operation = $change['operation'];
                $data = $change['data'] ?? [];
                
                // Prevent overriding auto-increment ID with local offline ID
                if (isset($data['id']) && !is_numeric($data['id'])) {
                    unset($data['id']); 
                }

                if ($operation === 'create') {
                    $serverId = DB::table($table)->insertGetId(array_merge($data, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                    $results[] = [
                        'id' => $change['id'], 
                        'status' => 'success', 
                        'server_id' => $serverId,
                        'client_uuid' => $data['client_uuid'] ?? null
                    ];
                } elseif ($operation === 'update') {
                    $uuid = $data['client_uuid'] ?? null;
                    if ($uuid) {
                        DB::table($table)->where('client_uuid', $uuid)->update(array_merge($data, [
                            'updated_at' => now(),
                        ]));
                    }
                    $results[] = ['id' => $change['id'], 'status' => 'success'];
                } elseif ($operation === 'delete') {
                    $uuid = $data['client_uuid'] ?? null;
                    if ($uuid && Schema::hasColumn($table, 'deleted_at')) {
                        DB::table($table)->where('client_uuid', $uuid)->update([
                            'deleted_at' => now(),
                        ]);
                    } elseif ($uuid) {
                        DB::table($table)->where('client_uuid', $uuid)->delete();
                    }
                    $results[] = ['id' => $change['id'], 'status' => 'success'];
                }
            }
            DB::commit();
            return response()->json(['status' => 'success', 'results' => $results]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function pull(Request $request)
    {
        $lastSyncedAt = $request->query('last_synced_at');
        
        $data = [];
        foreach ($this->syncableTables as $table) {
            $query = DB::table($table);
            
            if ($lastSyncedAt) {
                $query->where('updated_at', '>=', $lastSyncedAt);
            }
            
            $data[$table] = $query->get();
        }

        return response()->json([
            'status' => 'success',
            'server_time' => now()->toIso8601String(),
            'data' => $data
        ]);
    }
}

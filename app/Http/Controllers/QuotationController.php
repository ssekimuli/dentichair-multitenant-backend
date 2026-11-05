<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotation\ApproveQuotationRequest;
use App\Http\Requests\Quotation\CreateFromLabOrderRequest;
use App\Http\Requests\Quotation\CreateFromTreatmentPlanRequest;
use App\Http\Requests\Quotation\RejectQuotationRequest;
use App\Http\Requests\Quotation\SendQuotationRequest;
use App\Http\Requests\Quotation\StoreQuotationRequest;
use App\Http\Requests\Quotation\UpdateQuotationRequest;
use App\Http\Resources\Quotation\QuotationResource;
use App\Models\Quotation;
use App\Services\Quotation\QuotationApprovalService;
use App\Services\Quotation\QuotationConversionService;
use App\Services\Quotation\QuotationNotificationService;
use App\Services\Quotation\QuotationPdfService;
use App\Services\Quotation\QuotationRevisionService;
use App\Services\Quotation\QuotationService;
use App\Services\Quotation\QuotationStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * @group Quotation Management
 *
 * REST API for managing dental patient and laboratory client quotations,
 * approvals, public customer acceptance, and invoice conversion.
 */
class QuotationController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    public function __construct(
        protected QuotationService $quotationService,
        protected QuotationApprovalService $approvalService,
        protected QuotationConversionService $conversionService,
        protected QuotationRevisionService $revisionService,
        protected QuotationNotificationService $notificationService,
        protected QuotationPdfService $pdfService,
        protected QuotationStatusService $statusService
    ) {}

    /**
     * Get branch ID from request/authenticated user.
     */
    protected function getBranchId(Request $request): int
    {
        return (int) ($request->input('branch_id') ?: ($request->user()?->branch_id ?: 1));
    }

    /**
     * List Quotations
     *
     * Retrieve paginated quotations filtered by customer type, status, source, date, etc.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        $branchId = $this->getBranchId($request);
        Log::info('[Quotation API] GET /quotations', [
            'branch_id' => $branchId,
            'filters' => $request->only(['customer_type', 'status', 'search', 'page', 'per_page']),
        ]);
        $paginator = $this->quotationService->list($branchId, $request->all());
        Log::info('[Quotation API] Loaded quotations count: ' . $paginator->total());

        return response()->json([
            'data' => QuotationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create Quotation
     *
     * Create a manual quotation for either a patient or a laboratory client.
     */
    public function store(StoreQuotationRequest $request): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $branchId = $this->getBranchId($request);
        Log::info('[Quotation API] POST /quotations', [
            'branch_id' => $branchId,
            'customer_type' => $request->input('customer_type'),
            'user_id' => $request->user()?->id,
        ]);
        $quotation = $this->quotationService->create(
            $request->validated(),
            $branchId,
            $request->user()?->id
        );
        Log::info('[Quotation API] Created quotation ' . $quotation->quotation_number . ' (ID: ' . $quotation->id . ')');

        return response()->json([
            'message' => 'Quotation created successfully.',
            'data' => new QuotationResource($quotation),
        ], 201);
    }

    /**
     * Get Quotation by ID
     */
    public function show($id): JsonResponse
    {
        $quotation = Quotation::with([
            'patient',
            'labClient',
            'dentist',
            'labOrder',
            'branch',
            'items',
            'invoice',
            'approvals',
            'statusHistories.changedBy',
            'revisions',
            'originalQuotation',
        ])->findOrFail($id);

        $this->authorize('view', $quotation);

        return response()->json([
            'data' => new QuotationResource($quotation),
        ]);
    }

    /**
     * Update Draft Quotation
     */
    public function update(UpdateQuotationRequest $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('update', $quotation);
        Log::info("[Quotation API] PUT /quotations/$id", ['user_id' => $request->user()?->id]);

        $updated = $this->quotationService->update(
            $quotation,
            $request->validated(),
            $request->user()?->id
        );
        Log::info("[Quotation API] Updated quotation $id");

        return response()->json([
            'message' => 'Quotation updated successfully.',
            'data' => new QuotationResource($updated),
        ]);
    }

    /**
     * Delete or Cancel Quotation
     */
    public function destroy($id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('delete', $quotation);
        Log::info("[Quotation API] DELETE /quotations/$id", ['user_id' => request()->user()?->id]);

        $this->quotationService->deleteOrCancel($quotation, request()->user()?->id);
        Log::info("[Quotation API] Deleted quotation $id");

        return response()->json([
            'message' => 'Quotation deleted or cancelled successfully.',
        ]);
    }

    /**
     * Retrieve Patient Quotations
     */
    public function patientQuotations(Request $request, $patientId): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        $branchId = $this->getBranchId($request);
        $filters = array_merge($request->all(), [
            'customer_type' => 'patient',
            'patient_id' => $patientId,
        ]);

        $paginator = $this->quotationService->list($branchId, $filters);

        return response()->json([
            'data' => QuotationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create Quotation from Patient Treatment Plan
     */
    public function fromTreatmentPlan(CreateFromTreatmentPlanRequest $request, $patientId): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $branchId = $this->getBranchId($request);
        $quotation = $this->quotationService->createFromTreatmentPlan(
            (int) $patientId,
            $request->validated(),
            $branchId,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Quotation created from treatment plan successfully.',
            'data' => new QuotationResource($quotation),
        ], 201);
    }

    /**
     * Retrieve Laboratory Client Quotations
     */
    public function labClientQuotations(Request $request, $labClientId): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        $branchId = $this->getBranchId($request);
        $filters = array_merge($request->all(), [
            'customer_type' => 'lab_client',
            'lab_client_id' => $labClientId,
        ]);

        $paginator = $this->quotationService->list($branchId, $filters);

        return response()->json([
            'data' => QuotationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Retrieve Laboratory Order Quotations
     */
    public function labOrderQuotations(Request $request, $labOrderId): JsonResponse
    {
        $this->authorize('viewAny', Quotation::class);

        $branchId = $this->getBranchId($request);
        $filters = array_merge($request->all(), [
            'customer_type' => 'lab_client',
            'lab_order_id' => $labOrderId,
        ]);

        $paginator = $this->quotationService->list($branchId, $filters);

        return response()->json([
            'data' => QuotationResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create Quotation from Laboratory Order
     */
    public function fromLabOrder(CreateFromLabOrderRequest $request, $labOrderId = null): JsonResponse
    {
        $this->authorize('create', Quotation::class);

        $targetOrderId = $labOrderId ?: $request->input('lab_order_id');
        if (empty($targetOrderId)) {
            return response()->json(['message' => 'The lab_order_id field is required.'], 422);
        }

        $branchId = $this->getBranchId($request);
        $quotation = $this->quotationService->createFromLabOrder(
            (int) $targetOrderId,
            $request->validated(),
            $branchId,
            $request->user()?->id
        );

        return response()->json([
            'message' => 'Quotation generated from lab order successfully.',
            'data' => new QuotationResource($quotation),
        ], 201);
    }

    /**
     * Send Quotation
     */
    public function send(SendQuotationRequest $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('send', $quotation);
        

        $channels = $request->input('channels', ['email']);
        $recipientOptions = $request->only(['email', 'phone', 'phone_number', 'telephone_number']);
        $result = $this->notificationService->send(
            $quotation,
            $channels,
            $request->input('message'),
            $request->user()?->id,
            $recipientOptions
        );

        return response()->json([
            'message' => 'Quotation sent successfully.',
            'data' => $result,
        ]);
    }

    /**
     * Staff Record Approval
     */
    public function approve(ApproveQuotationRequest $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('approve', $quotation);
        Log::info("[Quotation API] POST /quotations/$id/approve", [
            'customer_name' => $request->input('customer_name'),
            'approval_method' => $request->input('approval_method'),
            'user_id' => $request->user()?->id,
        ]);

        $approved = $this->approvalService->recordStaffApproval(
            $quotation,
            $request->validated(),
            $request->user()?->id
        );
        Log::info("[Quotation API] Quotation $id approved.");

        return response()->json([
            'message' => 'Quotation approval recorded successfully.',
            'data' => new QuotationResource($approved),
        ]);
    }

    /**
     * Staff Record Rejection
     */
    public function reject(RejectQuotationRequest $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('reject', $quotation);
        Log::info("[Quotation API] POST /quotations/$id/reject", [
            'reason' => $request->input('reason'),
            'user_id' => $request->user()?->id,
        ]);

        $rejected = $this->approvalService->recordStaffRejection(
            $quotation,
            $request->validated(),
            $request->user()?->id
        );
        Log::info("[Quotation API] Quotation $id marked rejected.");

        return response()->json([
            'message' => 'Quotation rejection recorded successfully.',
            'data' => new QuotationResource($rejected),
        ]);
    }

    /**
     * Convert Approved Quotation into an Invoice
     */
    public function convert(Request $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('convert', $quotation);

        $branchId = $this->getBranchId($request);
        Log::info("[Quotation API] POST /quotations/$id/convert", [
            'branch_id' => $branchId,
            'user_id' => $request->user()?->id,
        ]);

        $invoice = $this->conversionService->convertToInvoice(
            $quotation,
            $branchId,
            $request->user()?->id
        );
        Log::info("[Quotation API] Quotation $id converted into Invoice ID: " . $invoice->id);

        return response()->json([
            'message' => 'Quotation converted into invoice successfully.',
            'data' => [
                'invoice' => $invoice,
                'quotation' => new QuotationResource($quotation->fresh()),
            ],
        ]);
    }

    /**
     * Renew Expired Quotation
     */
    public function renew(Request $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('update', $quotation);
        Log::info("[Quotation API] POST /quotations/$id/renew", ['user_id' => $request->user()?->id]);

        $renewed = $this->revisionService->renew(
            $quotation,
            $request->all(),
            $request->user()?->id
        );
        Log::info("[Quotation API] Quotation $id renewed.");

        return response()->json([
            'message' => 'Quotation renewed successfully.',
            'data' => new QuotationResource($renewed),
        ]);
    }

    /**
     * Revise Quotation
     */
    public function revise(Request $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('create', Quotation::class);
        Log::info("[Quotation API] POST /quotations/$id/revise", ['user_id' => $request->user()?->id]);

        $revised = $this->revisionService->revise(
            $quotation,
            $request->all(),
            $request->user()?->id
        );
        Log::info("[Quotation API] Created revised quotation #" . $revised->quotation_number . " for base quotation $id");

        return response()->json([
            'message' => 'Revised quotation created successfully.',
            'data' => new QuotationResource($revised),
        ], 201);
    }

    /**
     * Cancel Quotation
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $quotation = Quotation::findOrFail($id);
        $this->authorize('cancel', $quotation);
        Log::info("[Quotation API] POST /quotations/$id/cancel", ['reason' => $request->input('reason')]);

        $reason = $request->input('reason', 'Cancelled by staff.');
        $this->statusService->transition($quotation, 'cancelled', $request->user()?->id, 'staff', $reason);
        Log::info("[Quotation API] Quotation $id marked cancelled.");

        return response()->json([
            'message' => 'Quotation cancelled successfully.',
            'data' => new QuotationResource($quotation->fresh()),
        ]);
    }

    /**
     * Get Quotation PDF Data (JSON) or Binary
     */
    public function pdf(Request $request, $id)
    {
        $quotation = Quotation::with([
            'items',
            'patient',
            'labClient',
            'dentist',
            'labOrder',
            'branch.setting',
        ])->findOrFail($id);

        $this->authorize('view', $quotation);

        $format = $request->input('format');
        if ($format === 'raw_pdf' || $format === 'pdf' || (!$format && !$request->wantsJson())) {
            return $this->pdfService->response($quotation, 'pdf');
        }
        if ($format === 'html') {
            return $this->pdfService->response($quotation, 'html');
        }

        $branding = $this->pdfService->getBranding($quotation);

        return response()->json([
            'quotation' => new QuotationResource($quotation),
            'branding' => $branding,
        ]);
    }

    /**
     * Get Quotation History and Approvals
     */
    public function history($id): JsonResponse
    {
        $quotation = Quotation::with([
            'statusHistories.changedBy',
            'approvals.approvedBy',
        ])->findOrFail($id);

        $this->authorize('view', $quotation);

        return response()->json([
            'data' => [
                'status_history' => $quotation->statusHistories,
                'approvals' => $quotation->approvals,
            ]
        ]);
    }
}

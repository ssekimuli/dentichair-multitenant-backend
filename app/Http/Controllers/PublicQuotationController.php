<?php

namespace App\Http\Controllers;

use App\Http\Requests\Quotation\PublicApprovalRequest;
use App\Http\Resources\Quotation\QuotationPublicResource;
use App\Services\Quotation\QuotationApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Public Quotation Approval
 *
 * Publicly accessible endpoints for customer online quotation review, acceptance and rejection.
 */
class PublicQuotationController extends Controller
{
    public function __construct(
        protected QuotationApprovalService $approvalService
    ) {}

    /**
     * View Quotation (Public)
     *
     * Review quotation details securely using a public cryptographically signed token.
     */
    public function show(string $token): JsonResponse
    {
        [$quotation] = $this->approvalService->validatePublicToken($token);

        return response()->json([
            'data' => new QuotationPublicResource($quotation),
        ]);
    }

    /**
     * Approve Quotation (Public)
     *
     * Customer records their formal agreement and consent to proceed with the quotation.
     */
    public function approve(PublicApprovalRequest $request, string $token): JsonResponse
    {
        $quotation = $this->approvalService->publicApprove($token, $request->validated());

        return response()->json([
            'message' => 'Thank you! Your approval has been confirmed successfully.',
            'data' => new QuotationPublicResource($quotation),
        ]);
    }

    /**
     * Reject Quotation (Public)
     *
     * Customer declines the quotation.
     */
    public function reject(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
            'customer_name' => 'nullable|string|max:255',
        ]);

        $quotation = $this->approvalService->publicReject($token, $validated);

        return response()->json([
            'message' => 'Your response has been recorded. Thank you for notifying us.',
            'data' => new QuotationPublicResource($quotation),
        ]);
    }

    /**
     * Download / Stream Quotation PDF (Public)
     */
    public function pdf(string $token): \Illuminate\Http\Response
    {
        [$quotation] = $this->approvalService->validatePublicToken($token);
        return app(\App\Services\Quotation\QuotationPdfService::class)->response($quotation, 'pdf');
    }
}


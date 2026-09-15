<?php

declare(strict_types=1);

namespace App\Domain\Billing\Controllers;

use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Repositories\PaymentRepository;
use App\Domain\Billing\Requests\PaymentRequest;
use App\Domain\Billing\Resources\PaymentResource;
use App\Domain\Billing\Services\PaymentService;
use App\Domain\Shared\Http\Controllers\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Money in and money out, as records of their own.
 *
 * Separate from Billing because a payment is not an invoice. It has its own
 * date, its own reference, and it may touch several documents — which is what
 * a status column on the invoice could never express.
 */
class PaymentController extends ApiController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentRepository $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->repository->paginate($this->filters($request), $this->perPage($request));

        return $this->collection(PaymentResource::collection($page), $page);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->item(new PaymentResource($payment->load('allocations.invoice')));
    }

    public function store(PaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->record(
            $request->payload(),
            $request->allocations(),
            $request->user()?->getAuthIdentifier(),
        );

        return $this->item(new PaymentResource($payment->load('allocations.invoice')), status: 201);
    }

    /**
     * Un-record a payment.
     *
     * The invoices it was holding up go back to what they were — recomputed
     * before the row goes, while its allocations can still be read. Money
     * entered against the wrong customer is the ordinary reason, and leaving
     * the documents marked paid afterwards would be the worse half of the
     * mistake.
     */
    public function destroy(Payment $payment): JsonResponse
    {
        $this->payments->delete($payment);

        return $this->noContent();
    }
}

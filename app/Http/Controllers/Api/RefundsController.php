<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Requests\UpdateRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Refund::query();
        $this->scopeMerchant($query, $request);

        foreach (['status', 'payment_id', 'order_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return RefundResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreRefundRequest $request): RefundResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);

        $refund = DB::transaction(function () use ($data): Refund {
            [$payment, $order] = $this->ensureRelationsBelongToMerchant(
                $data['merchant_id'],
                $data['payment_id'],
                $data['order_id'] ?? null,
            );
            $this->ensureRefundIsAllowed($payment, $data['amount']);
            if (! isset($data['order_id']) && $order) {
                $data['order_id'] = $order->id;
            }

            $refund = Refund::create($data);
            $this->syncRefundedBalances($payment, $order);

            return $refund;
        });

        return RefundResource::make($refund);
    }

    public function show(Request $request, int $refund): RefundResource
    {
        return RefundResource::make($this->scopeMerchant(Refund::query(), $request)->findOrFail($refund));
    }

    public function update(UpdateRefundRequest $request, int $refund): RefundResource
    {
        $updatedRefund = DB::transaction(function () use ($request, $refund): Refund {
            $model = $this->scopeMerchant(Refund::query(), $request)
                ->lockForUpdate()
                ->findOrFail($refund);
            abort_if(ReturnRequest::where('refund_id', $model->id)->exists(), 409, 'A processed return uses this refund.');
            $originalPayment = Payment::where('merchant_id', $model->merchant_id)
                ->lockForUpdate()
                ->findOrFail($model->payment_id);
            $originalOrder = $model->order_id
                ? Order::where('merchant_id', $model->merchant_id)
                    ->lockForUpdate()
                    ->find($model->order_id)
                : null;
            $data = $request->validated();
            $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
            [$payment, $order] = $this->ensureRelationsBelongToMerchant(
                $data['merchant_id'],
                $data['payment_id'] ?? $model->payment_id,
                $data['order_id'] ?? $model->order_id,
            );
            $this->ensureRefundIsAllowed($payment, $data['amount'] ?? (float) $model->amount, $model);

            $model->update($data);

            if ($originalPayment->id !== $payment->id || $originalOrder?->id !== $order?->id) {
                $this->syncRefundedBalances($originalPayment, $originalOrder);
            }

            $this->syncRefundedBalances($payment, $order);

            return $model->fresh();
        });

        return RefundResource::make($updatedRefund);
    }

    public function destroy(Request $request, int $refund)
    {
        DB::transaction(function () use ($request, $refund): void {
            $model = $this->scopeMerchant(Refund::query(), $request)->lockForUpdate()->findOrFail($refund);
            abort_if(ReturnRequest::where('refund_id', $model->id)->exists(), 409, 'A processed return uses this refund.');
            $payment = Payment::where('merchant_id', $model->merchant_id)->lockForUpdate()->find($model->payment_id);
            $order = $model->order_id
                ? Order::where('merchant_id', $model->merchant_id)->lockForUpdate()->find($model->order_id)
                : ($payment?->order_id ? Order::where('merchant_id', $model->merchant_id)->lockForUpdate()->find($payment->order_id) : null);

            $model->delete();

            if ($payment) {
                $this->syncRefundedBalances($payment, $order);
            }
        });

        return response()->json(status: 204);
    }

    protected function ensureRelationsBelongToMerchant(int $merchantId, int $paymentId, ?int $orderId): array
    {
        $payment = Payment::where('merchant_id', $merchantId)
            ->lockForUpdate()
            ->findOrFail($paymentId);

        $order = null;
        if ($orderId) {
            $order = Order::where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->findOrFail($orderId);
            if ($payment->order_id && $payment->order_id !== $order->id) {
                abort(422, 'The payment does not belong to the specified order.');
            }
        } elseif ($payment->order_id) {
            $order = Order::where('merchant_id', $merchantId)
                ->lockForUpdate()
                ->find($payment->order_id);
        }

        return [$payment, $order];
    }

    protected function ensureRefundIsAllowed(Payment $payment, float $refundAmount, ?Refund $existingRefund = null): void
    {
        if (! in_array($payment->status, [
            PaymentStatus::Completed,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Refunded,
        ], true)) {
            throw ValidationException::withMessages([
                'payment_id' => ['Refunds may only be created for completed or previously refunded payments.'],
            ]);
        }

        $refundedAmount = (float) $payment->refunds()
            ->when($existingRefund, fn ($query) => $query->whereKeyNot($existingRefund->id))
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        if ($refundAmount <= 0 || ($refundedAmount + $refundAmount) > (float) $payment->amount) {
            throw ValidationException::withMessages([
                'amount' => ['The refund amount exceeds the remaining refundable balance.'],
            ]);
        }
    }

    protected function syncRefundedBalances(Payment $payment, ?Order $order): void
    {
        $refundedAmount = (float) $payment->refunds()
            ->where('status', RefundStatus::Processed)
            ->sum('amount');

        if ($refundedAmount >= (float) $payment->amount) {
            $payment->update(['status' => PaymentStatus::Refunded]);
        } elseif ($refundedAmount > 0) {
            $payment->update(['status' => PaymentStatus::PartiallyRefunded]);
        } else {
            $payment->update(['status' => PaymentStatus::Completed]);
        }

        if ($order) {
            $orderRefundedTotal = (float) Refund::where('order_id', $order->id)
                ->where('status', RefundStatus::Processed)
                ->sum('amount');
            $orderTotal = (float) $order->total_amount;

            if ($orderRefundedTotal >= $orderTotal && $orderTotal > 0) {
                $order->update(['payment_status' => OrderPaymentStatus::Refunded]);
            } elseif ($orderRefundedTotal > 0) {
                $order->update(['payment_status' => OrderPaymentStatus::PartiallyRefunded]);
            } else {
                $hasCompletedPayment = Payment::where('order_id', $order->id)->where('status', PaymentStatus::Completed)->exists();
                $order->update(['payment_status' => $hasCompletedPayment ? OrderPaymentStatus::Paid : OrderPaymentStatus::Unpaid]);
            }
        }
    }
}

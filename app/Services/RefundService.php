<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ReturnRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    public function __construct(
        protected PaymentService $paymentService,
        protected InventoryService $inventoryService,
    ) {}

    /**
     * Create a refund request. The amount is always derived or validated server-side from one
     * source: an approved return request, order items/quantities, the payment's full remaining
     * balance, or (legacy) an explicit amount capped at the refundable balance.
     *
     * Rows are locked order → payment → refund so refund and payment flows serialize per order.
     * A repeated request with the same Idempotency-Key returns the original refund.
     *
     * @param  array<string, mixed>  $data  Validated StoreRefundRequest data.
     * @return array{0: Refund, 1: bool} The refund and whether it was created by this call.
     *
     * @throws ValidationException
     */
    public function request(array $data, int $merchantId, ?int $userId = null, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($merchantId, $idempotencyKey))) {
            return [$existing, false];
        }

        try {
            $refund = DB::transaction(fn (): Refund => $this->createRefund($data, $merchantId, $userId, $idempotencyKey));
        } catch (UniqueConstraintViolationException $exception) {
            if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($merchantId, $idempotencyKey))) {
                return [$existing, false];
            }

            throw $exception;
        }

        return [$refund, true];
    }

    /**
     * Move a refund through its lifecycle. Repeating the current status is a no-op, so duplicate
     * clicks or gateway callbacks never complete a refund twice.
     *
     * @param  array{notes?: ?string, payout_reference?: ?string, failure_reason?: ?string, refunded_at?: ?string, cancel_order?: bool}  $details
     *
     * @throws ValidationException
     */
    public function transition(Refund $refund, RefundStatus $status, array $details = [], ?int $userId = null): Refund
    {
        return DB::transaction(function () use ($refund, $status, $details, $userId): Refund {
            [$payment, $order] = $this->lockPaymentAndOrder($refund->merchant_id, $refund->payment_id, $refund->order_id);
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if ($refund->status === $status) {
                return $refund;
            }

            if (! in_array($status, $refund->status->allowedTransitions(), true)) {
                throw ValidationException::withMessages([
                    'status' => ["A {$refund->status->value} refund cannot be changed to {$status->value}."],
                ]);
            }

            if (in_array($status, [RefundStatus::Processed, RefundStatus::Failed], true)) {
                $this->paymentService->ensureManualConfirmationAllowed($payment->gateway);
            }

            if (! empty($details['cancel_order'])) {
                $this->ensureOrderCanBeCancelled($order, $refund, (float) $refund->amount);
                $refund->cancel_order = true;
            }

            $from = $refund->status;

            match ($status) {
                RefundStatus::Processed => $this->complete($refund, $payment, $order, $details, $userId),
                RefundStatus::Failed => $refund->fill(['failure_reason' => $details['failure_reason'] ?? null]),
                RefundStatus::Processing => $refund->fill(['payout_reference' => $details['payout_reference'] ?? $refund->payout_reference]),
                default => null,
            };

            $refund->fill([
                'status' => $status,
                'notes' => $details['notes'] ?? $refund->notes,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ])->save();

            $this->syncBalances($refund, $payment, $order);
            $this->recordHistory($refund, $from, $status, $userId, $details['notes'] ?? $details['failure_reason'] ?? null);

            if ($status === RefundStatus::Processed) {
                $this->applyCompletionEffects($refund, $order?->refresh(), $userId);
            }

            return $refund;
        });
    }

    /**
     * Per order item: ordered, already refunded/reserved and still refundable quantities,
     * plus each collected payment's refundable balance.
     *
     * @return array{items: list<array<string, mixed>>, payments: list<array<string, mixed>>, refundable_amount: string}
     */
    public function refundableSummary(Order $order): array
    {
        $order->loadMissing('items');
        $reserved = $this->reservedQuantities($order);

        $items = $order->items->map(fn (OrderItem $item) => [
            'order_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'product_name' => $item->product_name,
            'sku' => $item->sku,
            'quantity' => (int) $item->quantity,
            'unit_price' => $item->unit_price,
            'total_price' => $item->total_price,
            'refunded_or_reserved_quantity' => (int) ($reserved[$item->id] ?? 0),
            'refundable_quantity' => max(0, (int) $item->quantity - (int) ($reserved[$item->id] ?? 0)),
        ])->values()->all();

        $payments = Payment::where('order_id', $order->id)
            ->whereIn('status', PaymentStatus::captured())
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment) => [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'method' => $payment->method?->value,
                'gateway' => $payment->gateway,
                'amount' => $payment->amount,
                'refundable_amount' => $this->money($this->remainingRefundable($payment)),
            ]);

        return [
            'items' => $items,
            'payments' => $payments->values()->all(),
            'refundable_amount' => $this->money($payments->sum(fn (array $payment) => (float) $payment['refundable_amount'])),
        ];
    }

    /**
     * Re-derive payment/order statuses and the ledger after a refund is created or edited.
     */
    public function syncBalances(Refund $refund, ?Payment $payment, ?Order $order): void
    {
        $this->syncPaymentAndOrder($payment, $order);
        $this->paymentService->syncRefundTransaction($refund);
    }

    public function syncPaymentAndOrder(?Payment $payment, ?Order $order): void
    {
        if ($payment) {
            $this->paymentService->syncPaymentRefundStatus($payment);
        }

        if ($order) {
            $this->paymentService->syncOrderPaymentStatus($order);
        }
    }

    /**
     * Refunds may only be issued against collected payments and never beyond what was paid.
     * Open refunds (requested, approved, processing, failed) reserve their amount; when
     * completing an existing refund only completed refunds are counted.
     *
     * @throws ValidationException
     */
    public function ensureRefundable(Payment $payment, float $amount, RefundStatus $status, ?Refund $existing = null): void
    {
        if (! $payment->status?->isCaptured()) {
            throw ValidationException::withMessages([
                'payment_id' => ['Refunds may only be created for completed or previously refunded payments.'],
            ]);
        }

        $counted = $existing ? [RefundStatus::Processed] : RefundStatus::committed();
        $committed = (float) $payment->refunds()
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
            ->whereIn('status', $counted)
            ->sum('amount');

        if ($amount <= 0 || $committed + $amount > (float) $payment->amount + 0.001) {
            throw ValidationException::withMessages([
                'amount' => ['The refund amount exceeds the remaining refundable balance of '.$this->money(max(0, (float) $payment->amount - $committed)).'.'],
            ]);
        }
    }

    /**
     * @return array{0: Payment, 1: ?Order}
     */
    public function lockPaymentAndOrder(int $merchantId, int $paymentId, ?int $orderId): array
    {
        $paymentOrderId = Payment::where('merchant_id', $merchantId)->whereKey($paymentId)->value('order_id');
        $lockOrderId = $orderId ?? $paymentOrderId;

        $order = $lockOrderId ? Order::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($lockOrderId) : null;
        $payment = Payment::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($paymentId);

        if ($order && $payment->order_id && $payment->order_id !== $order->id) {
            abort(422, 'The payment does not belong to the specified order.');
        }

        return [$payment, $order];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    protected function createRefund(array $data, int $merchantId, ?int $userId, ?string $idempotencyKey): Refund
    {
        $return = isset($data['return_request_id'])
            ? ReturnRequest::where('merchant_id', $merchantId)->findOrFail($data['return_request_id'])
            : null;
        $orderId = $return?->order_id
            ?? $data['order_id']
            ?? (isset($data['payment_id']) ? Payment::where('merchant_id', $merchantId)->whereKey($data['payment_id'])->value('order_id') : null);

        $order = $orderId ? Order::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId) : null;

        if (isset($data['order_id']) && $return && (int) $data['order_id'] !== $return->order_id) {
            throw ValidationException::withMessages(['order_id' => ['The return request belongs to a different order.']]);
        }

        if ($order && isset($data['customer_id']) && (int) $data['customer_id'] !== (int) $order->customer_id) {
            throw ValidationException::withMessages(['customer_id' => ['The customer does not own this order.']]);
        }

        [$items, $amount] = match (true) {
            $return !== null => $this->itemsFromReturn($return->refresh(), $order),
            ! empty($data['items']) => $this->itemsFromRequest($data['items'], $order),
            default => [collect(), isset($data['amount']) ? round((float) $data['amount'], 2) : null],
        };

        $payment = $this->resolvePayment($merchantId, $order, $data['payment_id'] ?? null, $amount);
        $amount ??= round($this->remainingRefundable($payment), 2);
        $status = RefundStatus::from($data['status'] ?? RefundStatus::Pending->value);

        $this->ensureRefundable($payment, $amount, RefundStatus::Pending);

        if ($status === RefundStatus::Processed) {
            $this->paymentService->ensureManualConfirmationAllowed($payment->gateway);
        }

        if (! empty($data['cancel_order'])) {
            $this->ensureOrderCanBeCancelled($order, null, $amount);
        }

        $refund = Refund::create([
            'merchant_id' => $merchantId,
            'payment_id' => $payment->id,
            'order_id' => $order?->id,
            'return_request_id' => $return?->id,
            'reference' => $data['reference'] ?? $this->paymentService->generateReference('RF'),
            'amount' => $amount,
            'reason' => $data['reason'] ?? $return?->reason,
            'notes' => $data['notes'] ?? null,
            'cancel_order' => (bool) ($data['cancel_order'] ?? false),
            'status' => RefundStatus::Pending,
            'requested_by' => $userId,
            'idempotency_key' => $idempotencyKey,
            'metadata' => $data['metadata'] ?? null,
        ]);
        $refund->items()->createMany($items->all());
        $this->recordHistory($refund, null, RefundStatus::Pending, $userId, 'Refund requested.');

        if ($status === RefundStatus::Processed) {
            $this->complete($refund, $payment, $order, $data, $userId);
            $refund->fill(['status' => RefundStatus::Processed, 'reviewed_by' => $userId, 'reviewed_at' => now()])->save();
            $this->recordHistory($refund, RefundStatus::Pending, RefundStatus::Processed, $userId, 'Recorded as already refunded.');
        }

        $this->syncBalances($refund, $payment, $order);

        if ($status === RefundStatus::Processed) {
            $this->applyCompletionEffects($refund, $order?->refresh(), $userId);
        }

        return $refund;
    }

    /**
     * Completion re-checks the payment balance against completed refunds only.
     *
     * @param  array<string, mixed>  $details
     */
    protected function complete(Refund $refund, Payment $payment, ?Order $order, array $details, ?int $userId): void
    {
        $this->ensureRefundable($payment, (float) $refund->amount, RefundStatus::Processed, $refund);

        $refund->fill([
            'refunded_at' => $details['refunded_at'] ?? now(),
            'payout_reference' => $details['payout_reference'] ?? $refund->payout_reference,
            'failure_reason' => null,
        ]);
    }

    /**
     * After a refund completes: process its return request (restocking returned items through
     * InventoryService) and, when requested, cancel the fully refunded unfulfilled order
     * (restoring its stock). Effects that no longer apply are noted in the history instead of
     * blocking a refund whose money has already moved.
     */
    protected function applyCompletionEffects(Refund $refund, ?Order $order, ?int $userId): void
    {
        if ($refund->return_request_id && $order) {
            $return = ReturnRequest::whereKey($refund->return_request_id)->lockForUpdate()->first();

            if ($return && $return->status === 'approved' && $return->refund_id === null) {
                $this->inventoryService->restoreStockForReturn($return, $order, $userId);
                $return->update(['status' => 'processed', 'refund_id' => $refund->id]);
                $this->recordHistory($refund, RefundStatus::Processed, RefundStatus::Processed, $userId, "Return request #{$return->id} processed and items restocked.");
            }
        }

        if ($refund->cancel_order && $order) {
            if ($order->payment_status === OrderPaymentStatus::Refunded
                && in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true)) {
                $this->inventoryService->restoreStockForOrder($order, 'Order cancelled: '.$order->order_number, $userId);
                $order->update(['status' => OrderStatus::Cancelled]);
                $this->recordHistory($refund, RefundStatus::Processed, RefundStatus::Processed, $userId, "Order {$order->order_number} cancelled and stock restored.");
            } else {
                $this->recordHistory($refund, RefundStatus::Processed, RefundStatus::Processed, $userId, "Order {$order->order_number} was not cancelled: it is no longer a fully refunded pending/processing order.");
            }
        }
    }

    /**
     * @return array{0: Collection<int, array{order_item_id: int, quantity: int, amount: float}>, 1: float}
     *
     * @throws ValidationException
     */
    protected function itemsFromReturn(ReturnRequest $return, ?Order $order): array
    {
        if ($return->status !== 'approved' || $return->refund_id !== null) {
            throw ValidationException::withMessages(['return_request_id' => ['Only approved return requests that have not been refunded can be refunded.']]);
        }

        if (Refund::where('return_request_id', $return->id)->whereIn('status', RefundStatus::committed())->exists()) {
            throw ValidationException::withMessages(['return_request_id' => ['A refund has already been requested for this return.']]);
        }

        $requested = $return->items()->get()->map(fn ($item) => ['order_item_id' => $item->order_item_id, 'quantity' => (int) $item->quantity]);
        [$items] = $this->itemsFromRequest($requested->all(), $order, $return->id);

        $items = $items->map(fn (array $item) => [
            ...$item,
            'amount' => (float) $return->items->firstWhere('order_item_id', $item['order_item_id'])->amount,
        ]);

        return [$items, round((float) $return->amount, 2)];
    }

    /**
     * Validate requested quantities against what is still refundable and price them from the
     * order items (never from the client).
     *
     * @param  array<int, array{order_item_id: int|string, quantity: int|string}>  $requested
     * @return array{0: Collection<int, array{order_item_id: int, quantity: int, amount: float}>, 1: float}
     *
     * @throws ValidationException
     */
    protected function itemsFromRequest(array $requested, ?Order $order, ?int $excludingReturnId = null): array
    {
        if (! $order) {
            throw ValidationException::withMessages(['items' => ['Item refunds require an order.']]);
        }

        $orderItems = $order->items()->get()->keyBy('id');
        $reserved = $this->reservedQuantities($order, $excludingReturnId);
        $items = collect();

        foreach (array_values($requested) as $index => $line) {
            $orderItem = $orderItems->get((int) $line['order_item_id']);
            $quantity = (int) $line['quantity'];

            if (! $orderItem) {
                throw ValidationException::withMessages(["items.{$index}.order_item_id" => ['The item does not belong to this order.']]);
            }

            $refundable = (int) $orderItem->quantity - (int) ($reserved[$orderItem->id] ?? 0);

            if ($quantity < 1 || $quantity > $refundable) {
                throw ValidationException::withMessages(["items.{$index}.quantity" => ["Only {$refundable} unit(s) of {$orderItem->product_name} can still be refunded."]]);
            }

            $items->push([
                'order_item_id' => $orderItem->id,
                'quantity' => $quantity,
                'amount' => round((float) $orderItem->total_price * $quantity / max(1, (int) $orderItem->quantity), 2),
            ]);
        }

        return [$items, round($items->sum('amount'), 2)];
    }

    /**
     * Quantities per order item already refunded or held by an open refund, plus quantities
     * held by return requests that have no refund of their own yet.
     *
     * @return Collection<int, int>
     */
    protected function reservedQuantities(Order $order, ?int $excludingReturnId = null): Collection
    {
        $committed = collect(RefundStatus::committed())->pluck('value')->all();

        $refunded = DB::table('refund_items')
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.order_id', $order->id)
            ->whereIn('refunds.status', $committed)
            ->groupBy('refund_items.order_item_id')
            ->selectRaw('refund_items.order_item_id as order_item_id, SUM(refund_items.quantity) as quantity')
            ->pluck('quantity', 'order_item_id');

        $returned = DB::table('return_request_items')
            ->join('return_requests', 'return_requests.id', '=', 'return_request_items.return_request_id')
            ->where('return_requests.order_id', $order->id)
            ->where('return_requests.status', '!=', 'rejected')
            ->when($excludingReturnId, fn ($query) => $query->where('return_requests.id', '!=', $excludingReturnId))
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('refunds')
                ->whereColumn('refunds.return_request_id', 'return_requests.id')
                ->whereIn('refunds.status', $committed))
            ->groupBy('return_request_items.order_item_id')
            ->selectRaw('return_request_items.order_item_id as order_item_id, SUM(return_request_items.quantity) as quantity')
            ->pluck('quantity', 'order_item_id');

        return $refunded->keys()->merge($returned->keys())->unique()
            ->mapWithKeys(fn ($id) => [(int) $id => (int) ($refunded[$id] ?? 0) + (int) ($returned[$id] ?? 0)]);
    }

    /**
     * Use the requested payment, or the most recent collected payment of the order that can
     * cover the amount.
     *
     * @throws ValidationException
     */
    protected function resolvePayment(int $merchantId, ?Order $order, ?int $paymentId, ?float $amount): Payment
    {
        if ($paymentId) {
            $payment = Payment::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($paymentId);

            if ($order && $payment->order_id && $payment->order_id !== $order->id) {
                abort(422, 'The payment does not belong to the specified order.');
            }

            return $payment;
        }

        if (! $order) {
            throw ValidationException::withMessages(['payment_id' => ['A payment or order is required.']]);
        }

        $payment = Payment::where('order_id', $order->id)
            ->whereIn('status', PaymentStatus::captured())
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get()
            ->first(fn (Payment $payment) => $this->remainingRefundable($payment) + 0.001 >= ($amount ?? 0.01));

        if (! $payment) {
            throw ValidationException::withMessages([
                'amount' => ['No collected payment on this order has enough refundable balance for this refund.'],
            ]);
        }

        return $payment;
    }

    /**
     * @throws ValidationException
     */
    protected function ensureOrderCanBeCancelled(?Order $order, ?Refund $refund, float $amount): void
    {
        if (! $order || ! in_array($order->status, [OrderStatus::Pending, OrderStatus::Processing], true)) {
            throw ValidationException::withMessages([
                'cancel_order' => ['Only pending or processing orders can be cancelled; completed orders are restocked through return requests.'],
            ]);
        }

        $captured = (float) Payment::where('order_id', $order->id)->whereIn('status', PaymentStatus::captured())->sum('amount');
        $otherRefunds = (float) Refund::where('order_id', $order->id)
            ->whereIn('status', RefundStatus::committed())
            ->when($refund, fn ($query) => $query->whereKeyNot($refund->id))
            ->sum('amount');

        if ($captured <= 0 || $otherRefunds + $amount + 0.001 < $captured) {
            throw ValidationException::withMessages([
                'cancel_order' => ['The order must be fully refunded before it can be cancelled.'],
            ]);
        }
    }

    protected function remainingRefundable(Payment $payment): float
    {
        return max(0, (float) $payment->amount - (float) $payment->refunds()->whereIn('status', RefundStatus::committed())->sum('amount'));
    }

    protected function recordHistory(Refund $refund, ?RefundStatus $from, RefundStatus $to, ?int $userId, ?string $notes): void
    {
        $refund->histories()->create([
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'notes' => $notes,
            'created_at' => now(),
        ]);
    }

    protected function findByIdempotencyKey(int $merchantId, string $key): ?Refund
    {
        return Refund::where('merchant_id', $merchantId)->where('idempotency_key', $key)->first();
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

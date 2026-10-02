<?php

namespace App\Services;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentService
{
    /**
     * Record a payment for an order: either initialise a pending payment that awaits
     * confirmation, or record money that was already received (status "completed").
     *
     * @param  array<string, mixed>  $data  Validated StorePaymentRequest data.
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function record(array $data, int $merchantId, ?int $userId = null, array $files = []): Payment
    {
        $status = PaymentStatus::from($data['status'] ?? PaymentStatus::Pending->value);
        $method = isset($data['method']) ? PaymentMethod::from($data['method']) : PaymentMethod::fromLegacyGateway($data['gateway'] ?? null);
        $gateway = $data['gateway'] ?? config('payments.default_gateway');

        if ($status === PaymentStatus::Completed) {
            $this->ensureManualConfirmationAllowed($gateway);
        }

        $attachments = $this->storeAttachments($files, $merchantId);

        try {
            return DB::transaction(function () use ($data, $merchantId, $userId, $status, $method, $gateway, $attachments): Payment {
                $order = isset($data['order_id'])
                    ? Order::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($data['order_id'])
                    : null;

                if ($order && $status !== PaymentStatus::Failed) {
                    $this->ensureOrderAcceptsPayment($order, (float) $data['amount']);
                }

                $payment = Payment::create([
                    'merchant_id' => $merchantId,
                    'order_id' => $order?->id,
                    'reference' => $data['reference'] ?? $this->generateReference('PAY'),
                    'gateway_reference' => $data['gateway_reference'] ?? null,
                    'gateway' => $gateway,
                    'method' => $method,
                    'status' => $status,
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? config('payments.currency'),
                    'paid_at' => $status === PaymentStatus::Completed ? ($data['paid_at'] ?? now()) : null,
                    'notes' => $data['notes'] ?? null,
                    'attachments' => $attachments ?: null,
                    'failure_reason' => $status === PaymentStatus::Failed ? ($data['failure_reason'] ?? null) : null,
                    'expires_at' => $status === PaymentStatus::Pending ? $this->expiryFor($method) : null,
                    'verified_by' => $status === PaymentStatus::Completed ? $userId : null,
                    'metadata' => $data['metadata'] ?? null,
                ]);

                if (in_array($status, [PaymentStatus::Completed, PaymentStatus::Failed], true)) {
                    $this->recordPaymentTransaction($payment);
                }

                if ($order) {
                    $this->syncOrderPaymentStatus($order);
                }

                return $payment;
            });
        } catch (Throwable $exception) {
            $this->discardAttachments($attachments);

            throw $exception;
        }
    }

    /**
     * Edit payment details. Amount and order may only change while the payment is pending,
     * so collected money and the ledger cannot be rewritten.
     *
     * @param  array<string, mixed>  $data  Validated UpdatePaymentRequest data.
     *
     * @throws ValidationException
     */
    public function updateDetails(Payment $payment, array $data, int $merchantId): Payment
    {
        return DB::transaction(function () use ($payment, $data, $merchantId): Payment {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $originalOrderId = $payment->order_id;
            $orderChanged = array_key_exists('order_id', $data) && (int) $data['order_id'] !== (int) $originalOrderId;
            $amountChanged = array_key_exists('amount', $data) && abs((float) $data['amount'] - (float) $payment->amount) > 0.001;

            if (($orderChanged || $amountChanged || $merchantId !== $payment->merchant_id) && $payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    $orderChanged ? 'order_id' : 'amount' => ['The amount, order and merchant of a non-pending payment cannot be changed.'],
                ]);
            }

            if (array_key_exists('paid_at', $data) && ! $payment->status->isCaptured()) {
                unset($data['paid_at']);
            }

            $orderId = $orderChanged ? $data['order_id'] : $originalOrderId;
            $order = $orderId ? Order::where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($orderId) : null;

            if ($order && ($orderChanged || $amountChanged)) {
                $this->ensureOrderAcceptsPayment($order, (float) ($data['amount'] ?? $payment->amount));
            }

            if (array_key_exists('method', $data)) {
                $data['method'] = $data['method'] !== null ? PaymentMethod::from($data['method']) : null;
            }

            $payment->update([...$data, 'merchant_id' => $merchantId]);

            foreach (array_unique(array_filter([$originalOrderId, $orderId])) as $affectedOrderId) {
                if ($affected = Order::whereKey($affectedOrderId)->lockForUpdate()->first()) {
                    $this->syncOrderPaymentStatus($affected);
                }
            }

            return $payment;
        });
    }

    /**
     * Move a pending payment to completed (verified receipt), failed or cancelled.
     *
     * @param  array{paid_at?: ?string, gateway_reference?: ?string, notes?: ?string, reason?: ?string}  $details
     *
     * @throws ValidationException
     */
    public function transition(Payment $payment, PaymentStatus $status, array $details = [], ?int $userId = null): Payment
    {
        return DB::transaction(function () use ($payment, $status, $details, $userId): Payment {
            $order = $payment->order_id ? Order::whereKey($payment->order_id)->lockForUpdate()->first() : null;
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === $status) {
                return $payment;
            }

            if ($payment->status !== PaymentStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => ["A {$payment->status->value} payment cannot be changed to {$status->value}."],
                ]);
            }

            $attributes = match ($status) {
                PaymentStatus::Completed => $this->completionAttributes($payment, $order, $details, $userId),
                PaymentStatus::Failed, PaymentStatus::Cancelled => ['failure_reason' => $details['reason'] ?? null],
                default => throw ValidationException::withMessages([
                    'status' => ["A pending payment cannot be changed to {$status->value}."],
                ]),
            };

            $payment->update([
                ...$attributes,
                'status' => $status,
                'notes' => $details['notes'] ?? $payment->notes,
            ]);

            if (in_array($status, [PaymentStatus::Completed, PaymentStatus::Failed], true)) {
                $this->recordPaymentTransaction($payment);
            }

            if ($order) {
                $this->syncOrderPaymentStatus($order);
            }

            return $payment;
        });
    }

    /**
     * Expire pending payments whose window has passed. Pending payments never count
     * toward an order's paid amount, so order statuses do not change.
     */
    public function expirePendingPayments(): int
    {
        return Payment::where('status', PaymentStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => PaymentStatus::Expired, 'updated_at' => now()]);
    }

    /**
     * Amounts owed, collected and refunded for an order.
     *
     * @return array{total_amount: string, amount_paid: string, amount_refunded: string, net_paid: string, pending_amount: string, outstanding_balance: string}
     */
    public function orderBalance(Order $order): array
    {
        $paid = $this->capturedTotal($order);
        $refunded = $this->refundedTotal($order);
        $pending = (float) Payment::where('order_id', $order->id)->where('status', PaymentStatus::Pending)->sum('amount');
        $total = (float) $order->total_amount;

        return [
            'total_amount' => $this->money($total),
            'amount_paid' => $this->money($paid),
            'amount_refunded' => $this->money($refunded),
            'net_paid' => $this->money($paid - $refunded),
            'pending_amount' => $this->money($pending),
            'outstanding_balance' => $this->money(max(0, $total - $paid)),
        ];
    }

    /**
     * Derive the order's payment status from captured payments and processed refunds.
     */
    public function syncOrderPaymentStatus(Order $order): void
    {
        $paid = $this->capturedTotal($order);
        $refunded = $this->refundedTotal($order);
        $total = (float) $order->total_amount;

        $status = match (true) {
            $refunded > 0 && $refunded + 0.001 >= $paid => OrderPaymentStatus::Refunded,
            $refunded > 0 => OrderPaymentStatus::PartiallyRefunded,
            $paid > 0 && $paid + 0.001 >= $total => OrderPaymentStatus::Paid,
            $paid > 0 => OrderPaymentStatus::PartiallyPaid,
            default => OrderPaymentStatus::Unpaid,
        };

        if ($order->payment_status !== $status) {
            $order->update(['payment_status' => $status]);
        }
    }

    /**
     * Derive a captured payment's refund status from its processed refunds.
     */
    public function syncPaymentRefundStatus(Payment $payment): void
    {
        if (! $payment->status?->isCaptured()) {
            return;
        }

        $refunded = (float) $payment->refunds()->where('status', RefundStatus::Processed)->sum('amount');

        $status = match (true) {
            $refunded + 0.001 >= (float) $payment->amount && $refunded > 0 => PaymentStatus::Refunded,
            $refunded > 0 => PaymentStatus::PartiallyRefunded,
            default => PaymentStatus::Completed,
        };

        if ($payment->status !== $status) {
            $payment->update(['status' => $status]);
        }
    }

    /**
     * Keep the single ledger row for a refund (source_key "refund:{id}") in step with it:
     * "completed" once processed, "failed" when a payout attempt failed, and "failed"
     * ("reversed") if a previously processed refund no longer is. Refunds that never reached
     * processing or failure have no ledger row.
     */
    public function syncRefundTransaction(Refund $refund): void
    {
        $transaction = Transaction::where('source_key', self::refundSourceKey($refund))->first()
            ?? Transaction::where('refund_id', $refund->id)->first();
        $processed = $refund->status === RefundStatus::Processed;
        $failed = $refund->status === RefundStatus::Failed;

        if (! $processed && ! $failed) {
            $transaction?->update(['status' => TransactionStatus::Failed, 'description' => 'Refund '.$refund->reference.' reversed']);

            return;
        }

        $attributes = [
            'merchant_id' => $refund->merchant_id,
            'payment_id' => $refund->payment_id,
            'order_id' => $refund->order_id,
            'type' => TransactionType::Refund,
            'status' => $processed ? TransactionStatus::Completed : TransactionStatus::Failed,
            'amount' => $refund->amount,
            'description' => ($processed ? 'Refund ' : 'Failed refund ').$refund->reference,
            'transacted_at' => $processed ? ($refund->refunded_at ?? now()) : now(),
        ];

        $transaction
            ? $transaction->update($attributes)
            : Transaction::create([
                ...$attributes,
                'refund_id' => $refund->id,
                'source_key' => self::refundSourceKey($refund),
                'reference' => $this->generateReference('TXN'),
                'metadata' => ['source' => 'refund'],
            ]);
    }

    public function generateReference(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    /**
     * @throws ValidationException
     */
    public function ensureManualConfirmationAllowed(?string $gateway): void
    {
        if (in_array($gateway, config('payments.integrated_gateways', []), true)) {
            throw ValidationException::withMessages([
                'status' => ["Payments through {$gateway} are confirmed by verified gateway callbacks only."],
            ]);
        }
    }

    /**
     * @param  array{paid_at?: ?string, gateway_reference?: ?string}  $details
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    protected function completionAttributes(Payment $payment, ?Order $order, array $details, ?int $userId): array
    {
        $this->ensureManualConfirmationAllowed($payment->gateway);

        if ($payment->expires_at?->isPast()) {
            throw ValidationException::withMessages([
                'status' => ['The payment expired on '.$payment->expires_at->toDayDateTimeString().' and can no longer be confirmed.'],
            ]);
        }

        if ($order) {
            $this->ensureOrderAcceptsPayment($order, (float) $payment->amount);
        }

        return [
            'paid_at' => $details['paid_at'] ?? now(),
            'gateway_reference' => $details['gateway_reference'] ?? $payment->gateway_reference,
            'verified_by' => $userId,
            'expires_at' => null,
            'failure_reason' => null,
        ];
    }

    /**
     * @throws ValidationException
     */
    protected function ensureOrderAcceptsPayment(Order $order, float $amount): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['order_id' => ['Payments cannot be recorded for a cancelled order.']]);
        }

        $outstanding = max(0, (float) $order->total_amount - $this->capturedTotal($order));

        if ($amount > $outstanding + 0.001) {
            throw ValidationException::withMessages([
                'amount' => ['The amount exceeds the outstanding balance of '.$this->money($outstanding).'.'],
            ]);
        }
    }

    /**
     * Write the ledger row for a payment's outcome exactly once: "completed" when money was
     * collected, "failed" when the attempt was declined. The unique `source_key` makes a
     * repeated verification or gateway callback a no-op.
     */
    public function recordPaymentTransaction(Payment $payment): Transaction
    {
        $completed = $payment->status === PaymentStatus::Completed;

        return Transaction::firstOrCreate(['source_key' => self::paymentSourceKey($payment)], [
            'merchant_id' => $payment->merchant_id,
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'reference' => $this->generateReference('TXN'),
            'type' => TransactionType::Payment,
            'status' => $completed ? TransactionStatus::Completed : TransactionStatus::Failed,
            'amount' => $payment->amount,
            'description' => ($completed ? 'Payment ' : 'Failed payment ').$payment->reference.($payment->method ? ' via '.$payment->method->value : ''),
            'transacted_at' => $completed ? ($payment->paid_at ?? now()) : now(),
            'metadata' => ['source' => 'payment'],
        ]);
    }

    public static function paymentSourceKey(Payment $payment): string
    {
        return 'payment:'.$payment->id;
    }

    public static function refundSourceKey(Refund $refund): string
    {
        return 'refund:'.$refund->id;
    }

    protected function capturedTotal(Order $order): float
    {
        return (float) Payment::where('order_id', $order->id)->whereIn('status', PaymentStatus::captured())->sum('amount');
    }

    protected function refundedTotal(Order $order): float
    {
        return (float) Refund::where('order_id', $order->id)->where('status', RefundStatus::Processed)->sum('amount');
    }

    protected function expiryFor(?PaymentMethod $method): ?Carbon
    {
        $minutes = (int) config('payments.pending_expiry_minutes');

        return $method === PaymentMethod::Cod || $minutes <= 0 ? null : now()->addMinutes($minutes);
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{path: string, name: string, mime: ?string, size: int}>
     */
    protected function storeAttachments(array $files, int $merchantId): array
    {
        $stored = [];

        foreach ($files as $index => $file) {
            $path = $file->store(config('payments.attachments.directory').'/'.$merchantId, config('payments.attachments.disk'));

            if (! is_string($path) || $path === '') {
                $this->discardAttachments($stored);

                throw ValidationException::withMessages(["attachments.{$index}" => ['The attachment failed to upload.']]);
            }

            $stored[] = ['path' => $path, 'name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType(), 'size' => $file->getSize()];
        }

        return $stored;
    }

    /**
     * @param  list<array{path: string}>  $attachments
     */
    protected function discardAttachments(array $attachments): void
    {
        if ($attachments !== []) {
            Storage::disk(config('payments.attachments.disk'))->delete(array_column($attachments, 'path'));
        }
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}

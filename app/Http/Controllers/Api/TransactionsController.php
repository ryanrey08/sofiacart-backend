<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Transaction::query();
        $this->scopeMerchant($query, $request);

        foreach (['status', 'type', 'payment_id', 'order_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return TransactionResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StoreTransactionRequest $request): TransactionResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'] ?? null, $data['order_id'] ?? null);

        return TransactionResource::make(Transaction::create($data));
    }

    public function show(Request $request, int $transaction): TransactionResource
    {
        return TransactionResource::make($this->scopeMerchant(Transaction::query(), $request)->findOrFail($transaction));
    }

    public function update(UpdateTransactionRequest $request, int $transaction): TransactionResource
    {
        $model = $this->scopeMerchant(Transaction::query(), $request)->findOrFail($transaction);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'] ?? $model->payment_id, $data['order_id'] ?? $model->order_id);
        $model->update($data);

        return TransactionResource::make($model->fresh());
    }

    public function destroy(Request $request, int $transaction)
    {
        $this->scopeMerchant(Transaction::query(), $request)->findOrFail($transaction)->delete();

        return response()->json(status: 204);
    }

    protected function ensureRelationsBelongToMerchant(int $merchantId, ?int $paymentId, ?int $orderId): void
    {
        if ($paymentId) {
            Payment::where('merchant_id', $merchantId)->findOrFail($paymentId);
        }

        if ($orderId) {
            Order::where('merchant_id', $merchantId)->findOrFail($orderId);
        }
    }
}

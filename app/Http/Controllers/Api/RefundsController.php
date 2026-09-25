<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRefundRequest;
use App\Http\Requests\UpdateRefundRequest;
use App\Http\Resources\RefundResource;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Http\Request;

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

        return RefundResource::collection($query->latest()->paginate((int) $request->integer('per_page', 15)));
    }

    public function store(StoreRefundRequest $request): RefundResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'], $data['order_id'] ?? null);

        return RefundResource::make(Refund::create($data));
    }

    public function show(Request $request, int $refund): RefundResource
    {
        return RefundResource::make($this->scopeMerchant(Refund::query(), $request)->findOrFail($refund));
    }

    public function update(UpdateRefundRequest $request, int $refund): RefundResource
    {
        $model = $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureRelationsBelongToMerchant($data['merchant_id'], $data['payment_id'] ?? $model->payment_id, $data['order_id'] ?? $model->order_id);
        $model->update($data);

        return RefundResource::make($model->fresh());
    }

    public function destroy(Request $request, int $refund)
    {
        $this->scopeMerchant(Refund::query(), $request)->findOrFail($refund)->delete();

        return response()->json(status: 204);
    }

    protected function ensureRelationsBelongToMerchant(int $merchantId, int $paymentId, ?int $orderId): void
    {
        Payment::where('merchant_id', $merchantId)->findOrFail($paymentId);

        if ($orderId) {
            Order::where('merchant_id', $merchantId)->findOrFail($orderId);
        }
    }
}

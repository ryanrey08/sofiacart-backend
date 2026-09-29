<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentsController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Payment::query();
        $this->scopeMerchant($query, $request);

        foreach (['status', 'gateway', 'order_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where('reference', 'like', "%{$search}%");
        }

        return PaymentResource::collection($query->latest()->paginate($this->pageSize($request)));
    }

    public function store(StorePaymentRequest $request): PaymentResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);
        $this->ensureOrderBelongsToMerchant($data['merchant_id'], $data['order_id'] ?? null);

        return PaymentResource::make(Payment::create($data));
    }

    public function show(Request $request, int $payment): PaymentResource
    {
        return PaymentResource::make($this->scopeMerchant(Payment::query(), $request)->findOrFail($payment));
    }

    public function update(UpdatePaymentRequest $request, int $payment): PaymentResource
    {
        $model = $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $this->ensureOrderBelongsToMerchant($data['merchant_id'], $data['order_id'] ?? $model->order_id);
        $model->update($data);

        return PaymentResource::make($model->fresh());
    }

    public function destroy(Request $request, int $payment)
    {
        $this->scopeMerchant(Payment::query(), $request)->findOrFail($payment)->delete();

        return response()->json(status: 204);
    }

    protected function ensureOrderBelongsToMerchant(int $merchantId, ?int $orderId): void
    {
        if ($orderId) {
            Order::where('merchant_id', $merchantId)->findOrFail($orderId);
        }
    }
}

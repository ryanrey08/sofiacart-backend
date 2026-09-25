<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\InteractsWithMerchantScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use InteractsWithMerchantScope;

    public function index(Request $request)
    {
        $query = Customer::query();
        $this->scopeMerchant($query, $request);

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return CustomerResource::collection($query->latest()->paginate((int) $request->integer('per_page', 15)));
    }

    public function store(StoreCustomerRequest $request): CustomerResource
    {
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? null);

        return CustomerResource::make(Customer::create($data));
    }

    public function show(Request $request, int $customer): CustomerResource
    {
        return CustomerResource::make($this->scopeMerchant(Customer::query(), $request)->findOrFail($customer));
    }

    public function update(UpdateCustomerRequest $request, int $customer): CustomerResource
    {
        $model = $this->scopeMerchant(Customer::query(), $request)->findOrFail($customer);
        $data = $request->validated();
        $data['merchant_id'] = $this->merchantIdForWrite($request, $data['merchant_id'] ?? $model->merchant_id);
        $model->update($data);

        return CustomerResource::make($model->fresh());
    }

    public function destroy(Request $request, int $customer)
    {
        $this->scopeMerchant(Customer::query(), $request)->findOrFail($customer)->delete();

        return response()->json(status: 204);
    }
}

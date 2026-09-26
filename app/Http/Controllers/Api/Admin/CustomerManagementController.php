<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCustomerResource;
use App\Http\Resources\OrderResource;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->withCount('orders');

        if ($merchantId = $request->integer('merchant_id')) {
            $query->where('merchant_id', $merchantId);
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        return AdminCustomerResource::collection(
            $query->latest()->paginate((int) $request->integer('per_page', 15))
        );
    }

    public function show(Customer $customer)
    {
        return response()->json([
            'data' => array_merge(
                AdminCustomerResource::make($customer->loadCount('orders'))->resolve(),
                [
                    'merchant_id' => $customer->merchant_id,
                    'recent_orders' => OrderResource::collection(
                        $customer->orders()->with(['items'])->latest('ordered_at')->limit(10)->get()
                    ),
                ],
            ),
        ]);
    }

    public function orders(Request $request, Customer $customer)
    {
        return OrderResource::collection(
            $customer->orders()->with(['customer', 'items'])->latest('ordered_at')->paginate((int) $request->integer('per_page', 15))
        );
    }
}

<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Models\InventoryLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    /**
     * Lock and validate products for an incoming order items payload.
     *
     * @param int $merchantId
     * @param array $items
     * @return Collection<int, Product>
     * @throws ValidationException
     */
    public function lockAndValidateProductsForOrder(int $merchantId, array $items): Collection
    {
        $productIds = collect($items)
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($productIds->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Product> $products */
        $products = Product::where('merchant_id', $merchantId)
            ->whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $variants = ProductVariant::whereIn('id', collect($items)->pluck('product_variant_id')->filter()->unique()->sort())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($products as $product) {
            $product->setRelation('lockedVariants', $variants);
        }

        $requestedQuantities = [];
        $variantQuantities = [];

        foreach ($items as $index => $item) {
            if (empty($item['product_id'])) {
                continue;
            }

            $pid = (int) $item['product_id'];
            /** @var Product|null $product */
            $product = $products->get($pid);

            if (! $product) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ["Product #{$pid} does not exist or does not belong to the merchant."],
                ]);
            }

            if ($product->status !== ProductStatus::Active) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => ["The product '{$product->name}' is not active and cannot be ordered."],
                ]);
            }
            $variant = null;
            if (isset($item['product_variant_id'])) {
                $variant = $variants->get((int) $item['product_variant_id']);
                if (! $variant || $variant->product_id !== $pid) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => ['The variant does not belong to the selected product.'],
                    ]);
                }
            }

            if (isset($item['unit_price'])) {
                $submittedPrice = round((float) $item['unit_price'], 2);
                $actualPrice = round((float) ($variant?->price ?? $product->price), 2);

                if (abs($submittedPrice - $actualPrice) > 0.001) {
                    throw ValidationException::withMessages([
                        "items.{$index}.unit_price" => ["The unit price for '{$product->name}' does not match the current product price."],
                    ]);
                }
            }

            $quantity = (int) ($item['quantity'] ?? 1);
            if ($variant) {
                $variantQuantities[$variant->id] = ($variantQuantities[$variant->id] ?? 0) + $quantity;
            } else {
                $requestedQuantities[$pid] = ($requestedQuantities[$pid] ?? 0) + $quantity;
            }
        }
        foreach ($variantQuantities as $id => $quantity) {
            if ($variants->get($id)->stock < $quantity) {
                throw ValidationException::withMessages(['items' => ['Insufficient variant stock.']]);
            }
        }

        foreach ($requestedQuantities as $pid => $totalQty) {
            $product = $products->get($pid);
            if ($product && $product->stock_quantity < $totalQty) {
                throw ValidationException::withMessages([
                    'items' => ["Insufficient stock for product '{$product->name}'. Available: {$product->stock_quantity}, requested: {$totalQty}."],
                ]);
            }
        }

        return $products;
    }

    /**
     * Deduct stock for order items and write inventory logs.
     */
    public function deductStockForOrder(Order $order, array $items, Collection $lockedProducts, ?int $userId = null): void
    {
        $quantities = [];
        $variantQuantities = [];
        foreach ($items as $item) {
            if (! empty($item['product_id'])) {
                $pid = (int) $item['product_id'];
                if (! empty($item['product_variant_id'])) {
                    $vid = (int) $item['product_variant_id'];
                    $variantQuantities[$vid] = ($variantQuantities[$vid] ?? 0) + (int) $item['quantity'];
                } else {
                    $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $item['quantity'];
                }
            }
        }
        foreach ($variantQuantities as $id => $qty) {
            $variant = $lockedProducts->first()->getRelation('lockedVariants')->get($id);
            $variant->decrement('stock', $qty);
            InventoryLog::create([
                'merchant_id' => $order->merchant_id,
                'product_id' => $variant->product_id,
                'user_id' => $userId,
                'reason' => 'Order created: '.$order->order_number,
                'quantity_change' => -$qty,
                'resulting_stock' => $variant->stock,
                'notes' => 'Deducted variant #'.$id.' for order #'.$order->order_number,
                'created_at' => now(),
            ]);
        }

        foreach ($quantities as $pid => $qty) {
            /** @var Product|null $product */
            $product = $lockedProducts->get($pid);
            if ($product) {
                $newStock = $product->stock_quantity - $qty;
                $product->update(['stock_quantity' => $newStock]);

                InventoryLog::create([
                    'merchant_id' => $order->merchant_id,
                    'product_id' => $product->id,
                    'user_id' => $userId,
                    'reason' => 'Order created: ' . $order->order_number,
                    'quantity_change' => -$qty,
                    'resulting_stock' => $newStock,
                    'notes' => "Deducted for order #{$order->order_number}",
                    'created_at' => now(),
                ]);
            }
        }
    }

    /**
     * Restore stock for an order idempotently.
     */
    public function restoreStockForOrder(Order $order, string $reason, ?int $userId = null): bool
    {
        if ($order->inventory_restored) {
            return false;
        }

        $order->loadMissing('items');

        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($productIds->isNotEmpty()) {
            $products = Product::where('merchant_id', $order->merchant_id)
                ->whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $variantQuantities = $order->items->whereNotNull('product_variant_id')
                ->groupBy('product_variant_id')->map(fn ($items) => $items->sum('quantity'));
            $variants = ProductVariant::whereIn('id', $variantQuantities->keys()->sort()->values())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($variantQuantities as $id => $qty) {
                if ($variant = $variants->get($id)) {
                    $variant->increment('stock', $qty);
                    InventoryLog::create([
                        'merchant_id' => $order->merchant_id,
                        'product_id' => $variant->product_id,
                        'user_id' => $userId,
                        'reason' => $reason,
                        'quantity_change' => $qty,
                        'resulting_stock' => $variant->stock,
                        'notes' => 'Restored variant #'.$id.' for order #'.$order->order_number,
                        'created_at' => now(),
                    ]);
                }
            }

            $quantities = [];
            foreach ($order->items as $item) {
                if ($item->product_id && ! $item->product_variant_id) {
                    $pid = (int) $item->product_id;
                    $quantities[$pid] = ($quantities[$pid] ?? 0) + (int) $item->quantity;
                }
            }

            foreach ($quantities as $pid => $qty) {
                $product = $products->get($pid);
                if ($product) {
                    $newStock = $product->stock_quantity + $qty;
                    $product->update(['stock_quantity' => $newStock]);

                    InventoryLog::create([
                        'merchant_id' => $order->merchant_id,
                        'product_id' => $product->id,
                        'user_id' => $userId,
                        'reason' => $reason,
                        'quantity_change' => $qty,
                        'resulting_stock' => $newStock,
                        'notes' => "Restored stock for order #{$order->order_number}",
                        'created_at' => now(),
                    ]);
                }
            }
        }

        $order->update(['inventory_restored' => true]);

        return true;
    }
}

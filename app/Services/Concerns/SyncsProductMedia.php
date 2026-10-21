<?php

namespace App\Services\Concerns;

use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Writes a product's gallery and variants. Shared by ProductsController (direct edits) and
 * ProductApprovalService (approved edits), so both apply them the same way.
 */
trait SyncsProductMedia
{
    protected function discardStoredImages(array $paths): void
    {
        try {
            Storage::disk('public')->delete($paths);
        } catch (Throwable) {
        }
    }

    protected function syncImages(Product $product, ?array $paths, ?int $mainIndex): void
    {
        if ($paths === null) {
            return;
        }

        if ($mainIndex !== null && $mainIndex >= count($paths)) {
            throw ValidationException::withMessages([
                'main_image_index' => ['The selected main image does not exist.'],
            ]);
        }

        $product->update(['images' => $paths]);
        foreach ($paths as $index => $path) {
            $product->imageRecords()->create([
                'path' => $path,
                'is_main' => $mainIndex === null ? $index === 0 : $index === $mainIndex,
                'sort_order' => $index,
            ]);
        }
    }

    protected function syncVariants(Product $product, mixed $variants): void
    {
        if ($variants === null) {
            return;
        }

        $existing = $product->variants()->lockForUpdate()->get()->keyBy('sku');
        $submitted = collect($variants)->pluck('sku');
        foreach ($existing as $variant) {
            if (! $submitted->contains($variant->sku)) {
                if (OrderItem::where('product_variant_id', $variant->id)->exists()) {
                    throw ValidationException::withMessages(['variants' => ['Ordered variants cannot be removed.']]);
                }
                $variant->delete();
            }
        }
        foreach ($variants as $index => $variant) {
            $attributes = [
                'sku' => $variant['sku'],
                'color' => $variant['color'] ?? null,
                'size' => $variant['size'] ?? null,
                'attributes' => $variant['attributes'] ?? null,
                'price' => $variant['price'],
                'stock' => $variant['stock'],
                'sort_order' => $variant['sort_order'] ?? $index,
            ];
            if ($current = $existing->get($variant['sku'])) {
                $current->update($attributes);
            } else {
                $product->variants()->create($attributes);
            }
        }
    }

    protected function syncExistingImages(Product $product, array $imageIds, ?int $mainId): void
    {
        $images = $product->imageRecords()->whereIn('id', $imageIds)->get()
            ->sortBy(fn ($image) => array_search($image->id, $imageIds, true))
            ->values();

        if ($images->isEmpty()) {
            $product->imageRecords()->delete();
        } else {
            $product->imageRecords()->whereNotIn('id', $images->pluck('id'))->delete();
        }
        foreach ($images as $index => $image) {
            $image->update([
                'sort_order' => $index,
                'is_main' => $mainId ? $image->id === $mainId : $index === 0,
            ]);
        }
        $product->update(['images' => $images->pluck('path')->values()->all()]);
    }
}

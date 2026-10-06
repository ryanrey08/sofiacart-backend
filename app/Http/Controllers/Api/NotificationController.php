<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notifications for the authenticated user. Used by both the merchant (`/api/v1`) and admin
 * (`/api/admin`) route groups; every query is scoped to the user, so no other ability is needed.
 */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['unread' => ['nullable', 'boolean']]);

        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        return NotificationResource::collection($query->paginate($this->pageSize($request, 10)))
            ->additional(['meta' => ['unread_count' => $request->user()->unreadNotifications()->count()]]);
    }

    public function markRead(Request $request, string $notification): NotificationResource
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        return NotificationResource::make($model);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }
}

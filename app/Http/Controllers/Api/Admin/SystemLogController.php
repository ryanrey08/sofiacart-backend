<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminAuditLogResource;
use App\Models\AdminAuditLog;
use Illuminate\Http\Request;

class SystemLogController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'module' => ['nullable', 'string', 'regex:/^[a-z-]+$/'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $query = AdminAuditLog::query()->with('actor');

        foreach (['action', 'actor_id', 'subject_type', 'subject_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($module = $request->string('module')->trim()->toString()) {
            $query->where('action', 'like', "admin.{$module}.%");
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date('date_from')->startOfDay());
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date('date_to')->endOfDay());
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        return AdminAuditLogResource::collection(
            $query->latest('created_at')->latest('id')->paginate($this->pageSize($request, 25))
        );
    }

    public function show(AdminAuditLog $log): AdminAuditLogResource
    {
        return AdminAuditLogResource::make($log->load('actor'));
    }
}

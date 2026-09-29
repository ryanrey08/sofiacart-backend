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
        $query = AdminAuditLog::query()->with('actor');

        foreach (['action', 'actor_id', 'subject_type', 'subject_id'] as $filter) {
            if ($value = $request->input($filter)) {
                $query->where($filter, $value);
            }
        }

        if ($search = $request->string('search')->toString()) {
            $query->where(function ($builder) use ($search): void {
                $builder->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%");
            });
        }

        return AdminAuditLogResource::collection(
            $query->latest('created_at')->paginate($this->pageSize($request, 25))
        );
    }

    public function show(AdminAuditLog $log): AdminAuditLogResource
    {
        return AdminAuditLogResource::make($log->load('actor'));
    }
}

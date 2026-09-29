<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'description',
        'is_secret',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_secret' => 'boolean',
        ];
    }
}

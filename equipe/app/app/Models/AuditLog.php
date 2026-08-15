<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'timestamp',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'changed_fields',
        'request_id',
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'changed_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuditLog $log) {
            $log->timestamp ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Registra ações administrativas/assistenciais sem duplicar PHI.
 * Grava apenas quem, quando, ação, entidade e nomes dos campos alterados.
 */
class AuditLogger
{
    public static function log(string $action, string $entityType, string|int $entityId, array $changedFields = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'changed_fields' => array_values($changedFields) ?: null,
            'request_id' => Request::header('X-Request-Id') ?? (string) Str::uuid(),
            'ip_hash' => Request::ip() ? hash('sha256', Request::ip()) : null,
        ]);
    }

    public static function logModel(string $action, Model $model, array $changedFields = []): AuditLog
    {
        return static::log($action, class_basename($model), $model->getKey(), $changedFields);
    }
}

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
            'request_id' => static::resolveRequestId(),
            'ip_hash' => Request::ip() ? static::hashIp(Request::ip()) : null,
        ]);
    }

    public static function logModel(string $action, Model $model, array $changedFields = []): AuditLog
    {
        return static::log($action, class_basename($model), $model->getKey(), $changedFields);
    }

    /**
     * O header X-Request-Id vem do cliente sem nenhuma garantia de formato
     * — nunca gravar direto na trilha de auditoria (alguém poderia forjar
     * o request_id de outra operação pra confundir uma investigação depois).
     * Só aceita se já for um UUID válido; caso contrário gera um novo.
     */
    private static function resolveRequestId(): string
    {
        $clientValue = Request::header('X-Request-Id');

        return $clientValue && Str::isUuid($clientValue) ? $clientValue : (string) Str::uuid();
    }

    /**
     * hash() simples de um IPv4 é reversível em minutos via rainbow table
     * (só 2^32 combinações) — HMAC com a APP_KEY como segredo evita isso.
     */
    private static function hashIp(string $ip): string
    {
        return hash_hmac('sha256', $ip, config('app.key'));
    }
}

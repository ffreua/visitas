<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'crm',
        'username',
        'password',
        'role',
        'must_change_password',
        'active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            $user->uuid ??= (string) Str::uuid();
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === 'ADMIN';
    }

    public function isPhysician(): bool
    {
        return $this->role === 'PHYSICIAN';
    }

    /**
     * Gestor observador: enxerga a lista assistencial e os dashboards e
     * nada mais — nenhuma escrita, nenhuma exportação. Quem garante isso
     * na prática é o middleware DenyObserverWrites (bloqueia todo verbo de
     * escrita) somado às Policies; este método é só a pergunta.
     */
    public function isObserver(): bool
    {
        return $this->role === 'OBSERVER';
    }

    /**
     * Colunas que registram AUTORIA de ato assistencial. Todas apontam para
     * users; as marcadas com nullOnDelete não impediriam a exclusão no
     * banco — apagariam silenciosamente o autor de uma visita ou de uma
     * pendência, que é justamente o que um registro clínico não pode
     * perder. Por isso a checagem é explícita, e não delegada à FK.
     */
    private const AUTHORSHIP_COLUMNS = [
        'admissions' => ['created_by', 'updated_by', 'deleted_by'],
        'admission_diagnoses' => ['created_by'],
        'pending_items' => ['created_by', 'resolved_by'],
        'daily_rounds' => ['assigned_physician_id', 'assigned_by', 'completed_by'],
    ];

    /**
     * Já assinou alguma coisa no sistema? Se sim, o usuário só pode ser
     * desativado — nunca excluído.
     */
    public function hasClinicalFootprint(): bool
    {
        foreach (self::AUTHORSHIP_COLUMNS as $table => $columns) {
            $exists = DB::table($table)
                ->where(function ($query) use ($columns) {
                    foreach ($columns as $column) {
                        $query->orWhere($column, $this->id);
                    }
                })
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }

    /**
     * É o último administrador ativo? Rebaixar, desativar ou excluir esse
     * usuário deixaria o sistema sem ninguém capaz de administrá-lo — e não
     * há como recriar um admin sem entrar como admin.
     */
    public function isLastActiveAdmin(): bool
    {
        if (! $this->isAdmin() || ! $this->active) {
            return false;
        }

        return ! static::query()
            ->where('role', 'ADMIN')
            ->where('active', true)
            ->whereKeyNot($this->getKey())
            ->exists();
    }
}

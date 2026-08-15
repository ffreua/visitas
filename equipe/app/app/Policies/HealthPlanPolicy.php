<?php

namespace App\Policies;

use App\Models\HealthPlan;
use App\Models\User;

class HealthPlanPolicy
{
    /**
     * Listagem administrativa completa (inclusive inativos) — não
     * confundir com o autocomplete público (HealthPlanController::search),
     * que não passa por Policy nenhuma e é aberto a qualquer médico
     * autenticado de propósito.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, HealthPlan $healthPlan): bool
    {
        return $user->isAdmin();
    }

    /**
     * Nunca apagar um plano já utilizado historicamente — apenas active=false.
     * Não existe rota de delete real; esta policy cobre só a desativação.
     */
    public function deactivate(User $user, HealthPlan $healthPlan): bool
    {
        return $user->isAdmin();
    }
}

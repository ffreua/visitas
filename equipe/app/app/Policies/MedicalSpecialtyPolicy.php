<?php

namespace App\Policies;

use App\Models\MedicalSpecialty;
use App\Models\User;

class MedicalSpecialtyPolicy
{
    /**
     * Listagem administrativa completa (inclusive inativos) — o
     * autocomplete público (MedicalSpecialtyController::search) não passa
     * por Policy nenhuma e continua aberto a qualquer médico autenticado.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, MedicalSpecialty $specialty): bool
    {
        return $user->isAdmin();
    }

    public function deactivate(User $user, MedicalSpecialty $specialty): bool
    {
        return $user->isAdmin();
    }
}

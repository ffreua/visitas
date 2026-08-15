<?php

namespace App\Policies;

use App\Models\Admission;
use App\Models\User;

class AdmissionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Admission $admission): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Admission $admission): bool
    {
        return true;
    }

    /**
     * Exclusão lógica (SoftDelete) — qualquer médico autenticado pode
     * retirar um episódio da lista assistencial. Nunca hard delete.
     */
    public function delete(User $user, Admission $admission): bool
    {
        return true;
    }

    public function viewTrashed(User $user): bool
    {
        return $user->isAdmin();
    }

    public function restore(User $user, Admission $admission): bool
    {
        return $user->isAdmin();
    }

    /**
     * Hard delete: exclusivo ADMIN. Mesmo assim o controller ainda deve
     * exigir reautenticação + frase de confirmação antes de executar.
     */
    public function forceDelete(User $user, Admission $admission): bool
    {
        return $user->isAdmin();
    }
}

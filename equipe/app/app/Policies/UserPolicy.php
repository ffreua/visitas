<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Nunca hard delete de usuário que já participou de atendimento —
     * a "exclusão" de um médico é sempre active=false (desativar).
     */
    public function deactivate(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function resetPassword(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}

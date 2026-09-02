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

    /**
     * Exclusão definitiva: só ADMIN, e nunca a própria conta (um admin que
     * se exclui pode deixar o sistema sem administrador). As demais
     * restrições — autoria assistencial e último admin ativo — dependem do
     * estado do banco e ficam no controller, com mensagem explicando o que
     * fazer no lugar.
     */
    public function delete(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->isNot($target);
    }
}

<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * Hoje qualquer médico autenticado pode ver/cadastrar pacientes (equipe
 * hospitalar compartilha a lista) — mas sem uma Policy explícita, uma
 * futura role (residente, auditoria, secretária) herdaria acesso a PHI
 * por omissão. Existir e retornar `true` documenta a decisão e dá um
 * único lugar para apertar o acesso depois.
 *
 * Esse "depois" chegou com OBSERVER (gestor observador): ele LÊ a lista
 * — é a finalidade do papel — mas não cria nem corrige cadastro.
 */
class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Patient $patient): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return ! $user->isObserver();
    }

    /**
     * Correção de cadastro (nome, nascimento, preenchimento do prontuário
     * ainda pendente) é parte do trabalho assistencial — a imutabilidade do
     * prontuário já confirmado é garantida pelo model, não pela policy.
     */
    public function update(User $user, Patient $patient): bool
    {
        return ! $user->isObserver();
    }
}

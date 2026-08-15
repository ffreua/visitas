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
        return true;
    }
}

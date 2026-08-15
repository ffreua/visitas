<?php

namespace App\Policies;

use App\Models\MedicalSpecialty;
use App\Models\User;

class MedicalSpecialtyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
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

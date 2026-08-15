<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    /**
     * Cria o primeiro administrador do sistema, caso nenhum exista ainda.
     * Não há tela de "criar conta" — este é o único bootstrap de acesso.
     */
    public function run(): void
    {
        if (User::where('role', 'ADMIN')->exists()) {
            return;
        }

        User::create([
            'uuid' => (string) Str::uuid(),
            'full_name' => 'Administrador',
            'username' => 'admin',
            'password' => Hash::make('senha@1234'),
            'role' => 'ADMIN',
            'must_change_password' => true,
            'active' => true,
        ]);

        $this->command?->warn('Usuário admin/senha@1234 criado. Troca de senha obrigatória no primeiro login.');
    }
}

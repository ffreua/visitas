<?php

namespace App\Http\Controllers;

use App\Models\User;

class PhysicianController extends Controller
{
    /**
     * Lista enxuta de médicos ativos para atribuição de responsável do dia
     * (seção 33-34 do PRD) — qualquer usuário autenticado pode consultar,
     * diferente da gestão de equipe completa (Admin\UserController, admin-only).
     *
     * OBSERVER fica de fora: gestor observador não faz visita, e aparecer no
     * seletor de responsável convidaria a atribuir o dia a quem não pode nem
     * assinar a visita depois.
     */
    public function index()
    {
        return response()->json(
            User::where('active', true)
                ->where('role', '!=', 'OBSERVER')
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'role'])
        );
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Novo papel OBSERVER ("gestor observador"): enxerga a lista assistencial e
 * os dashboards, e nada além disso — nenhuma escrita, nenhuma exportação.
 * Serve para quem acompanha o serviço sem participar do ato assistencial.
 *
 * A coluna `role` é um enum, que no SQLite vira `varchar CHECK (role IN
 * (...))`. Sem recriar a checagem o INSERT do novo papel seria recusado pelo
 * banco. Nenhuma linha existente é tocada: ADMIN e PHYSICIAN continuam
 * válidos, apenas passa a haver um terceiro valor aceito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['ADMIN', 'PHYSICIAN', 'OBSERVER'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['ADMIN', 'PHYSICIAN'])->change();
        });
    }
};

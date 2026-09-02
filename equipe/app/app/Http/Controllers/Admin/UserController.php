<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteUserRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    private const DEFAULT_PASSWORD = 'senha@1234';

    private const LAST_ADMIN_MESSAGE = 'Este é o último administrador ativo — sem ele ninguém poderia administrar o sistema. Promova outro usuário a administrador antes.';

    public function index()
    {
        $this->authorize('viewAny', User::class);

        $users = User::orderBy('full_name')->paginate(30);

        // A tela precisa saber, por usuário, se "Excluir" é possível — sem
        // isso o botão só descobriria no clique, depois de pedir a senha.
        $users->getCollection()->transform(function (User $user) {
            $user->can_delete = ! $user->hasClinicalFootprint() && ! $user->isLastActiveAdmin();

            return $user;
        });

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'crm' => ['nullable', 'string', 'max:50'],
            'username' => ['required', 'string', 'max:100', 'unique:users,username'],
            'role' => ['required', Rule::in(['ADMIN', 'PHYSICIAN'])],
        ]);

        $user = User::create([
            ...$data,
            'uuid' => (string) Str::uuid(),
            'password' => Hash::make(self::DEFAULT_PASSWORD),
            'must_change_password' => true,
            'active' => true,
        ]);

        AuditLogger::logModel('USER_CREATE', $user);

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'crm' => ['nullable', 'string', 'max:50'],
            // O login pode ter sido digitado errado no cadastro; único
            // ignorando o próprio registro para não colidir consigo mesmo.
            'username' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('users', 'username')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(['ADMIN', 'PHYSICIAN'])],
        ]);

        if (($data['role'] ?? $user->role) === 'PHYSICIAN' && $user->isLastActiveAdmin()) {
            throw ValidationException::withMessages(['role' => self::LAST_ADMIN_MESSAGE]);
        }

        $user->fill($data);
        $changed = array_keys($user->getDirty());

        if ($changed !== []) {
            $user->save();
            AuditLogger::logModel('USER_UPDATE', $user, $changed);
        }

        return response()->json($user);
    }

    public function deactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        if ($user->isLastActiveAdmin()) {
            throw ValidationException::withMessages(['active' => self::LAST_ADMIN_MESSAGE]);
        }

        $user->update(['active' => false]);

        AuditLogger::logModel('USER_DISABLE', $user);

        return response()->json($user);
    }

    public function reactivate(User $user)
    {
        $this->authorize('deactivate', $user);

        $user->update(['active' => true]);

        AuditLogger::logModel('USER_ENABLE', $user);

        return response()->json($user);
    }

    /**
     * Exclusão definitiva — só para conta criada por engano ou que nunca
     * chegou a ser usada. Quem já assinou visita, criou episódio ou
     * resolveu pendência não pode ser excluído: apagar o usuário apagaria
     * junto a autoria daqueles atos. Para esses, o caminho é desativar
     * (bloqueia o acesso e preserva o histórico).
     */
    public function destroy(DeleteUserRequest $request, User $user)
    {
        $this->authorize('delete', $user);

        if (! Hash::check($request->validated()['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        if ($user->isLastActiveAdmin()) {
            return response()->json(['message' => self::LAST_ADMIN_MESSAGE], 422);
        }

        if ($user->hasClinicalFootprint()) {
            return response()->json([
                'message' => 'Este usuário já participou de atendimentos — excluí-lo apagaria a autoria desses registros. Use "Desativar": o acesso é bloqueado e o histórico permanece.',
            ], 422);
        }

        // Registra ANTES de apagar: depois do delete não há mais modelo, e o
        // próprio audit_logs.user_id do autor da ação continua íntegro.
        AuditLogger::log('USER_DELETE', 'User', $user->uuid, ['username' => $user->username]);

        $user->delete();

        return response()->json(['message' => 'Usuário excluído.']);
    }

    public function resetPassword(User $user)
    {
        $this->authorize('resetPassword', $user);

        $user->update([
            'password' => Hash::make(self::DEFAULT_PASSWORD),
            'must_change_password' => true,
        ]);

        AuditLogger::logModel('PASSWORD_RESET', $user);

        return response()->json(['message' => 'Senha redefinida para o padrão. Troca obrigatória no próximo login.']);
    }
}

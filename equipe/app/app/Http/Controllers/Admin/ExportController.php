<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExportAdmissionsRequest;
use App\Models\User;
use App\Services\AdmissionExportService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ExportController extends Controller
{
    public function store(ExportAdmissionsRequest $request, AdmissionExportService $service)
    {
        Gate::authorize('viewAny', User::class);

        $data = $request->validated();
        $pseudonymized = (bool) ($data['pseudonymized'] ?? false);

        // Reautenticação obrigatória para exports identificáveis (seção 88).
        if (! $pseudonymized && ! Hash::check($data['password'], $request->user()->password)) {
            return response()->json(['message' => 'Senha incorreta.'], 403);
        }

        $result = $service->build($data, $pseudonymized);

        AuditLogger::log('EXPORT', 'Export', $result['filename'], [
            'filters' => array_keys(array_filter($data, fn ($v, $k) => ! in_array($k, ['password'], true) && $v !== null, ARRAY_FILTER_USE_BOTH)),
            'row_count' => $result['row_count'],
            'pseudonymized' => $pseudonymized,
        ]);

        return response()->json([
            'download_token' => $result['filename'],
            'row_count' => $result['row_count'],
        ], 201);
    }

    /**
     * Entrega o arquivo por rota autenticada (nunca um link público
     * previsível — seção 126) e remove o arquivo depois de servido.
     */
    public function download(string $token)
    {
        Gate::authorize('viewAny', User::class);

        // Token é só o basename do arquivo gerado por store(); barra qualquer
        // tentativa de path traversal antes de tocar no filesystem.
        $filename = basename($token);
        $path = rtrim(config('neurologia.exports_path'), '/\\').DIRECTORY_SEPARATOR.$filename;

        if (! Str::endsWith($filename, '.xlsx') || ! is_file($path)) {
            abort(404);
        }

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }
}

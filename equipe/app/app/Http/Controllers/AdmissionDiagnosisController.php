<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\CID10;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdmissionDiagnosisController extends Controller
{
    /**
     * Adiciona diagnóstico secundário (a hipótese/final principal já é
     * criada junto com store()/close() de Admission — seção 30 do PRD).
     */
    public function store(Request $request, Admission $admission)
    {
        $this->authorize('update', $admission);

        $data = $request->validate([
            'phase' => ['required', 'in:SUSPECTED,FINAL'],
            'cid_code' => ['required', 'string', 'exists:cid10,code'],
        ]);

        $diagnosis = DB::transaction(function () use ($admission, $data) {
            $cid = CID10::findOrFail($data['cid_code']);

            return $admission->diagnoses()->create([
                'phase' => $data['phase'],
                'cid_code' => $cid->code,
                'description_snapshot' => $cid->description,
                'is_primary' => false,
                'created_by' => Auth::id(),
            ]);
        });

        AuditLogger::log('UPDATE_ADMISSION', 'Admission', $admission->id, ['diagnoses']);

        return response()->json($diagnosis, 201);
    }
}

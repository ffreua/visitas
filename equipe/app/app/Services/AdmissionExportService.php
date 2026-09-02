<?php

namespace App\Services;

use App\Models\Admission;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Monta o workbook de exportação (seções 81-88 do PRD). Nunca grava dentro
 * de public_html — o caminho de destino vem de config('neurologia.exports_path').
 */
class AdmissionExportService
{
    /**
     * @return array{path: string, filename: string, row_count: int}
     */
    public function build(array $filters, bool $pseudonymized): array
    {
        $includeDeleted = false; // exports normais nunca incluem excluídos (seção 78) — auditoria tem sua própria tela.
        $admissions = AdmissionFilters::query($filters, $includeDeleted)->orderBy('admission_at')->get();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $this->buildPatientsSheet($spreadsheet, $admissions, $pseudonymized);
        $this->buildEpisodesSheet($spreadsheet, $admissions, $pseudonymized);
        $this->buildDiagnosesSheet($spreadsheet, $admissions, $pseudonymized);
        $this->buildVisitsSheet($spreadsheet, $admissions, $pseudonymized);
        $this->buildPendingItemsSheet($spreadsheet, $admissions, $pseudonymized);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'export_'.now()->format('Y-m-d_His').'_'.substr((string) Str::uuid(), 0, 8).'.xlsx';
        $path = rtrim(config('neurologia.exports_path'), '/\\').DIRECTORY_SEPARATOR.$filename;

        (new Xlsx($spreadsheet))->save($path);

        return ['path' => $path, 'filename' => $filename, 'row_count' => $admissions->count()];
    }

    private function patientCode(Admission $admission): string
    {
        return 'PAC-'.str_pad((string) $admission->patient_id, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Colunas de identificação repetidas no início de cada aba. Com
     * prontuário opcional (paciente cadastrado pelo número de atendimento),
     * o prontuário sozinho já não garante que a linha seja rastreável — o
     * número de atendimento entra ao lado dele como segunda chave.
     *
     * No modo pseudonimizado nenhum dos dois aparece: são identificadores
     * do sistema do hospital, exatamente o que a pseudonimização remove.
     *
     * @return array<int, string>
     */
    private function keyHeaders(bool $pseudonymized): array
    {
        return $pseudonymized ? ['Código do paciente'] : ['Prontuário', 'Nº atendimento'];
    }

    /**
     * @return array<int, string|null>
     */
    private function keyColumns(Admission $admission, bool $pseudonymized): array
    {
        return $pseudonymized
            ? [$this->patientCode($admission)]
            : [$admission->patient->medical_record_number, $admission->attendance_number];
    }

    private function buildPatientsSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Pacientes');

        $headers = $pseudonymized
            ? ['Código do paciente', 'Data de nascimento']
            : ['Prontuário', 'Nome', 'Data de nascimento', 'Prontuário confirmado'];
        $sheet->fromArray($headers, null, 'A1');

        $patients = $admissions->pluck('patient')->unique('id')->values();
        $row = 2;
        foreach ($patients as $patient) {
            if ($pseudonymized) {
                $sheet->fromArray([
                    'PAC-'.str_pad((string) $patient->id, 5, '0', STR_PAD_LEFT),
                    optional($patient->date_of_birth)->format('Y-m-d'),
                ], null, "A{$row}");
            } else {
                $sheet->fromArray([
                    $patient->medical_record_number,
                    $patient->full_name,
                    optional($patient->date_of_birth)->format('Y-m-d'),
                    $patient->hasConfirmedMedicalRecord() ? 'Sim' : 'Não',
                ], null, "A{$row}");
            }
            $row++;
        }
    }

    private function buildEpisodesSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Episodios');

        $headers = [
            ...$this->keyHeaders($pseudonymized),
            $pseudonymized ? null : 'Paciente',
            'Entrada hospitalar', 'Encerramento Neurologia', 'Alta hospitalar',
            'Procedência',
            'Institucional/Interconsulta', 'Avaliação única/Acompanhamento',
            'Particular/Plano', 'Plano', 'Especialidade solicitante',
            'CID suspeito', 'Diagnóstico suspeito', 'CID final', 'Diagnóstico final',
            'Responsáveis', 'Tempo acompanhamento Neurologia (dias)', 'Tempo internação hospitalar (dias)',
            'Status',
        ];
        $headers = array_values(array_filter($headers, fn ($h) => $h !== null));
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            $suspected = $admission->diagnoses->where('phase', 'SUSPECTED')->where('is_primary', true)->first();
            $final = $admission->diagnoses->where('phase', 'FINAL')->where('is_primary', true)->first();
            $responsibles = $admission->dailyRounds->pluck('assignedPhysician.full_name')->filter()->unique()->implode(', ');
            $neurologyDays = round($admission->neurology_followup_started_at->diffInDays($admission->neurology_followup_closed_at ?? now(), true), 1);
            $hospitalDays = $admission->hospital_discharge_at
                ? round($admission->admission_at->diffInDays($admission->hospital_discharge_at, true), 1)
                : null;

            $line = [
                ...$this->keyColumns($admission, $pseudonymized),
                ...($pseudonymized ? [] : [$admission->patient->full_name]),
                $admission->admission_at->format('Y-m-d H:i'),
                optional($admission->neurology_followup_closed_at)->format('Y-m-d H:i'),
                optional($admission->hospital_discharge_at)->format('Y-m-d H:i'),
                $admission->originLabel(),
                $admission->care_type === 'INTERCONSULT' ? 'Interconsulta' : 'Institucional',
                $admission->followup_mode === 'SINGLE_EVALUATION' ? 'Avaliação única' : 'Acompanhamento',
                $admission->payer_type === 'PRIVATE' ? 'Particular' : 'Plano de saúde',
                $admission->payer_type === 'PRIVATE' ? null : ($admission->health_plan_name_snapshot ?? $admission->healthPlan?->name),
                $admission->requestingSpecialty?->name,
                $suspected?->cid_code,
                $suspected?->description_snapshot,
                $final?->cid_code,
                $final?->description_snapshot,
                $responsibles,
                $neurologyDays,
                $hospitalDays,
                $admission->status === 'ACTIVE' ? 'Ativo' : 'Encerrado',
            ];
            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }
    }

    private function buildDiagnosesSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Diagnosticos');
        $sheet->fromArray([...$this->keyHeaders($pseudonymized), 'Fase', 'CID', 'Descrição', 'Principal'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->diagnoses as $diagnosis) {
                $sheet->fromArray([
                    ...$this->keyColumns($admission, $pseudonymized),
                    $diagnosis->phase === 'SUSPECTED' ? 'Hipótese' : 'Final',
                    $diagnosis->cid_code,
                    $diagnosis->description_snapshot,
                    $diagnosis->is_primary ? 'Sim' : 'Não',
                ], null, "A{$row}");
                $row++;
            }
        }
    }

    private function buildVisitsSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Visitas');
        $sheet->fromArray([...$this->keyHeaders($pseudonymized), 'Data', 'Responsável atribuído', 'Visita realizada por', 'Horário da visita'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->dailyRounds as $round) {
                $sheet->fromArray([
                    ...$this->keyColumns($admission, $pseudonymized),
                    $round->round_date->format('Y-m-d'),
                    $round->assignedPhysician?->full_name,
                    $round->completer?->full_name,
                    optional($round->completed_at)->format('Y-m-d H:i'),
                ], null, "A{$row}");
                $row++;
            }
        }
    }

    private function buildPendingItemsSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Pendencias');
        $sheet->fromArray([...$this->keyHeaders($pseudonymized), 'Descrição', 'Status', 'Criada em', 'Resolvida em'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->pendingItems as $item) {
                $sheet->fromArray([
                    ...$this->keyColumns($admission, $pseudonymized),
                    $item->description,
                    $item->status,
                    optional($item->created_at)->format('Y-m-d H:i'),
                    optional($item->resolved_at)->format('Y-m-d H:i'),
                ], null, "A{$row}");
                $row++;
            }
        }
    }
}

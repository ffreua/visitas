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

    private function buildPatientsSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Pacientes');

        $headers = $pseudonymized
            ? ['Código do paciente', 'Data de nascimento']
            : ['Prontuário', 'Nome', 'Data de nascimento'];
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
            $pseudonymized ? 'Código do paciente' : 'Prontuário',
            $pseudonymized ? null : 'Paciente',
            'Entrada hospitalar', 'Encerramento Neurologia', 'Alta hospitalar',
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
                $pseudonymized ? $this->patientCode($admission) : $admission->patient->medical_record_number,
                $pseudonymized ? null : $admission->patient->full_name,
                $admission->admission_at->format('Y-m-d H:i'),
                optional($admission->neurology_followup_closed_at)->format('Y-m-d H:i'),
                optional($admission->hospital_discharge_at)->format('Y-m-d H:i'),
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
            $line = array_values(array_filter($line, fn ($v, $k) => ! ($pseudonymized && $k === 1), ARRAY_FILTER_USE_BOTH));

            $sheet->fromArray($line, null, "A{$row}");
            $row++;
        }
    }

    private function buildDiagnosesSheet(Spreadsheet $spreadsheet, $admissions, bool $pseudonymized): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Diagnosticos');
        $sheet->fromArray([$pseudonymized ? 'Código do paciente' : 'Prontuário', 'Fase', 'CID', 'Descrição', 'Principal'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->diagnoses as $diagnosis) {
                $sheet->fromArray([
                    $pseudonymized ? $this->patientCode($admission) : $admission->patient->medical_record_number,
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
        $sheet->fromArray([$pseudonymized ? 'Código do paciente' : 'Prontuário', 'Data', 'Responsável atribuído', 'Visita realizada por', 'Horário da visita'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->dailyRounds as $round) {
                $sheet->fromArray([
                    $pseudonymized ? $this->patientCode($admission) : $admission->patient->medical_record_number,
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
        $sheet->fromArray([$pseudonymized ? 'Código do paciente' : 'Prontuário', 'Descrição', 'Status', 'Criada em', 'Resolvida em'], null, 'A1');

        $row = 2;
        foreach ($admissions as $admission) {
            foreach ($admission->pendingItems as $item) {
                $sheet->fromArray([
                    $pseudonymized ? $this->patientCode($admission) : $admission->patient->medical_record_number,
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

import { Link } from 'react-router-dom'
import { calculateAge, todayISODate, isSameLocalDate } from '../lib/format'

export default function AdmissionCard({ admission }) {
  const patient = admission.patient
  const age = calculateAge(patient?.date_of_birth)
  const today = todayISODate()

  const todaysRound = (admission.daily_rounds || []).find((r) => isSameLocalDate(r.round_date, today))
  const openPending = (admission.pending_items || []).filter((p) => p.status === 'OPEN').length
  const suspected = (admission.diagnoses || []).find((d) => d.phase === 'SUSPECTED' && d.is_primary)

  const physicianName = todaysRound?.assigned_physician?.full_name
    || todaysRound?.completer?.full_name
    || (todaysRound?.assigned_physician_id ? 'Atribuído' : null)

  const isVisited = !!todaysRound?.completed_at

  return (
    <Link to={`/atendimentos/${admission.id}`} className="patient-card">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
        <div>
          <div className="name">{patient?.full_name}{age !== null ? ` · ${age} anos` : ''}</div>
          <div className="meta">Prontuário {patient?.medical_record_number}</div>
        </div>
        <span className={`status-indicator ${isVisited ? 'status-visited' : 'status-pending'}`}>
          {isVisited ? '✓ Visitado' : 'Visita pendente'}
        </span>
      </div>

      {(admission.unit || admission.bed) && (
        <div className="meta" style={{ marginTop: 4 }}>
          📍 {admission.unit ? `Enfermaria ${admission.unit}` : ''}{admission.unit && admission.bed ? ' • ' : ''}
          {admission.bed ? `Leito ${admission.bed}` : ''}
        </div>
      )}

      <div className="meta">
        💳 {admission.payer_type === 'PRIVATE' ? 'Particular' : (admission.health_plan_name_snapshot || admission.health_plan?.name || 'Convênio')}
      </div>

      <div className="badge-row" style={{ marginTop: 8 }}>
        {admission.care_type === 'INTERCONSULT' ? (
          <span className="badge badge-info">Interconsulta{admission.requesting_specialty ? ` · ${admission.requesting_specialty.name}` : ''}</span>
        ) : (
          <span className="badge badge-neutral">Institucional</span>
        )}
        {admission.followup_mode === 'SINGLE_EVALUATION' && (
          <span className="badge badge-warning">Avaliação única</span>
        )}
      </div>

      {suspected && (
        <div className="diagnosis-preview">
          🩺 <strong>{suspected.cid_code}</strong> — {suspected.description_snapshot}
        </div>
      )}

      <div className="badge-row" style={{ marginTop: 10, borderTop: '1px solid var(--color-border-subtle)', paddingTop: 8 }}>
        {physicianName ? (
          <span className="badge badge-physician">👤 Resp: {physicianName}</span>
        ) : (
          <span className="badge badge-danger">⚠️ Sem responsável hoje</span>
        )}

        {openPending > 0 && (
          <span className="badge badge-warning">⏳ {openPending} pendência{openPending > 1 ? 's' : ''}</span>
        )}
      </div>
    </Link>
  )
}


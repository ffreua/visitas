import { Link } from 'react-router-dom'
import { calculateAge, todayISODate, isSameLocalDate } from '../lib/format'

export default function AdmissionCard({ admission }) {
  const patient = admission.patient
  const age = calculateAge(patient?.date_of_birth)
  const today = todayISODate()

  const todaysRound = (admission.daily_rounds || []).find((r) => isSameLocalDate(r.round_date, today))
  const openPending = (admission.pending_items || []).filter((p) => p.status === 'OPEN').length
  const suspected = (admission.diagnoses || []).find((d) => d.phase === 'SUSPECTED' && d.is_primary)

  return (
    <Link to={`/atendimentos/${admission.id}`} className="patient-card">
      <div className="name">{patient?.full_name}{age !== null ? ` · ${age} anos` : ''}</div>
      <div className="meta">Prontuário {patient?.medical_record_number}</div>
      {(admission.unit || admission.bed) && (
        <div className="meta">
          {admission.unit ? `Enfermaria ${admission.unit}` : ''}{admission.unit && admission.bed ? ' • ' : ''}
          {admission.bed ? `Leito ${admission.bed}` : ''}
        </div>
      )}
      <div className="meta">
        {admission.payer_type === 'PRIVATE' ? 'Particular' : (admission.health_plan_name_snapshot || admission.health_plan?.name)}
      </div>

      <div className="badge-row">
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
        <div className="meta">{suspected.cid_code} — {suspected.description_snapshot}</div>
      )}

      <div className="badge-row">
        {todaysRound?.assigned_physician_id ? (
          <span className="badge badge-neutral">Resp. hoje: {todaysRound.assigned_physician?.full_name || '—'}</span>
        ) : (
          <span className="badge badge-danger">Sem responsável hoje</span>
        )}

        {todaysRound?.completed_at ? (
          <span className="badge badge-success">Visitado hoje</span>
        ) : (
          <span className="badge badge-neutral">Ainda não visitado hoje</span>
        )}

        {openPending > 0 && (
          <span className="badge badge-warning">{openPending} pendência{openPending > 1 ? 's' : ''}</span>
        )}
      </div>
    </Link>
  )
}

import { useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../lib/api'
import { calculateAge, formatDate, formatDateTime } from '../../lib/format'

function StatTile({ value, label }) {
  return (
    <div className="stat-tile">
      <div className="value">{value ?? '—'}</div>
      <div className="label">{label}</div>
    </div>
  )
}

function DurationSummary({ summary, unit = 'dias' }) {
  if (!summary || summary.median === null) return <span style={{ color: 'var(--color-text-muted)' }}>Sem dados suficientes</span>
  return <span>mediana {summary.median} {unit} (P25 {summary.p25} · P75 {summary.p75} · P90 {summary.p90})</span>
}

function Field({ label, children }) {
  if (children === null || children === undefined || children === '') return null
  return (
    <div style={{ fontSize: '0.88rem' }}>
      <span style={{ color: 'var(--color-text-muted)' }}>{label}: </span>{children}
    </div>
  )
}

function EpisodeCard({ episode, index, total }) {
  const [open, setOpen] = useState(index === 0)

  return (
    <div
      className="card"
      style={{
        borderLeft: `4px solid ${episode.deleted_at ? 'var(--color-danger)' : episode.status === 'ACTIVE' ? 'var(--color-success)' : 'var(--color-border)'}`,
      }}
    >
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        style={{
          display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8,
          width: '100%', background: 'none', border: 'none', padding: 0, textAlign: 'left',
          cursor: 'pointer', font: 'inherit', color: 'inherit',
        }}
      >
        <div>
          <div style={{ fontWeight: 700 }}>
            Atendimento {total - index}
            {episode.attendance_number ? ` · nº ${episode.attendance_number}` : ' · sem número'}
          </div>
          <div style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>
            Entrada {formatDateTime(episode.admission_at)}
            {episode.neurology_followup_closed_at ? ` · encerrado ${formatDateTime(episode.neurology_followup_closed_at)}` : ''}
          </div>
        </div>
        <span style={{ fontSize: '1.1rem', color: 'var(--color-text-muted)' }}>{open ? '▾' : '▸'}</span>
      </button>

      <div className="badge-row" style={{ marginTop: 8 }}>
        <span className={`badge ${episode.status === 'ACTIVE' ? 'badge-success' : 'badge-neutral'}`}>
          {episode.status === 'ACTIVE' ? 'Ativo' : 'Encerrado'}
        </span>
        <span className="badge badge-neutral">
          {episode.care_type === 'INTERCONSULT' ? 'Interconsulta' : 'Institucional'}
        </span>
        {episode.followup_mode === 'SINGLE_EVALUATION' && <span className="badge badge-warning">Avaliação única</span>}
        <span className="badge badge-info">
          {episode.payer_type === 'PRIVATE' ? 'Particular' : (episode.health_plan || 'Plano de saúde')}
        </span>
        {episode.deleted_at && <span className="badge badge-danger">Excluído</span>}
      </div>

      {open && (
        <div style={{ marginTop: 10, display: 'grid', gap: 4 }}>
          <Field label="Entrada">{formatDateTime(episode.admission_at)}</Field>
          <Field label="Alta hospitalar">{formatDateTime(episode.hospital_discharge_at)}</Field>
          <Field label="Encerramento Neurologia">{formatDateTime(episode.neurology_followup_closed_at)}</Field>
          <Field label="Dias de acompanhamento">{episode.neurology_days}</Field>
          <Field label="Dias de internação hospitalar">{episode.hospital_days}</Field>
          <Field label="Procedência">{episode.origin}</Field>
          <Field label="Enfermaria / leito">
            {[episode.unit, episode.bed].filter(Boolean).join(' · ') || null}
          </Field>

          {episode.care_type === 'INTERCONSULT' && (
            <>
              <Field label="Especialidade solicitante">{episode.requesting_specialty}</Field>
              <Field label="Solicitada em">{formatDateTime(episode.consult_requested_at)}</Field>
              <Field label="1ª avaliação">{formatDateTime(episode.first_neurology_evaluation_at)}</Field>
            </>
          )}

          <div style={{ marginTop: 6, fontSize: '0.88rem' }}>
            <span style={{ color: 'var(--color-text-muted)' }}>Hipóteses: </span>
            {episode.diagnoses.suspected.length === 0
              ? '—'
              : episode.diagnoses.suspected.map((d) => `${d.cid_code} — ${d.description}`).join(' | ')}
          </div>
          <div style={{ fontSize: '0.88rem' }}>
            <span style={{ color: 'var(--color-text-muted)' }}>Diagnóstico final: </span>
            {episode.diagnoses.final.length === 0
              ? '—'
              : episode.diagnoses.final.map((d) => `${d.cid_code} — ${d.description}`).join(' | ')}
          </div>

          <Field label="Visitas">
            {`${episode.rounds.completed}/${episode.rounds.total}`}
            {episode.rounds.coverage_pct !== null ? ` (${episode.rounds.coverage_pct}% de cobertura)` : ''}
          </Field>
          <Field label="Equipe">{episode.rounds.physicians.join(', ') || null}</Field>

          <Field label="Pendências">
            {`${episode.pending_items.open} abertas de ${episode.pending_items.total}`}
          </Field>
          {episode.pending_items.items.length > 0 && (
            <ul style={{ margin: '2px 0 0', paddingLeft: 18, fontSize: '0.85rem' }}>
              {episode.pending_items.items.map((item, i) => (
                <li key={i} style={{ color: item.status === 'OPEN' ? 'inherit' : 'var(--color-text-muted)' }}>
                  {item.description} — {item.status === 'OPEN' ? 'aberta' : 'concluída'}
                </li>
              ))}
            </ul>
          )}

          {episode.brief_history && (
            <div style={{ marginTop: 6, fontSize: '0.88rem' }}>
              <div style={{ color: 'var(--color-text-muted)' }}>História breve:</div>
              <div style={{ whiteSpace: 'pre-wrap' }}>{episode.brief_history}</div>
            </div>
          )}
          {episode.discharge_outcome && (
            <div style={{ marginTop: 6, fontSize: '0.88rem' }}>
              <div style={{ color: 'var(--color-text-muted)' }}>Desfecho:</div>
              <div style={{ whiteSpace: 'pre-wrap' }}>{episode.discharge_outcome}</div>
            </div>
          )}
          {episode.followup_plan_documented && (
            <div style={{ marginTop: 6, fontSize: '0.88rem' }}>
              <div style={{ color: 'var(--color-text-muted)' }}>Plano de seguimento:</div>
              <div style={{ whiteSpace: 'pre-wrap' }}>{episode.followup_plan_documented}</div>
            </div>
          )}

          {episode.deleted_at && (
            <div className="alert alert-danger" style={{ marginTop: 8 }}>
              Excluído em {formatDateTime(episode.deleted_at)}
              {episode.deleted_by ? ` por ${episode.deleted_by}` : ''}
              {episode.deletion_reason ? ` — ${episode.deletion_reason}` : ''}
            </div>
          )}

          <div style={{ marginTop: 8, fontSize: '0.8rem', color: 'var(--color-text-muted)' }}>
            Criado por {episode.created_by || '—'}
            {episode.updated_by ? ` · última alteração por ${episode.updated_by}` : ''}
          </div>

          <Link to={`/atendimentos/${episode.id}`} style={{ marginTop: 6, fontSize: '0.88rem' }}>
            Abrir atendimento →
          </Link>
        </div>
      )}
    </div>
  )
}

export default function PatientDashboardAdminPage() {
  const [search, setSearch] = useState('')
  const [results, setResults] = useState(null)
  const [searching, setSearching] = useState(false)
  const [selected, setSelected] = useState(null)
  const [loadingDetail, setLoadingDetail] = useState(false)
  const [includeDeleted, setIncludeDeleted] = useState(false)
  const [error, setError] = useState('')

  async function handleSearch(e) {
    e?.preventDefault()
    setError('')
    setSearching(true)
    setSelected(null)
    try {
      const { data } = await api.get('/admin/dashboard/patients', { params: { search } })
      setResults(data.patients)
      if (data.patients.length === 1) {
        await openPatient(data.patients[0].id, includeDeleted)
      }
    } catch {
      setError('Não foi possível buscar. Tente novamente.')
    } finally {
      setSearching(false)
    }
  }

  async function openPatient(patientId, withDeleted = includeDeleted) {
    setError('')
    setLoadingDetail(true)
    try {
      const { data } = await api.get(`/admin/dashboard/patients/${patientId}`, {
        params: { include_deleted: withDeleted ? 1 : undefined },
      })
      setSelected(data)
    } catch {
      setError('Não foi possível carregar este prontuário.')
    } finally {
      setLoadingDetail(false)
    }
  }

  function handleIncludeDeleted(checked) {
    setIncludeDeleted(checked)
    if (selected) openPatient(selected.patient.id, checked)
  }

  const summary = selected?.summary

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Dashboard por prontuário</h2>

      <form className="card" onSubmit={handleSearch}>
        <div className="form-group" style={{ marginBottom: 8 }}>
          <label>Prontuário, nome ou número de atendimento</label>
          <input
            className="input"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Ex.: 123456, Maria, ATD-5521"
            autoFocus
          />
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem', marginBottom: 8 }}>
          <input type="checkbox" checked={includeDeleted} onChange={(e) => handleIncludeDeleted(e.target.checked)} />
          Incluir atendimentos excluídos (auditoria)
        </label>
        <button type="submit" className="btn btn-primary" disabled={searching}>
          {searching ? 'Buscando…' : 'Buscar'}
        </button>
      </form>

      {error && <div className="alert alert-danger">{error}</div>}

      {results && !selected && (
        results.length === 0 ? (
          <div className="empty-state">Nenhum paciente encontrado.</div>
        ) : (
          <div className="card">
            <div className="section-title" style={{ marginTop: 0 }}>
              {results.length} paciente{results.length === 1 ? '' : 's'}
            </div>
            <table className="admin-table">
              <thead>
                <tr><th>Prontuário</th><th>Paciente</th><th>Atendimentos</th><th></th></tr>
              </thead>
              <tbody>
                {results.map((p) => (
                  <tr key={p.id}>
                    <td>{p.medical_record_number || '— pendente'}</td>
                    <td>{p.full_name}</td>
                    <td>{p.episodes_total}{p.episodes_active > 0 ? ` (${p.episodes_active} ativo)` : ''}</td>
                    <td>
                      <button type="button" className="btn btn-outline" style={{ minHeight: 32, padding: '4px 10px' }}
                        onClick={() => openPatient(p.id)}>
                        Abrir
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )
      )}

      {loadingDetail && <div className="empty-state">Carregando prontuário…</div>}

      {selected && !loadingDetail && (
        <div>
          <div className="card">
            <div style={{ fontSize: '1.05rem', fontWeight: 800 }}>{selected.patient.full_name}</div>
            <div style={{ color: 'var(--color-text-muted)', fontSize: '0.88rem' }}>
              {selected.patient.medical_record_number
                ? `Prontuário ${selected.patient.medical_record_number}`
                : '⚠ Prontuário pendente'}
              {' · '}Nascimento {formatDate(selected.patient.date_of_birth)}
              {calculateAge(selected.patient.date_of_birth) !== null ? ` (${calculateAge(selected.patient.date_of_birth)} anos)` : ''}
            </div>
            {results && results.length > 1 && (
              <button type="button" className="btn btn-outline" style={{ marginTop: 8 }} onClick={() => setSelected(null)}>
                ← Voltar à lista
              </button>
            )}
          </div>

          <div className="stat-grid">
            <StatTile value={summary.episodes_total} label="Atendimentos" />
            <StatTile value={summary.episodes_active} label="Ativos" />
            <StatTile value={summary.episodes_closed} label="Encerrados" />
            {includeDeleted && <StatTile value={summary.episodes_deleted} label="Excluídos" />}
            <StatTile value={summary.interconsults} label="Interconsultas" />
            <StatTile value={summary.single_evaluations} label="Avaliações únicas" />
            <StatTile value={summary.neurology_days_total} label="Dias de acompanhamento" />
            <StatTile value={summary.pending_open} label="Pendências abertas" />
          </div>

          <div className="card">
            <div className="section-title" style={{ marginTop: 0 }}>Consolidado</div>
            <div>
              Primeiro atendimento: {formatDateTime(summary.first_admission_at) || '—'} ·
              último: {formatDateTime(summary.last_admission_at) || '—'}
            </div>
            <div>Acompanhamento Neurologia: <DurationSummary summary={summary.neurology_days} /></div>
            <div>
              Internação hospitalar: <DurationSummary summary={summary.hospital_days} />
              {' '}({summary.hospital_days_sample_size} com alta registrada)
            </div>
            <div>
              Cobertura de visita: {summary.rounds_coverage_pct !== null ? `${summary.rounds_coverage_pct}%` : '—'}
              {' '}({summary.rounds_completed}/{summary.rounds_total} visitas)
            </div>
            <div>
              Forma de pagamento: Particular {summary.payers.PRIVATE} · Plano {summary.payers.HEALTH_PLAN}
              {summary.plans.length > 0 && ` (${summary.plans.map((p) => `${p.plan}: ${p.episodes}`).join(', ')})`}
            </div>
          </div>

          {summary.physicians.length > 0 && (
            <div className="card">
              <div className="section-title" style={{ marginTop: 0 }}>Visitas por médico</div>
              <table className="admin-table">
                <thead><tr><th>Médico</th><th>Visitas</th></tr></thead>
                <tbody>
                  {summary.physicians.map((p) => (
                    <tr key={p.physician}><td>{p.physician}</td><td>{p.rounds}</td></tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {summary.readmission_gaps_days.length > 0 && (
            <div className="card">
              <div className="section-title" style={{ marginTop: 0 }}>Intervalos entre atendimentos</div>
              {summary.readmission_gaps_days.map((gap, i) => (
                <div key={i} style={{ fontSize: '0.9rem' }}>
                  {gap.gap_days} dias entre o encerramento do atendimento #{gap.from_episode_id} e a entrada do #{gap.to_episode_id}
                  {gap.gap_days <= 30 && <span className="badge badge-warning" style={{ marginLeft: 6 }}>reinternação ≤ 30 dias</span>}
                </div>
              ))}
            </div>
          )}

          <div className="section-title">Atendimentos ({selected.episodes.length})</div>
          {selected.episodes.length === 0 ? (
            <div className="empty-state">Nenhum atendimento registrado para este prontuário.</div>
          ) : (
            selected.episodes.map((episode, index) => (
              <EpisodeCard key={episode.id} episode={episode} index={index} total={selected.episodes.length} />
            ))
          )}
        </div>
      )}
    </div>
  )
}

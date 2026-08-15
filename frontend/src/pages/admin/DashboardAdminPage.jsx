import { useEffect, useState } from 'react'
import api from '../../lib/api'

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

export default function DashboardAdminPage() {
  const [filters, setFilters] = useState({ date_from: '', date_to: '' })
  const [data, setData] = useState(null)
  const [quality, setQuality] = useState(null)
  const [includeDeleted, setIncludeDeleted] = useState(false)
  const [loading, setLoading] = useState(true)

  async function load() {
    setLoading(true)
    try {
      const params = { ...filters, include_deleted: includeDeleted ? 1 : undefined }
      const [{ data: dashboard }, { data: dq }] = await Promise.all([
        api.get('/admin/dashboard', { params }),
        api.get('/admin/dashboard/data-quality'),
      ])
      setData(dashboard)
      setQuality(dq)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function handleFilterSubmit(e) {
    e.preventDefault()
    load()
  }

  if (loading && !data) return <div className="empty-state">Carregando indicadores…</div>

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Dashboard — Indicadores</h2>

      <form className="card" onSubmit={handleFilterSubmit}>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <div className="form-group" style={{ flex: 1, minWidth: 140 }}>
            <label>De</label>
            <input type="date" className="input" value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })} />
          </div>
          <div className="form-group" style={{ flex: 1, minWidth: 140 }}>
            <label>Até</label>
            <input type="date" className="input" value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })} />
          </div>
        </div>
        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.85rem' }}>
          <input type="checkbox" checked={includeDeleted} onChange={(e) => setIncludeDeleted(e.target.checked)} />
          Incluir excluídos (auditoria)
        </label>
        <button type="submit" className="btn btn-primary" style={{ marginTop: 8 }}>Aplicar filtros</button>
      </form>

      <div className="section-title">Volume</div>
      <div className="stat-grid">
        <StatTile value={data.volume.episodes} label="Episódios" />
        <StatTile value={data.volume.unique_patients} label="Pacientes únicos" />
        <StatTile value={data.volume.currently_active} label="Ativos atualmente" />
        <StatTile value={data.volume.discharges} label="Altas/encerramentos" />
        <StatTile value={data.volume.new_interconsults} label="Interconsultas" />
        <StatTile value={data.volume.single_evaluations} label="Avaliações únicas" />
        <StatTile value={data.volume.neurology_patient_days} label="Patient-days (Neurologia)" />
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Particular × Plano</div>
        <div>Particular: {data.payers.private_vs_plan.PRIVATE} · Plano de saúde: {data.payers.private_vs_plan.HEALTH_PLAN}</div>
        {data.payers.by_plan.length > 0 && (
          <table className="admin-table" style={{ marginTop: 8 }}>
            <thead><tr><th>Plano</th><th>Episódios</th><th>Patient-days</th><th>Mediana acompanhamento</th></tr></thead>
            <tbody>
              {data.payers.by_plan.map((p) => (
                <tr key={p.plan}><td>{p.plan}</td><td>{p.episodes}</td><td>{p.patient_days}</td><td>{p.median_followup_days ?? '—'} dias</td></tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Interconsultas</div>
        <div>Total: {data.interconsults.count}</div>
        <div>Tempo solicitação → 1ª avaliação: <DurationSummary summary={data.interconsults.response_time_days} /></div>
        {data.interconsults.by_specialty.map((s) => (
          <div key={s.specialty} style={{ fontSize: '0.9rem' }}>{s.specialty}: {s.count}</div>
        ))}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Tempo de internação</div>
        <div>Internação hospitalar: <DurationSummary summary={data.length_of_stay.hospital_los_days} /> ({data.length_of_stay.hospital_los_sample_size} episódios com alta registrada)</div>
        <div>Acompanhamento Neurologia: <DurationSummary summary={data.length_of_stay.neurology_followup_days} /></div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Cobertura de visita diária</div>
        <div>{data.visit_coverage.coverage_pct !== null ? `${data.visit_coverage.coverage_pct}%` : '—'} ({data.visit_coverage.visited_patient_days}/{data.visit_coverage.active_patient_days} patient-days ativos visitados)</div>
        <div>Sem responsável hoje: {data.visit_coverage.unassigned_today} · Não visitados hoje: {data.visit_coverage.not_visited_today}</div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Diagnósticos</div>
        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 160 }}>
            <strong>Top hipóteses</strong>
            {data.diagnoses.top_suspected.map((d) => <div key={d.cid_code} style={{ fontSize: '0.9rem' }}>{d.cid_code} — {d.count}</div>)}
          </div>
          <div style={{ flex: 1, minWidth: 160 }}>
            <strong>Top finais</strong>
            {data.diagnoses.top_final.map((d) => <div key={d.cid_code} style={{ fontSize: '0.9rem' }}>{d.cid_code} — {d.count}</div>)}
          </div>
        </div>
        <div style={{ marginTop: 8 }}>
          Concordância: {data.diagnostic_agreement.concordant} concordantes · {data.diagnostic_agreement.changed} com mudança · {data.diagnostic_agreement.undetermined} indeterminados
        </div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Reinternações</div>
        <div>Até 7 dias: {data.readmissions.within_7_days} · Até 30 dias: {data.readmissions.within_30_days}</div>
        <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)' }}>{data.readmissions.note}</div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Pendências</div>
        <div>Abertas: {data.pending_items.open} · Criadas: {data.pending_items.created} · Resolvidas: {data.pending_items.resolved}</div>
        <div>Tempo mediano de resolução: {data.pending_items.median_resolution_days ?? '—'} dias</div>
        <div>Abertas no encerramento: {data.pending_items.open_at_closure}</div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Avaliações únicas</div>
        <div>Total: {data.single_evaluations.count} · % concluídas no mesmo dia: {data.single_evaluations.same_day_pct ?? '—'}%</div>
        <div>Tempo de resposta: <DurationSummary summary={data.single_evaluations.response_time_days} /></div>
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Médicos — cobertura operacional</div>
        <table className="admin-table">
          <thead><tr><th>Médico</th><th>Visitas</th><th>Pacientes únicos</th><th>1ªs avaliações</th><th>Avaliações únicas</th></tr></thead>
          <tbody>
            {data.physicians.by_physician.map((p) => (
              <tr key={p.physician}>
                <td>{p.physician}</td><td>{p.rounds}</td><td>{p.unique_patients}</td><td>{p.first_evaluations}</td><td>{p.single_evaluations}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {quality && (
        <div className="card">
          <div className="section-title" style={{ marginTop: 0 }}>Qualidade dos dados</div>
          <ul style={{ paddingLeft: 18, margin: 0 }}>
            <li>Ativos sem hipótese diagnóstica: {quality.active_without_suspected_diagnosis}</li>
            <li>Interconsultas sem especialidade: {quality.interconsults_without_specialty}</li>
            <li>Interconsultas sem horário de solicitação: {quality.interconsults_without_request_time}</li>
            <li>Sem responsável hoje: {quality.without_responsible_today}</li>
            <li>Não visitados hoje: {quality.not_visited_today}</li>
            <li>Altas sem diagnóstico final: {quality.discharges_without_final_diagnosis}</li>
            <li>Sem plano/particular definido: {quality.without_payer_defined}</li>
            <li>Internações há mais de 30 dias: {quality.admissions_over_30_days}</li>
            <li>Avaliações únicas abertas há mais de 3 dias: {quality.single_evaluations_open_over_3_days}</li>
            <li>Pendências abertas há mais de 14 dias: {quality.pending_items_open_over_14_days}</li>
          </ul>
        </div>
      )}
    </div>
  )
}

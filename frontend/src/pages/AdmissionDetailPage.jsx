import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import api from '../lib/api'
import Autocomplete from '../components/Autocomplete'
import { formatDate, formatDateTime, todayISODate, isSameLocalDate } from '../lib/format'

const DELETE_REASONS = [
  ['DUPLICATE', 'Cadastro duplicado'],
  ['NOT_NEUROLOGY', 'Paciente não pertence à Neurologia'],
  ['CREATED_BY_MISTAKE', 'Criado por engano'],
  ['OTHER', 'Outro'],
]

export default function AdmissionDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [admission, setAdmission] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [physicians, setPhysicians] = useState([])

  const [newPending, setNewPending] = useState('')
  const [showClose, setShowClose] = useState(false)
  const [closeForm, setCloseForm] = useState({ final_cid_code: '', discharge_outcome: '', followup_plan_documented: '' })
  const [finalCidLabel, setFinalCidLabel] = useState('')
  const [showDelete, setShowDelete] = useState(false)
  const [deleteReason, setDeleteReason] = useState('DUPLICATE')
  const [deleteDetail, setDeleteDetail] = useState('')

  const load = useCallback(async () => {
    try {
      const { data } = await api.get(`/admissions/${id}`)
      setAdmission(data)
    } catch {
      setError('Não foi possível carregar este atendimento.')
    }
  }, [id])

  useEffect(() => { load() }, [load])
  useEffect(() => { api.get('/physicians').then(({ data }) => setPhysicians(data)) }, [])

  if (error) return <div className="alert alert-danger">{error}</div>
  if (!admission) return <div className="empty-state">Carregando…</div>

  const today = todayISODate()
  const todaysRound = (admission.daily_rounds || []).find((r) => isSameLocalDate(r.round_date, today))
  const suspected = (admission.diagnoses || []).filter((d) => d.phase === 'SUSPECTED')
  const final = (admission.diagnoses || []).filter((d) => d.phase === 'FINAL')
  const isSingleEval = admission.followup_mode === 'SINGLE_EVALUATION'

  async function withBusy(fn) {
    setBusy(true)
    try {
      await fn()
      await load()
    } catch (err) {
      const msg = err.response?.data?.message || Object.values(err.response?.data?.errors || {}).flat()[0] || 'Ocorreu um erro.'
      alert(msg)
    } finally {
      setBusy(false)
    }
  }

  async function handleAssign(physicianId) {
    if (!physicianId) return
    await withBusy(async () => {
      await api.post(`/admissions/${id}/rounds/assign`, { assigned_physician_id: physicianId })
    })
  }

  async function handleAddPending(e) {
    e.preventDefault()
    if (!newPending.trim()) return
    await withBusy(async () => {
      await api.post(`/admissions/${id}/pending-items`, { description: newPending })
      setNewPending('')
    })
  }

  async function handleResolvePending(pendingId, status) {
    await withBusy(async () => {
      await api.post(`/pending-items/${pendingId}/resolve`, { status })
    })
  }

  async function handleCompleteRound() {
    await withBusy(async () => {
      await api.post(`/admissions/${id}/rounds/complete`)
    })
  }

  async function handleClose(e) {
    e.preventDefault()
    if (!closeForm.final_cid_code || !closeForm.discharge_outcome) {
      alert('Diagnóstico final e desfecho são obrigatórios.')
      return
    }
    await withBusy(async () => {
      await api.post(`/admissions/${id}/close`, { version: admission.version, ...closeForm })
      setShowClose(false)
    })
  }

  async function handleConvertToFollowup() {
    if (!confirm('Converter esta avaliação única em acompanhamento contínuo?')) return
    await withBusy(async () => {
      await api.post(`/admissions/${id}/convert-to-followup`)
    })
  }

  async function handleDelete(e) {
    e.preventDefault()
    await withBusy(async () => {
      await api.delete(`/admissions/${id}`, { data: { reason: deleteReason, reason_detail: deleteDetail || undefined } })
    })
    navigate('/')
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>{admission.patient?.full_name}</h2>
      <p style={{ color: 'var(--color-text-muted)', fontSize: '0.85rem', marginTop: -8 }}>
        Prontuário {admission.patient?.medical_record_number} · Nascimento {formatDate(admission.patient?.date_of_birth)}
      </p>

      <div className="badge-row">
        <span className={`badge ${admission.status === 'ACTIVE' ? 'badge-success' : 'badge-neutral'}`}>
          {admission.status === 'ACTIVE' ? 'Ativo' : 'Encerrado'}
        </span>
        {admission.care_type === 'INTERCONSULT' && <span className="badge badge-info">Interconsulta</span>}
        {isSingleEval && <span className="badge badge-warning">Avaliação única</span>}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Internação</div>
        <div>Entrada: {formatDateTime(admission.admission_at)}</div>
        {admission.hospital_discharge_at && <div>Alta hospitalar: {formatDateTime(admission.hospital_discharge_at)}</div>}
        {admission.neurology_followup_closed_at && <div>Encerramento Neurologia: {formatDateTime(admission.neurology_followup_closed_at)}</div>}
        {(admission.unit || admission.bed) && <div>{admission.unit ? `Enfermaria ${admission.unit}` : ''} {admission.bed ? `· Leito ${admission.bed}` : ''}</div>}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Forma de pagamento</div>
        <div>{admission.payer_type === 'PRIVATE' ? 'Particular' : (admission.health_plan_name_snapshot || admission.health_plan?.name || 'Plano de saúde')}</div>
        {admission.care_type === 'INTERCONSULT' && (
          <>
            <div className="section-title">Interconsulta</div>
            <div>{admission.requesting_specialty?.name}</div>
            {admission.consult_requested_at && <div>Solicitado em {formatDateTime(admission.consult_requested_at)}</div>}
          </>
        )}
      </div>

      {admission.brief_history && (
        <div className="card">
          <div className="section-title" style={{ marginTop: 0 }}>História breve</div>
          <div style={{ whiteSpace: 'pre-wrap' }}>{admission.brief_history}</div>
        </div>
      )}

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Diagnósticos</div>
        {suspected.map((d) => (
          <div key={d.id}>Hipótese{d.is_primary ? ' (principal)' : ''}: {d.cid_code} — {d.description_snapshot}</div>
        ))}
        {final.map((d) => (
          <div key={d.id}>Final{d.is_primary ? ' (principal)' : ''}: {d.cid_code} — {d.description_snapshot}</div>
        ))}
      </div>

      {admission.status === 'ACTIVE' && (
        <div className="card">
          <div className="section-title" style={{ marginTop: 0 }}>Visita de hoje</div>
          {todaysRound?.assigned_physician_id ? (
            <div>Responsável: {todaysRound.assigned_physician?.full_name}</div>
          ) : (
            <div className="alert alert-warning">Responsável hoje: não definido</div>
          )}
          <select
            className="input"
            style={{ marginTop: 8 }}
            value={todaysRound?.assigned_physician_id || ''}
            disabled={busy}
            onChange={(e) => handleAssign(Number(e.target.value))}
          >
            <option value="" disabled>Atribuir responsável…</option>
            {physicians.map((p) => (
              <option key={p.id} value={p.id}>{p.full_name}</option>
            ))}
          </select>
          {todaysRound?.completed_at ? (
            <div className="badge badge-success">Visita realizada em {formatDateTime(todaysRound.completed_at)}</div>
          ) : (
            <button className="btn btn-primary" style={{ marginTop: 8 }} disabled={busy} onClick={handleCompleteRound}>
              ✓ Visita realizada
            </button>
          )}
        </div>
      )}

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Pendências</div>
        {(admission.pending_items || []).map((p) => (
          <div key={p.id} className="pending-item">
            <span style={{ textDecoration: p.status === 'OPEN' ? 'none' : 'line-through', color: p.status === 'OPEN' ? 'inherit' : 'var(--color-text-muted)' }}>
              {p.description}
            </span>
            {p.status === 'OPEN' && admission.status === 'ACTIVE' && (
              <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 10px' }} disabled={busy}
                onClick={() => handleResolvePending(p.id, 'DONE')}>Concluir</button>
            )}
          </div>
        ))}
        {admission.status === 'ACTIVE' && (
          <form onSubmit={handleAddPending} style={{ display: 'flex', gap: 6, marginTop: 8 }}>
            <input className="input" placeholder="Nova pendência…" value={newPending} onChange={(e) => setNewPending(e.target.value)} />
            <button type="submit" className="btn btn-primary" disabled={busy}>+</button>
          </form>
        )}
      </div>

      {admission.status === 'ACTIVE' && (
        <div className="card">
          <div className="section-title" style={{ marginTop: 0 }}>Ações</div>

          {isSingleEval && (
            <button className="btn btn-outline btn-block" style={{ marginBottom: 8 }} disabled={busy} onClick={handleConvertToFollowup}>
              Converter para acompanhamento
            </button>
          )}

          {!showClose ? (
            <button className="btn btn-primary btn-block" style={{ marginBottom: 8 }} onClick={() => setShowClose(true)}>
              {isSingleEval ? 'Concluir avaliação única' : 'Encerrar acompanhamento'}
            </button>
          ) : (
            <form onSubmit={handleClose} className="card" style={{ background: 'var(--color-bg)' }}>
              <div className="form-group">
                <label>Diagnóstico final (CID-10)</label>
                <Autocomplete
                  searchUrl="/cid10/search"
                  valueKey="code"
                  labelKey="description"
                  initialLabel={finalCidLabel}
                  renderOption={(opt) => `${opt.code} — ${opt.description}`}
                  onSelect={(opt) => {
                    setCloseForm({ ...closeForm, final_cid_code: opt?.code || '' })
                    setFinalCidLabel(opt ? `${opt.code} — ${opt.description}` : '')
                  }}
                />
              </div>
              <div className="form-group">
                <label>Desfecho</label>
                <textarea className="input" value={closeForm.discharge_outcome}
                  onChange={(e) => setCloseForm({ ...closeForm, discharge_outcome: e.target.value })} required />
              </div>
              <div className="form-group">
                <label>Plano de seguimento (opcional)</label>
                <textarea className="input" value={closeForm.followup_plan_documented}
                  onChange={(e) => setCloseForm({ ...closeForm, followup_plan_documented: e.target.value })} />
              </div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="submit" className="btn btn-primary" disabled={busy}>Confirmar encerramento</button>
                <button type="button" className="btn btn-outline" onClick={() => setShowClose(false)}>Cancelar</button>
              </div>
            </form>
          )}

          {!showDelete ? (
            <button className="btn btn-danger btn-block" onClick={() => setShowDelete(true)}>Excluir</button>
          ) : (
            <form onSubmit={handleDelete} className="card" style={{ background: 'var(--color-bg)' }}>
              <div className="alert alert-warning">
                O registro será removido das visualizações assistenciais e mantido no arquivo administrativo para segurança e auditoria.
              </div>
              <div className="form-group">
                <label>Motivo</label>
                <select className="input" value={deleteReason} onChange={(e) => setDeleteReason(e.target.value)}>
                  {DELETE_REASONS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                </select>
              </div>
              {deleteReason === 'OTHER' && (
                <div className="form-group">
                  <label>Detalhe</label>
                  <input className="input" value={deleteDetail} onChange={(e) => setDeleteDetail(e.target.value)} required />
                </div>
              )}
              <div style={{ display: 'flex', gap: 8 }}>
                <button type="submit" className="btn btn-danger" disabled={busy}>Confirmar exclusão</button>
                <button type="button" className="btn btn-outline" onClick={() => setShowDelete(false)}>Cancelar</button>
              </div>
            </form>
          )}
        </div>
      )}

      <Link to="/" style={{ display: 'inline-block', marginTop: 8 }}>← Voltar</Link>
    </div>
  )
}

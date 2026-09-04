import { useEffect, useState, useCallback } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import api from '../lib/api'
import Autocomplete from '../components/Autocomplete'
import AdmissionEditForm from '../components/AdmissionEditForm'
import PatientEditForm from '../components/PatientEditForm'
import { useAuth } from '../context/AuthContext'
import { formatDate, formatDateTime, todayISODate, isSameLocalDate } from '../lib/format'
import { canWrite } from '../lib/roles'
import { originLabel } from '../lib/vocabulary'

const DELETE_REASONS = [
  ['DUPLICATE', 'Cadastro duplicado'],
  ['NOT_NEUROLOGY', 'Paciente não pertence à Neurologia'],
  ['CREATED_BY_MISTAKE', 'Criado por engano'],
  ['OTHER', 'Outro'],
]

/**
 * Estado zerado do encerramento. Reaplicado toda vez que o formulário
 * abre: a conferência do convênio vale para AQUELA tentativa de encerrar
 * — quem cancelou para ir corrigir o pagador precisa conferir de novo ao
 * voltar, e não encontrar a caixa ainda marcada da tentativa anterior.
 */
const CLOSE_FORM_INICIAL = {
  final_cid_code: '',
  discharge_outcome: '',
  followup_plan_documented: '',
  hospital_discharge_at: '',
  health_plan_confirmed: false,
}

export default function AdmissionDetailPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [admission, setAdmission] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [physicians, setPhysicians] = useState([])

  const [newPending, setNewPending] = useState('')
  const [showClose, setShowClose] = useState(false)
  const [closeForm, setCloseForm] = useState(CLOSE_FORM_INICIAL)
  const [finalCidLabel, setFinalCidLabel] = useState('')
  const [patientFixed, setPatientFixed] = useState(false)
  const [showDelete, setShowDelete] = useState(false)
  const [deleteReason, setDeleteReason] = useState('DUPLICATE')
  const [deleteDetail, setDeleteDetail] = useState('')
  const [editing, setEditing] = useState(null)
  const [informingMrn, setInformingMrn] = useState(false)
  // Transferência de leito/enfermaria é a alteração mais frequente do
  // episódio (UTI → enfermaria, troca de leito) — fica como atalho no
  // próprio cartão de Internação, sem abrir o formulário completo.
  const [movingBed, setMovingBed] = useState(false)
  const [bedForm, setBedForm] = useState({ unit: '', bed: '' })

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
  // Gestor observador vê a ficha inteira e não age sobre ela. O servidor já
  // recusa qualquer escrita dele; aqui os controles somem para a tela não
  // prometer o que a API vai negar.
  const readOnly = !canWrite(user)

  // Espelha AdmissionController::missingForClosure. O servidor continua
  // sendo a autoridade — isto existe para avisar ANTES de o médico
  // preencher o formulário inteiro e tomar um erro no final.
  const closureBlockers = [
    !admission.patient?.medical_record_number && 'Número de prontuário',
    !admission.patient?.date_of_birth && 'Data de nascimento',
  ].filter(Boolean)

  // Mesmo rótulo que o cartão "Forma de pagamento" mostra, sem o
  // fallback genérico: na conferência do encerramento, "Plano de saúde"
  // no lugar do nome do convênio seria pedir para confirmar o nada.
  const payerLabel = admission.payer_type === 'PRIVATE'
    ? 'Particular'
    : (admission.health_plan_name_snapshot || admission.health_plan?.name || null)

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
      const res = await api.post(`/admissions/${id}/rounds/assign`, { assigned_physician_id: physicianId })
      if (res.data) {
        setAdmission((prev) => {
          if (!prev) return prev
          const rounds = [...(prev.daily_rounds || [])]
          const idx = rounds.findIndex((r) => isSameLocalDate(r.round_date, today))
          if (idx >= 0) {
            rounds[idx] = res.data
          } else {
            rounds.unshift(res.data)
          }
          return { ...prev, daily_rounds: rounds }
        })
      }
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
      const res = await api.post(`/admissions/${id}/rounds/complete`)
      if (res.data) {
        setAdmission((prev) => {
          if (!prev) return prev
          const rounds = [...(prev.daily_rounds || [])]
          const idx = rounds.findIndex((r) => isSameLocalDate(r.round_date, today))
          if (idx >= 0) {
            rounds[idx] = res.data
          } else {
            rounds.unshift(res.data)
          }
          return { ...prev, daily_rounds: rounds }
        })
      }
    })
  }

  async function handleClose(e) {
    e.preventDefault()
    if (!closeForm.final_cid_code || !closeForm.discharge_outcome) {
      alert('Diagnóstico final e desfecho são obrigatórios.')
      return
    }
    if (!closeForm.health_plan_confirmed) {
      alert('Confirme o convênio do atendimento antes de encerrar.')
      return
    }
    await withBusy(async () => {
      await api.post(`/admissions/${id}/close`, {
        version: admission.version,
        ...closeForm,
        hospital_discharge_at: closeForm.hospital_discharge_at || null,
      })
      setShowClose(false)
    })
  }

  async function handleConvertToFollowup() {
    if (!confirm('Converter esta avaliação única em acompanhamento contínuo?')) return
    await withBusy(async () => {
      await api.post(`/admissions/${id}/convert-to-followup`)
    })
  }

  async function handleMoveBed(e) {
    e.preventDefault()
    await withBusy(async () => {
      const { data } = await api.put(`/admissions/${id}`, {
        version: admission.version,
        unit: bedForm.unit || null,
        bed: bedForm.bed || null,
      })
      setAdmission(data)
      setMovingBed(false)
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
        {admission.patient?.medical_record_number
          ? `Prontuário ${admission.patient.medical_record_number}`
          : '⚠ Prontuário pendente'}
        {admission.attendance_number ? ` · Atendimento ${admission.attendance_number}` : ''}
        {' · '}Nascimento {formatDate(admission.patient?.date_of_birth)}
      </p>

      {!admission.patient?.medical_record_number && (
        <div className="alert alert-warning">
          <strong>Prontuário pendente.</strong> Este paciente foi cadastrado pelo número de
          atendimento.{readOnly ? '' : ' Assim que souber o prontuário, informe aqui — depois de confirmado ele não poderá mais ser alterado.'}

          {/* O campo abre AQUI, e não no bloco "Editar" lá embaixo: aquele
              fica centenas de pixels abaixo da dobra, então o clique parecia
              não fazer nada. */}
          {readOnly ? null : !informingMrn ? (
            <button
              type="button"
              className="btn btn-primary btn-block"
              style={{ marginTop: 8 }}
              onClick={() => setInformingMrn(true)}
            >
              Informar prontuário
            </button>
          ) : (
            <div style={{ marginTop: 10 }}>
              <PatientEditForm
                patient={admission.patient}
                onlyMedicalRecord
                onSaved={(updatedPatient) => {
                  setAdmission((prev) => ({ ...prev, patient: updatedPatient }))
                  setInformingMrn(false)
                }}
              />
              <button type="button" className="btn btn-outline btn-block" style={{ marginTop: 8 }}
                onClick={() => setInformingMrn(false)}>
                Cancelar
              </button>
            </div>
          )}
        </div>
      )}

      <div className="badge-row">
        <span className={`badge ${admission.status === 'ACTIVE' ? 'badge-success' : 'badge-neutral'}`}>
          {admission.status === 'ACTIVE' ? 'Ativo' : 'Encerrado'}
        </span>
        {admission.care_type === 'INTERCONSULT' && <span className="badge badge-info">Interconsulta</span>}
        {isSingleEval && <span className="badge badge-warning">Avaliação única</span>}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Internação</div>
        <div>Nº de atendimento: {admission.attendance_number || '—'}</div>
        <div>Entrada: {formatDateTime(admission.admission_at)}</div>
        <div>Procedência: {originLabel(admission.origin) || 'não informada'}</div>
        {admission.hospital_discharge_at && <div>Alta hospitalar: {formatDateTime(admission.hospital_discharge_at)}</div>}
        {admission.neurology_followup_closed_at && <div>Encerramento Neurologia: {formatDateTime(admission.neurology_followup_closed_at)}</div>}
        <div>
          {admission.unit || admission.bed
            ? `${admission.unit ? `Enfermaria ${admission.unit}` : ''}${admission.unit && admission.bed ? ' · ' : ''}${admission.bed ? `Leito ${admission.bed}` : ''}`
            : 'Enfermaria/leito não informados'}
        </div>

        {readOnly ? null : !movingBed ? (
          <button
            type="button"
            className="btn btn-outline"
            style={{ marginTop: 8, minHeight: 36, padding: '6px 12px' }}
            onClick={() => {
              setBedForm({ unit: admission.unit || '', bed: admission.bed || '' })
              setMovingBed(true)
            }}
          >
            🛏️ Alterar enfermaria/leito
          </button>
        ) : (
          <form onSubmit={handleMoveBed} style={{ marginTop: 8 }}>
            <div className="form-group">
              <label>Enfermaria / setor</label>
              <input className="input" value={bedForm.unit} autoFocus
                onChange={(e) => setBedForm({ ...bedForm, unit: e.target.value })}
                placeholder="Ex.: UTI, Enfermaria 3" />
            </div>
            <div className="form-group">
              <label>Leito</label>
              <input className="input" value={bedForm.bed}
                onChange={(e) => setBedForm({ ...bedForm, bed: e.target.value })} />
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="submit" className="btn btn-primary" disabled={busy}>Salvar</button>
              <button type="button" className="btn btn-outline" onClick={() => setMovingBed(false)}>Cancelar</button>
            </div>
          </form>
        )}
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
        <div className="card" style={{ border: todaysRound?.completed_at ? '1px solid #16a34a' : '1px solid var(--color-border)' }}>
          <div className="section-title" style={{ marginTop: 0, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span>Visita de hoje</span>
            {todaysRound?.completed_at ? (
              <span className="badge badge-success">✓ Realizada</span>
            ) : (
              <span className="badge badge-warning">Pendente</span>
            )}
          </div>

          {todaysRound?.assigned_physician_id ? (
            <div style={{ padding: '8px 12px', background: 'var(--color-bg)', borderRadius: 6, marginBottom: 8, fontSize: '0.92rem' }}>
              <strong>Responsável hoje:</strong>{' '}
              <span style={{ color: 'var(--color-primary)', fontWeight: 'bold' }}>
                {todaysRound.assigned_physician?.full_name || physicians.find((p) => p.id === todaysRound.assigned_physician_id)?.full_name || 'Médico atribuído'}
              </span>
            </div>
          ) : (
            <div className="alert alert-warning" style={{ marginBottom: 8 }}>
              Responsável hoje: <strong>não definido</strong>
            </div>
          )}

          {user && !readOnly && todaysRound?.assigned_physician_id !== user.id && (
            <button
              type="button"
              className="btn btn-outline btn-block"
              style={{ marginBottom: 8, borderColor: 'var(--color-primary)', color: 'var(--color-primary)' }}
              disabled={busy}
              onClick={() => handleAssign(user.id)}
            >
              👤 Assumir visita hoje ({user.full_name})
            </button>
          )}

          {!readOnly && (
          <div style={{ marginBottom: 10 }}>
            <label style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', display: 'block', marginBottom: 4 }}>
              {todaysRound?.assigned_physician_id ? 'Reatribuir para outro médico:' : 'Atribuir a outro médico da equipe:'}
            </label>
            <select
              className="input"
              value={todaysRound?.assigned_physician_id || ''}
              disabled={busy}
              onChange={(e) => {
                const val = Number(e.target.value)
                if (val) handleAssign(val)
              }}
            >
              <option value="" disabled>Selecione um médico na lista…</option>
              {physicians.map((p) => (
                <option key={p.id} value={p.id}>{p.full_name}</option>
              ))}
            </select>
          </div>
          )}

          {todaysRound?.completed_at ? (
            <div style={{ marginTop: 8, padding: '10px 12px', background: '#ecfdf5', borderRadius: 6, border: '1px solid #a7f3d0', color: '#065f46', fontSize: '0.9rem' }}>
              ✓ <strong>Visita realizada</strong> por{' '}
              {todaysRound.completer?.full_name || todaysRound.assigned_physician?.full_name || 'Médico'}{' '}
              em {formatDateTime(todaysRound.completed_at)}
            </div>
          ) : readOnly ? null : (
            <button
              type="button"
              className="btn btn-primary btn-block"
              style={{ marginTop: 8, padding: '10px 16px', fontSize: '0.95rem' }}
              disabled={busy}
              onClick={handleCompleteRound}
            >
              ✓ Assinar Visita Realizada Hoje
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
            {p.status === 'OPEN' && admission.status === 'ACTIVE' && !readOnly && (
              <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 10px' }} disabled={busy}
                onClick={() => handleResolvePending(p.id, 'DONE')}>Concluir</button>
            )}
          </div>
        ))}
        {admission.status === 'ACTIVE' && !readOnly && (
          <form onSubmit={handleAddPending} style={{ display: 'flex', gap: 6, marginTop: 8 }}>
            <input className="input" placeholder="Nova pendência…" value={newPending} onChange={(e) => setNewPending(e.target.value)} />
            <button type="submit" className="btn btn-primary" disabled={busy}>+</button>
          </form>
        )}
      </div>

      {admission.status === 'ACTIVE' && !readOnly && (
        <div className="card">
          <div className="section-title" style={{ marginTop: 0 }}>Ações</div>

          {isSingleEval && (
            <button className="btn btn-outline btn-block" style={{ marginBottom: 8 }} disabled={busy} onClick={handleConvertToFollowup}>
              Converter para acompanhamento
            </button>
          )}

          {!showClose ? (
            <button className="btn btn-primary btn-block" style={{ marginBottom: 8 }}
              onClick={() => { setCloseForm(CLOSE_FORM_INICIAL); setFinalCidLabel(''); setShowClose(true) }}>
              {isSingleEval ? 'Concluir avaliação única' : 'Encerrar acompanhamento'}
            </button>
          ) : (
            <>
              {/* Fica FORA do <form> de encerramento de propósito: o
                  PatientEditForm é ele mesmo um <form>, e aninhar os dois
                  fazia o submit do cadastro borbulhar para handleClose — o
                  médico salvava o nascimento e recebia de volta o erro
                  "diagnóstico final e desfecho são obrigatórios". */}
              {closureBlockers.length > 0 && (
                <div className="alert alert-warning">
                  <strong>Cadastro incompleto.</strong> Estes dados são obrigatórios para encerrar,
                  porque a partir daqui o atendimento vira dado de gestão e ninguém volta para
                  completá-lo:
                  <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                    {closureBlockers.map((item) => <li key={item}>{item}</li>)}
                  </ul>
                  <div style={{ marginTop: 10 }}>
                    <PatientEditForm
                      patient={admission.patient}
                      onSaved={(updatedPatient) => {
                        setAdmission((prev) => ({ ...prev, patient: updatedPatient }))
                        setPatientFixed(true)
                      }}
                    />
                  </div>
                </div>
              )}

              {closureBlockers.length === 0 && patientFixed && (
                <div className="alert alert-success">
                  Cadastro do paciente completo. Já é possível encerrar o atendimento.
                </div>
              )}

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
                  <label>Alta hospitalar (opcional)</label>
                  <input type="datetime-local" className="input" value={closeForm.hospital_discharge_at}
                    onChange={(e) => setCloseForm({ ...closeForm, hospital_discharge_at: e.target.value })} />
                  <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
                    Este é o único momento em que a alta hospitalar é registrada — confira antes de
                    confirmar. Deixe em branco se o paciente segue internado sob outra equipe.
                  </div>
                </div>
                {/* O convênio decide para onde a conta vai (ver "Minhas
                    Visitas" e o selo da AMHS). O encerramento é a última
                    vez que alguém passa pelo episódio, então a conferência
                    é aqui — com o valor à vista, e não como uma pergunta
                    genérica de "está tudo certo?". O servidor recusa o
                    encerramento sem ela; isto é o espelho na tela. */}
                <div className="form-group">
                  <label>Convênio / forma de pagamento</label>
                  <div style={{ fontWeight: 700, fontSize: '1.02rem', margin: '2px 0 2px' }}>
                    {payerLabel || <span style={{ color: 'var(--color-danger)' }}>Não informado</span>}
                  </div>
                  <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginTop: 8, fontSize: '0.88rem', fontWeight: 400 }}>
                    <input
                      type="checkbox"
                      checked={closeForm.health_plan_confirmed}
                      onChange={(e) => setCloseForm({ ...closeForm, health_plan_confirmed: e.target.checked })}
                      style={{ marginTop: 3 }}
                    />
                    <span>Confirmo que o convênio acima está <strong>correto</strong>.</span>
                  </label>
                  <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
                    Se estiver errado, cancele o encerramento e corrija em <strong>✏️ Editar
                    atendimento</strong>, mais abaixo nesta página. Depois de encerrado o episódio
                    vira dado de gestão e o convênio não é mais revisto.
                  </div>
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
                  <button type="submit" className="btn btn-primary"
                    disabled={busy || closureBlockers.length > 0 || !closeForm.health_plan_confirmed}>
                    Confirmar encerramento
                  </button>
                  <button type="button" className="btn btn-outline" onClick={() => setShowClose(false)}>Cancelar</button>
                </div>
              </form>
            </>
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

      {!readOnly && (
      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Editar</div>

        {editing === null && (
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button type="button" className="btn btn-outline" onClick={() => setEditing('admission')}>
              ✏️ Editar atendimento
            </button>
            <button type="button" className="btn btn-outline" onClick={() => setEditing('patient')}>
              👤 Editar paciente
            </button>
          </div>
        )}

        {editing === 'admission' && (
          <AdmissionEditForm
            admission={admission}
            onCancel={() => setEditing(null)}
            onSaved={(updated) => { setAdmission(updated); setEditing(null) }}
          />
        )}

        {editing === 'patient' && (
          <>
            <PatientEditForm
              patient={admission.patient}
              onSaved={(updatedPatient) => setAdmission((prev) => ({ ...prev, patient: updatedPatient }))}
            />
            <button type="button" className="btn btn-outline btn-block" style={{ marginTop: 8 }} onClick={() => setEditing(null)}>
              Fechar
            </button>
          </>
        )}
      </div>
      )}

      <Link to="/" style={{ display: 'inline-block', marginTop: 8 }}>← Voltar</Link>
    </div>
  )
}

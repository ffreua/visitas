import { useState } from 'react'
import api from '../lib/api'
import Autocomplete from './Autocomplete'
import { formatDateTime, toDateTimeInputValue } from '../lib/format'
import { ORIGINS } from '../lib/vocabulary'
import { useAuth } from '../context/AuthContext'

/**
 * Edição do episódio. Tudo que é dado de gestão pode ser corrigido depois
 * (número de atendimento, forma de pagamento, plano, história breve,
 * enfermaria/leito, dados da interconsulta).
 *
 * Os campos travados — entrada da internação, CID inicial e alta hospitalar
 * — aparecem aqui como leitura, para deixar explícito que são
 * intencionalmente fixos e não um esquecimento do formulário. A alta é
 * registrada uma única vez, no encerramento do acompanhamento.
 */
export default function AdmissionEditForm({ admission, onSaved, onCancel }) {
  const { user } = useAuth()
  const isAdmin = user?.role === 'ADMIN'
  const currentPlanLabel = admission.health_plan_name_snapshot || admission.health_plan?.name || ''

  const [form, setForm] = useState({
    attendance_number: admission.attendance_number || '',
    payer_type: admission.payer_type,
    health_plan_id: admission.health_plan_id || null,
    origin: admission.origin || '',
    hospital_discharge_at: toDateTimeInputValue(admission.hospital_discharge_at),
    unit: admission.unit || '',
    bed: admission.bed || '',
    requesting_specialty_id: admission.requesting_specialty_id || null,
    consult_requested_at: toDateTimeInputValue(admission.consult_requested_at),
    consult_reason: admission.consult_reason || '',
    consult_priority: admission.consult_priority || '',
    brief_history: admission.brief_history || '',
  })
  const [planLabel, setPlanLabel] = useState(currentPlanLabel)
  const [specialtyLabel, setSpecialtyLabel] = useState(admission.requesting_specialty?.name || '')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const isInterconsult = admission.care_type === 'INTERCONSULT'
  const hasMedicalRecord = !!admission.patient?.medical_record_number
  const suspected = (admission.diagnoses || []).filter((d) => d.phase === 'SUSPECTED' && d.is_primary)

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')

    if (!hasMedicalRecord && !form.attendance_number.trim()) {
      setError('Número de atendimento é obrigatório enquanto o paciente não tiver prontuário confirmado.')
      return
    }
    if (form.payer_type === 'HEALTH_PLAN' && !form.health_plan_id) {
      setError('Selecione o plano de saúde.')
      return
    }

    setSaving(true)
    try {
      const payload = {
        version: admission.version,
        attendance_number: form.attendance_number.trim() || null,
        payer_type: form.payer_type,
        unit: form.unit || null,
        bed: form.bed || null,
        brief_history: form.brief_history || null,
      }

      // health_plan_id é proibido em atendimento particular — mandar null
      // junto com payer_type=PRIVATE já é recusado pela validação.
      if (form.payer_type === 'HEALTH_PLAN') {
        payload.health_plan_id = form.health_plan_id
      }

      // Só entra no payload se preenchida: mandar "" quebraria a regra de
      // vocabulário fechado, e episódios antigos podem não ter procedência.
      if (form.origin) {
        payload.origin = form.origin
      }

      // Campo definitivo, corrigível apenas por administrador — para os
      // demais a chave nem é enviada (o backend recusa explicitamente).
      if (isAdmin) {
        payload.hospital_discharge_at = form.hospital_discharge_at || null
      }

      if (isInterconsult) {
        payload.requesting_specialty_id = form.requesting_specialty_id
        payload.consult_requested_at = form.consult_requested_at || null
        payload.consult_reason = form.consult_reason || null
        payload.consult_priority = form.consult_priority || null
      }

      const { data } = await api.put(`/admissions/${admission.id}`, payload)
      onSaved?.(data)
    } catch (err) {
      if (err.response?.status === 409) {
        setError(err.response.data.message)
      } else {
        const errors = err.response?.data?.errors
        setError(errors ? Object.values(errors).flat()[0] : (err.response?.data?.message || 'Não foi possível salvar.'))
      }
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {error && <div className="alert alert-danger">{error}</div>}

      <div className="form-group">
        <label>Número de atendimento{hasMedicalRecord ? ' (opcional)' : ''}</label>
        <input
          className="input"
          value={form.attendance_number}
          onChange={(e) => setForm({ ...form, attendance_number: e.target.value })}
          required={!hasMedicalRecord}
        />
      </div>

      <div className="form-group">
        <label>Forma de pagamento</label>
        <div className="radio-row">
          {[['PRIVATE', 'Particular'], ['HEALTH_PLAN', 'Plano de saúde']].map(([value, label]) => (
            <div
              key={value}
              className={`radio-pill ${form.payer_type === value ? 'selected' : ''}`}
              onClick={() => setForm({
                ...form,
                payer_type: value,
                health_plan_id: value === 'PRIVATE' ? null : form.health_plan_id,
              })}
            >
              {label}
            </div>
          ))}
        </div>
      </div>

      {form.payer_type === 'HEALTH_PLAN' && (
        <div className="form-group">
          <label>Plano de saúde</label>
          <Autocomplete
            searchUrl="/health-plans/search"
            initialLabel={planLabel}
            placeholder="Digite para buscar… (ex: bra)"
            onSelect={(opt) => {
              setForm((prev) => ({ ...prev, health_plan_id: opt?.id || null }))
              setPlanLabel(opt?.name || '')
            }}
          />
        </div>
      )}

      {isInterconsult && (
        <>
          <div className="form-group">
            <label>Especialidade solicitante</label>
            <Autocomplete
              searchUrl="/medical-specialties/search"
              initialLabel={specialtyLabel}
              placeholder="Digite para buscar…"
              onSelect={(opt) => {
                setForm((prev) => ({ ...prev, requesting_specialty_id: opt?.id || null }))
                setSpecialtyLabel(opt?.name || '')
              }}
            />
          </div>
          <div className="form-group">
            <label>Horário da solicitação</label>
            <input
              type="datetime-local"
              className="input"
              value={form.consult_requested_at}
              onChange={(e) => setForm({ ...form, consult_requested_at: e.target.value })}
            />
          </div>
          <div className="form-group">
            <label>Motivo da interconsulta</label>
            <textarea className="input" value={form.consult_reason}
              onChange={(e) => setForm({ ...form, consult_reason: e.target.value })} />
          </div>
          <div className="form-group">
            <label>Prioridade</label>
            <input className="input" value={form.consult_priority}
              onChange={(e) => setForm({ ...form, consult_priority: e.target.value })} />
          </div>
        </>
      )}

      <div className="form-group">
        <label>Procedência</label>
        <select className="input" value={form.origin}
          onChange={(e) => setForm({ ...form, origin: e.target.value })}>
          <option value="" disabled>Selecione…</option>
          {ORIGINS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </div>

      {isAdmin && (
        <div className="form-group">
          <label>Alta hospitalar</label>
          <input type="datetime-local" className="input" value={form.hospital_discharge_at}
            onChange={(e) => setForm({ ...form, hospital_discharge_at: e.target.value })} />
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            Correção de administrador. No uso normal a alta é registrada no encerramento do
            acompanhamento.
          </div>
        </div>
      )}

      <div className="form-group">
        <label>Enfermaria</label>
        <input className="input" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} />
      </div>
      <div className="form-group">
        <label>Leito</label>
        <input className="input" value={form.bed} onChange={(e) => setForm({ ...form, bed: e.target.value })} />
      </div>

      <div className="form-group">
        <label>História breve</label>
        <textarea
          className="input"
          rows={4}
          value={form.brief_history}
          onChange={(e) => setForm({ ...form, brief_history: e.target.value })}
        />
      </div>

      <div className="form-group">
        <label>Não editáveis</label>
        <div style={{ background: 'var(--color-bg)', borderRadius: 'var(--radius-sm)', padding: '10px 12px', fontSize: '0.88rem' }}>
          <div>🔒 Entrada (internação): <strong>{formatDateTime(admission.admission_at)}</strong></div>
          {!isAdmin && (
            <div style={{ marginTop: 4 }}>
              🔒 Alta hospitalar:{' '}
              <strong>
                {formatDateTime(admission.hospital_discharge_at)
                  || 'registrada no encerramento do acompanhamento'}
              </strong>
            </div>
          )}
          <div style={{ marginTop: 4 }}>
            🔒 CID inicial:{' '}
            <strong>
              {suspected.length > 0
                ? `${suspected[0].cid_code} — ${suspected[0].description_snapshot}`
                : 'não registrado'}
            </strong>
          </div>
          <div style={{ color: 'var(--color-text-muted)', marginTop: 6, fontSize: '0.8rem' }}>
            Fixos por definição: entrada e CID inicial são o marco temporal e a hipótese de partida
            do episódio. A alta hospitalar e o diagnóstico final são registrados no encerramento do
            acompanhamento{isAdmin ? ' — a alta pode ser corrigida acima, por ser administrador' : ''}.
          </div>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 8 }}>
        <button type="submit" className="btn btn-primary" disabled={saving}>
          {saving ? 'Salvando…' : 'Salvar alterações'}
        </button>
        <button type="button" className="btn btn-outline" onClick={onCancel}>Cancelar</button>
      </div>
    </form>
  )
}

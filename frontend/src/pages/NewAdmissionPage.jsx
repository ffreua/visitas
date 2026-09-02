import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import api from '../lib/api'
import Autocomplete from '../components/Autocomplete'
import { formatDate, nowLocalISOString } from '../lib/format'
import { ORIGINS } from '../lib/vocabulary'

const getEmptyForm = () => ({
  attendance_number: '',
  admission_at: nowLocalISOString(),
  care_type: 'INSTITUTIONAL',
  followup_mode: 'ONGOING',
  payer_type: 'PRIVATE',
  health_plan_id: null,
  requesting_specialty_id: null,
  consult_requested_at: nowLocalISOString(),
  origin: '',
  unit: '',
  bed: '',
  brief_history: '',
  suspected_cid_code: '',
})

// O prontuário identifica a pessoa e nunca muda; o número de atendimento
// identifica a internação e muda a cada passagem. Qualquer um dos dois serve
// de porta de entrada — quem está na enfermaria usa o número que tem em mão.
const SEARCH_MODES = [
  ['MEDICAL_RECORD', 'Prontuário'],
  ['ATTENDANCE', 'Nº de atendimento'],
]

export default function NewAdmissionPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState('lookup')
  const [searchMode, setSearchMode] = useState('MEDICAL_RECORD')
  const [numberTyped, setNumberTyped] = useState('')
  const [lookupError, setLookupError] = useState('')
  const [lookupLoading, setLookupLoading] = useState(false)

  const [patient, setPatient] = useState(null)
  const [lookupInfo, setLookupInfo] = useState(null)

  const [regName, setRegName] = useState('')
  const [regDob, setRegDob] = useState('')
  const [regMrn, setRegMrn] = useState('')
  const [regMrnConfirmed, setRegMrnConfirmed] = useState(false)

  const [form, setForm] = useState(getEmptyForm)
  const [selectedPlanLabel, setSelectedPlanLabel] = useState('')
  const [selectedCidLabel, setSelectedCidLabel] = useState('')
  const [selectedSpecialtyLabel, setSelectedSpecialtyLabel] = useState('')
  const [formError, setFormError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  const byMedicalRecord = searchMode === 'MEDICAL_RECORD'

  async function handleLookup(e) {
    e.preventDefault()
    setLookupError('')
    setLookupLoading(true)
    try {
      const params = byMedicalRecord
        ? { medical_record_number: numberTyped }
        : { attendance_number: numberTyped }
      const { data } = await api.get('/patients/lookup', { params })
      setPatient(data.patient)
      setLookupInfo(data)
      // Se casou por número de atendimento, aquele número é de uma
      // internação JÁ registrada — o episódio novo recebe outro.
      setForm((prev) => ({ ...prev, attendance_number: '' }))
      setStep('form')
    } catch (err) {
      if (err.response?.status === 404) {
        setRegMrn(byMedicalRecord ? numberTyped : '')
        setRegMrnConfirmed(false)
        setForm((prev) => ({ ...prev, attendance_number: byMedicalRecord ? '' : numberTyped }))
        setStep('register')
      } else {
        setLookupError(
          err.response?.data?.errors
            ? Object.values(err.response.data.errors).flat()[0]
            : 'Erro ao buscar paciente. Tente novamente.'
        )
      }
    } finally {
      setLookupLoading(false)
    }
  }

  async function handleRegister(e) {
    e.preventDefault()
    setLookupError('')

    if (!regMrn.trim() && !form.attendance_number.trim()) {
      setLookupError('Informe o número de prontuário ou o número de atendimento.')
      return
    }
    if (regMrn.trim() && !regMrnConfirmed) {
      setLookupError('Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.')
      return
    }

    setLookupLoading(true)
    try {
      const { data } = await api.post('/patients', {
        medical_record_number: regMrn.trim() || null,
        medical_record_confirmed: regMrn.trim() ? true : undefined,
        attendance_number: form.attendance_number.trim() || null,
        full_name: regName,
        date_of_birth: regDob || null,
      })
      setPatient(data.patient)
      setLookupInfo({ patient: data.patient, previously_followed: false, active_admission: null })
      setStep('form')
    } catch (err) {
      const errors = err.response?.data?.errors
      setLookupError(errors ? Object.values(errors).flat()[0] : 'Não foi possível cadastrar o paciente.')
    } finally {
      setLookupLoading(false)
    }
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setFormError('')

    if (!form.suspected_cid_code) {
      setFormError('Hipótese diagnóstica é obrigatória.')
      return
    }
    if (!patient.medical_record_number && !form.attendance_number.trim()) {
      setFormError('Número de atendimento é obrigatório enquanto o paciente não tiver prontuário confirmado.')
      return
    }
    if (!form.origin) {
      setFormError('Procedência é obrigatória.')
      return
    }
    if (form.care_type === 'INTERCONSULT' && !form.requesting_specialty_id) {
      setFormError('Especialidade solicitante é obrigatória em interconsultas.')
      return
    }
    if (form.payer_type === 'HEALTH_PLAN' && !form.health_plan_id) {
      setFormError('Selecione o plano de saúde.')
      return
    }

    setSubmitting(true)
    try {
      const payload = {
        patient_id: patient.id,
        attendance_number: form.attendance_number.trim() || null,
        admission_at: form.admission_at,
        care_type: form.care_type,
        followup_mode: form.followup_mode,
        payer_type: form.payer_type,
        health_plan_id: form.payer_type === 'HEALTH_PLAN' ? form.health_plan_id : null,
        requesting_specialty_id: form.care_type === 'INTERCONSULT' ? form.requesting_specialty_id : null,
        consult_requested_at: form.care_type === 'INTERCONSULT' ? form.consult_requested_at : null,
        origin: form.origin,
        unit: form.unit || null,
        bed: form.bed || null,
        brief_history: form.brief_history || null,
        suspected_cid_code: form.suspected_cid_code,
      }
      const { data } = await api.post('/admissions', payload)
      navigate(`/atendimentos/${data.id}`)
    } catch (err) {
      const errors = err.response?.data?.errors
      setFormError(errors ? Object.values(errors).flat()[0] : (err.response?.data?.message || 'Não foi possível salvar.'))
    } finally {
      setSubmitting(false)
    }
  }

  if (step === 'lookup') {
    return (
      <div className="card">
        <h2 className="section-title" style={{ marginTop: 0 }}>Novo atendimento</h2>

        <div className="form-group">
          <label>Buscar paciente por</label>
          <div className="radio-row">
            {SEARCH_MODES.map(([value, label]) => (
              <div
                key={value}
                className={`radio-pill ${searchMode === value ? 'selected' : ''}`}
                onClick={() => { setSearchMode(value); setLookupError('') }}
              >
                {label}
              </div>
            ))}
          </div>
        </div>

        <form onSubmit={handleLookup}>
          {lookupError && <div className="alert alert-danger">{lookupError}</div>}
          <div className="form-group">
            <label htmlFor="lookup-number">
              {byMedicalRecord ? 'Número de prontuário' : 'Número de atendimento'}
            </label>
            <input
              id="lookup-number"
              className="input"
              value={numberTyped}
              onChange={(e) => setNumberTyped(e.target.value)}
              required
              autoFocus
            />
            <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
              {byMedicalRecord
                ? 'O prontuário é único e vale para todas as internações do paciente.'
                : 'O número de atendimento muda a cada internação — a busca encontra o paciente pela passagem já registrada.'}
            </div>
          </div>
          <button type="submit" className="btn btn-primary btn-block" disabled={lookupLoading}>
            {lookupLoading ? 'Buscando…' : 'Buscar'}
          </button>
        </form>
      </div>
    )
  }

  if (step === 'register') {
    return (
      <div className="card">
        <h2 className="section-title" style={{ marginTop: 0 }}>Paciente não encontrado</h2>
        <p style={{ color: 'var(--color-text-muted)', fontSize: '0.9rem' }}>
          Nenhum paciente com {byMedicalRecord ? 'prontuário' : 'número de atendimento'} {numberTyped} — cadastre para continuar.
        </p>

        {!byMedicalRecord && (
          <div className="alert alert-warning">
            Buscar por número de atendimento só encontra internações já registradas aqui. Se este paciente
            pode ter sido atendido antes,{' '}
            <button
              type="button"
              onClick={() => { setSearchMode('MEDICAL_RECORD'); setNumberTyped(''); setLookupError(''); setStep('lookup') }}
              style={{ background: 'none', border: 'none', padding: 0, color: 'var(--color-primary)', textDecoration: 'underline', cursor: 'pointer', font: 'inherit' }}
            >
              busque pelo prontuário
            </button>{' '}
            antes de cadastrar, para não duplicar o registro.
          </div>
        )}

        <form onSubmit={handleRegister}>
          {lookupError && <div className="alert alert-danger">{lookupError}</div>}

          <div className="form-group">
            <label htmlFor="name">Nome completo</label>
            <input id="name" className="input" value={regName} onChange={(e) => setRegName(e.target.value)} required />
          </div>
          <div className="form-group">
            <label htmlFor="dob">Data de nascimento (pode ficar para depois)</label>
            <input id="dob" type="date" className="input" value={regDob} onChange={(e) => setRegDob(e.target.value)} />
            <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
              Será cobrada no encerramento do acompanhamento, junto com o prontuário.
            </div>
          </div>

          <div className="form-group">
            <label htmlFor="reg-attendance">Número de atendimento{regMrn.trim() ? ' (opcional)' : ''}</label>
            <input
              id="reg-attendance"
              className="input"
              value={form.attendance_number}
              onChange={(e) => setForm({ ...form, attendance_number: e.target.value })}
            />
          </div>

          <div className="form-group">
            <label htmlFor="reg-mrn">Número de prontuário{form.attendance_number.trim() ? ' (pode ficar para depois)' : ''}</label>
            <input
              id="reg-mrn"
              className="input"
              value={regMrn}
              onChange={(e) => { setRegMrn(e.target.value); setRegMrnConfirmed(false) }}
            />
            {regMrn.trim() ? (
              <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginTop: 8, fontSize: '0.85rem' }}>
                <input
                  type="checkbox"
                  checked={regMrnConfirmed}
                  onChange={(e) => setRegMrnConfirmed(e.target.checked)}
                  style={{ marginTop: 3 }}
                />
                <span>
                  Confirmo que o prontuário <strong>{regMrn.trim().toUpperCase()}</strong> está correto.
                  Depois de confirmado ele <strong>não poderá mais ser alterado</strong>.
                </span>
              </label>
            ) : (
              <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
                Sem prontuário agora, o paciente fica identificado pelo número de atendimento. O prontuário
                pode ser preenchido depois, na tela do atendimento.
              </div>
            )}
          </div>

          <button type="submit" className="btn btn-primary btn-block" disabled={lookupLoading}>
            {lookupLoading ? 'Salvando…' : 'Cadastrar e continuar'}
          </button>
        </form>
      </div>
    )
  }

  // step === 'form'
  if (lookupInfo?.active_admission) {
    return (
      <div className="card">
        <div className="alert alert-warning">Este paciente já possui acompanhamento ativo.</div>
        <Link to={`/atendimentos/${lookupInfo.active_admission.id}`} className="btn btn-primary btn-block">
          Abrir atendimento atual
        </Link>
      </div>
    )
  }

  return (
    <div className="card">
      <h2 className="section-title" style={{ marginTop: 0 }}>{patient.full_name}</h2>
      <p style={{ color: 'var(--color-text-muted)', fontSize: '0.85rem', marginTop: -8 }}>
        {patient.medical_record_number
          ? `Prontuário ${patient.medical_record_number}`
          : 'Prontuário pendente'} · Nascimento {formatDate(patient.date_of_birth)}
      </p>

      {!patient.medical_record_number && (
        <div className="alert alert-warning">
          Este paciente ainda não tem prontuário confirmado — o número de atendimento é obrigatório.
        </div>
      )}

      {lookupInfo?.previously_followed && (
        <div className="alert alert-warning">⚠ Paciente já acompanhado anteriormente pela Neurologia ({lookupInfo.admissions_count} internação{lookupInfo.admissions_count === 1 ? '' : 'ões'}).</div>
      )}

      <form onSubmit={handleSubmit}>
        {formError && <div className="alert alert-danger">{formError}</div>}

        <div className="form-group">
          <label>Número de atendimento{patient.medical_record_number ? ' (opcional)' : ''}</label>
          <input
            className="input"
            value={form.attendance_number}
            onChange={(e) => setForm({ ...form, attendance_number: e.target.value })}
            required={!patient.medical_record_number}
          />
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            Número desta internação. Pode ser editado depois.
          </div>
        </div>

        <div className="form-group">
          <label>Data/hora de entrada</label>
          <input type="datetime-local" className="input" value={form.admission_at}
            onChange={(e) => setForm({ ...form, admission_at: e.target.value })} required />
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            Confira antes de salvar: a data de entrada não pode ser alterada depois.
          </div>
        </div>

        <div className="form-group">
          <label>Tipo de atendimento</label>
          <div className="radio-row">
            {[['INSTITUTIONAL', 'Institucional'], ['INTERCONSULT', 'Interconsulta']].map(([value, label]) => (
              <div key={value} className={`radio-pill ${form.care_type === value ? 'selected' : ''}`}
                onClick={() => setForm({ ...form, care_type: value })}>{label}</div>
            ))}
          </div>
        </div>

        {form.care_type === 'INTERCONSULT' && (
          <>
            <div className="form-group">
              <label>Especialidade solicitante</label>
              <Autocomplete
                searchUrl="/medical-specialties/search"
                initialLabel={selectedSpecialtyLabel}
                placeholder="Digite para buscar…"
                onSelect={(opt) => {
                  setForm({ ...form, requesting_specialty_id: opt?.id || null })
                  setSelectedSpecialtyLabel(opt?.name || '')
                }}
              />
            </div>
            <div className="form-group">
              <label>Horário da solicitação</label>
              <input type="datetime-local" className="input" value={form.consult_requested_at}
                onChange={(e) => setForm({ ...form, consult_requested_at: e.target.value })} required />
            </div>
          </>
        )}

        <div className="form-group">
          <label>Modalidade de acompanhamento</label>
          <div className="radio-row">
            {[['ONGOING', 'Acompanhamento'], ['SINGLE_EVALUATION', 'Avaliação única']].map(([value, label]) => (
              <div key={value} className={`radio-pill ${form.followup_mode === value ? 'selected' : ''}`}
                onClick={() => setForm({ ...form, followup_mode: value })}>{label}</div>
            ))}
          </div>
        </div>

        <div className="form-group">
          <label>Forma de pagamento</label>
          <div className="radio-row">
            {[['PRIVATE', 'Particular'], ['HEALTH_PLAN', 'Plano de saúde']].map(([value, label]) => (
              <div key={value} className={`radio-pill ${form.payer_type === value ? 'selected' : ''}`}
                onClick={() => setForm({ ...form, payer_type: value })}>{label}</div>
            ))}
          </div>
        </div>

        {form.payer_type === 'HEALTH_PLAN' && (
          <div className="form-group">
            <label>Plano de saúde</label>
            <Autocomplete
              searchUrl="/health-plans/search"
              initialLabel={selectedPlanLabel}
              placeholder="Digite para buscar… (ex: bra)"
              onSelect={(opt) => {
                setForm({ ...form, health_plan_id: opt?.id || null })
                setSelectedPlanLabel(opt?.name || '')
              }}
            />
          </div>
        )}

        <div className="form-group">
          <label>Hipótese diagnóstica (CID-10)</label>
          <Autocomplete
            searchUrl="/cid10/search"
            valueKey="code"
            labelKey="description"
            initialLabel={selectedCidLabel}
            placeholder="Código ou descrição…"
            renderOption={(opt) => `${opt.code} — ${opt.description}`}
            onSelect={(opt) => {
              setForm({ ...form, suspected_cid_code: opt?.code || '' })
              setSelectedCidLabel(opt ? `${opt.code} — ${opt.description}` : '')
            }}
          />
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            Confira antes de salvar: o CID inicial não pode ser alterado depois (diagnósticos
            adicionais e o CID final entram no encerramento).
          </div>
        </div>

        <div className="form-group">
          <label>Procedência</label>
          <select className="input" value={form.origin} required
            onChange={(e) => setForm({ ...form, origin: e.target.value })}>
            <option value="" disabled>Selecione…</option>
            {ORIGINS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            De onde o paciente veio ao entrar em acompanhamento.
          </div>
        </div>

        <div className="form-group">
          <label>Enfermaria (opcional)</label>
          <input className="input" value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} />
        </div>
        <div className="form-group">
          <label>Leito (opcional)</label>
          <input className="input" value={form.bed} onChange={(e) => setForm({ ...form, bed: e.target.value })} />
        </div>
        <div className="form-group">
          <label>História breve (opcional)</label>
          <textarea className="input" value={form.brief_history} onChange={(e) => setForm({ ...form, brief_history: e.target.value })} />
        </div>

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          {submitting ? 'Salvando…' : 'Criar atendimento'}
        </button>
      </form>
    </div>
  )
}

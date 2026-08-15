import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import api from '../lib/api'
import Autocomplete from '../components/Autocomplete'
import { formatDate } from '../lib/format'

const emptyForm = {
  admission_at: new Date().toISOString().slice(0, 16),
  care_type: 'INSTITUTIONAL',
  followup_mode: 'ONGOING',
  payer_type: 'PRIVATE',
  health_plan_id: null,
  requesting_specialty_id: null,
  consult_requested_at: new Date().toISOString().slice(0, 16),
  unit: '',
  bed: '',
  brief_history: '',
  suspected_cid_code: '',
}

export default function NewAdmissionPage() {
  const navigate = useNavigate()
  const [step, setStep] = useState('lookup')
  const [mrn, setMrn] = useState('')
  const [lookupError, setLookupError] = useState('')
  const [lookupLoading, setLookupLoading] = useState(false)

  const [patient, setPatient] = useState(null)
  const [lookupInfo, setLookupInfo] = useState(null)

  const [regName, setRegName] = useState('')
  const [regDob, setRegDob] = useState('')

  const [form, setForm] = useState(emptyForm)
  const [selectedPlanLabel, setSelectedPlanLabel] = useState('')
  const [selectedCidLabel, setSelectedCidLabel] = useState('')
  const [selectedSpecialtyLabel, setSelectedSpecialtyLabel] = useState('')
  const [formError, setFormError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function handleLookup(e) {
    e.preventDefault()
    setLookupError('')
    setLookupLoading(true)
    try {
      const { data } = await api.get('/patients/lookup', { params: { medical_record_number: mrn } })
      setPatient(data.patient)
      setLookupInfo(data)
      setStep('form')
    } catch (err) {
      if (err.response?.status === 404) {
        setStep('register')
      } else {
        setLookupError('Erro ao buscar paciente. Tente novamente.')
      }
    } finally {
      setLookupLoading(false)
    }
  }

  async function handleRegister(e) {
    e.preventDefault()
    setLookupError('')
    setLookupLoading(true)
    try {
      const { data } = await api.post('/patients', {
        medical_record_number: mrn,
        full_name: regName,
        date_of_birth: regDob,
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
        admission_at: form.admission_at,
        care_type: form.care_type,
        followup_mode: form.followup_mode,
        payer_type: form.payer_type,
        health_plan_id: form.payer_type === 'HEALTH_PLAN' ? form.health_plan_id : null,
        requesting_specialty_id: form.care_type === 'INTERCONSULT' ? form.requesting_specialty_id : null,
        consult_requested_at: form.care_type === 'INTERCONSULT' ? form.consult_requested_at : null,
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
        <form onSubmit={handleLookup}>
          {lookupError && <div className="alert alert-danger">{lookupError}</div>}
          <div className="form-group">
            <label htmlFor="mrn">Número de prontuário</label>
            <input id="mrn" className="input" value={mrn} onChange={(e) => setMrn(e.target.value)} required autoFocus />
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
        <p style={{ color: 'var(--color-text-muted)', fontSize: '0.9rem' }}>Prontuário {mrn} — cadastre o paciente para continuar.</p>
        <form onSubmit={handleRegister}>
          {lookupError && <div className="alert alert-danger">{lookupError}</div>}
          <div className="form-group">
            <label htmlFor="name">Nome completo</label>
            <input id="name" className="input" value={regName} onChange={(e) => setRegName(e.target.value)} required />
          </div>
          <div className="form-group">
            <label htmlFor="dob">Data de nascimento</label>
            <input id="dob" type="date" className="input" value={regDob} onChange={(e) => setRegDob(e.target.value)} required />
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
        Prontuário {patient.medical_record_number} · Nascimento {formatDate(patient.date_of_birth)}
      </p>

      {lookupInfo?.previously_followed && (
        <div className="alert alert-warning">⚠ Paciente já acompanhado anteriormente pela Neurologia ({lookupInfo.admissions_count} internação{lookupInfo.admissions_count === 1 ? '' : 'ões'}).</div>
      )}

      <form onSubmit={handleSubmit}>
        {formError && <div className="alert alert-danger">{formError}</div>}

        <div className="form-group">
          <label>Data/hora de entrada</label>
          <input type="datetime-local" className="input" value={form.admission_at}
            onChange={(e) => setForm({ ...form, admission_at: e.target.value })} required />
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

import { useState } from 'react'
import api from '../lib/api'

/**
 * Edição do cadastro do paciente. Nome e nascimento são sempre editáveis;
 * o prontuário só aparece como campo enquanto estiver pendente — depois de
 * confirmado ele é a chave de indexação dos dados de gestão e vira imutável
 * (o backend recusa a alteração de qualquer forma).
 *
 * `onlyMedicalRecord` reduz o formulário ao campo do prontuário, para ser
 * usado direto no aviso de "prontuário pendente" no topo do atendimento —
 * ali quem clica quer preencher um número, não revisar o cadastro inteiro.
 */
export default function PatientEditForm({ patient, onSaved, onlyMedicalRecord = false }) {
  const [fullName, setFullName] = useState(patient.full_name || '')
  const [dob, setDob] = useState(String(patient.date_of_birth || '').slice(0, 10))
  const [mrn, setMrn] = useState('')
  const [mrnConfirmed, setMrnConfirmed] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [saving, setSaving] = useState(false)

  const hasMrn = !!patient.medical_record_number

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSuccess('')

    if (onlyMedicalRecord && !mrn.trim()) {
      setError('Informe o número de prontuário.')
      return
    }

    if (!hasMrn && mrn.trim() && !mrnConfirmed) {
      setError('Confirme o número de prontuário — depois de confirmado ele não poderá mais ser alterado.')
      return
    }

    setSaving(true)
    try {
      const payload = onlyMedicalRecord ? {} : { full_name: fullName, date_of_birth: dob || null }
      if (!hasMrn && mrn.trim()) {
        payload.medical_record_number = mrn.trim()
        payload.medical_record_confirmed = true
      }

      const { data } = await api.put(`/patients/${patient.id}`, payload)
      setMrn('')
      setMrnConfirmed(false)
      setSuccess(onlyMedicalRecord
        ? `Prontuário ${data.patient.medical_record_number} confirmado.`
        : 'Cadastro do paciente atualizado.')
      onSaved?.(data.patient)
    } catch (err) {
      const errors = err.response?.data?.errors
      setError(errors ? Object.values(errors).flat()[0] : (err.response?.data?.message || 'Não foi possível salvar.'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {!onlyMedicalRecord && (
        <>
          <div className="form-group">
            <label>Nome completo</label>
            <input className="input" value={fullName} onChange={(e) => setFullName(e.target.value)} required />
          </div>

          <div className="form-group">
            <label>Data de nascimento</label>
            <input type="date" className="input" value={dob} onChange={(e) => setDob(e.target.value)} />
          </div>
        </>
      )}

      {hasMrn ? (
        <div className="form-group">
          <label>Número de prontuário</label>
          <input className="input" value={patient.medical_record_number} disabled readOnly />
          <div style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginTop: 4 }}>
            🔒 Confirmado — não pode ser alterado.
          </div>
        </div>
      ) : (
        <div className="form-group">
          <label>Número de prontuário (pendente)</label>
          <input
            className="input"
            value={mrn}
            onChange={(e) => { setMrn(e.target.value); setMrnConfirmed(false) }}
            placeholder="Preencher quando o prontuário for conhecido"
            autoFocus={onlyMedicalRecord}
          />
          {mrn.trim() && (
            <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', marginTop: 8, fontSize: '0.85rem' }}>
              <input
                type="checkbox"
                checked={mrnConfirmed}
                onChange={(e) => setMrnConfirmed(e.target.checked)}
                style={{ marginTop: 3 }}
              />
              <span>
                Confirmo que o prontuário <strong>{mrn.trim().toUpperCase()}</strong> está correto.
                Depois de confirmado ele <strong>não poderá mais ser alterado</strong>.
              </span>
            </label>
          )}
        </div>
      )}

      <button type="submit" className="btn btn-primary btn-block" disabled={saving}>
        {saving
          ? 'Salvando…'
          : (onlyMedicalRecord ? 'Confirmar prontuário' : 'Salvar cadastro do paciente')}
      </button>
    </form>
  )
}

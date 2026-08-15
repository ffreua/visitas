import { useState } from 'react'
import { useNavigate, Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function ChangePasswordPage() {
  const { user, changePassword } = useAuth()
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const navigate = useNavigate()

  if (user && !user.must_change_password) {
    return <Navigate to="/" replace />
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')

    if (newPassword !== confirmation) {
      setError('A confirmação não confere com a nova senha.')
      return
    }

    setSubmitting(true)
    try {
      await changePassword(currentPassword, newPassword, confirmation)
      navigate('/')
    } catch (err) {
      const errors = err.response?.data?.errors
      const msg = errors ? Object.values(errors).flat()[0] : (err.response?.data?.message || 'Não foi possível trocar a senha.')
      setError(msg)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div style={{ minHeight: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
      <form className="card" style={{ width: '100%', maxWidth: 380 }} onSubmit={handleSubmit}>
        <h1 style={{ fontSize: '1.1rem', marginTop: 0 }}>Você deve criar uma nova senha antes de continuar.</h1>

        {error && <div className="alert alert-danger">{error}</div>}

        <div className="form-group">
          <label htmlFor="current">Senha atual</label>
          <input id="current" type="password" className="input" value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)} required />
        </div>

        <div className="form-group">
          <label htmlFor="new">Nova senha</label>
          <input id="new" type="password" className="input" minLength={8} value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)} required />
        </div>

        <div className="form-group">
          <label htmlFor="confirm">Confirme a nova senha</label>
          <input id="confirm" type="password" className="input" minLength={8} value={confirmation}
            onChange={(e) => setConfirmation(e.target.value)} required />
        </div>

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          {submitting ? 'Salvando…' : 'Salvar nova senha'}
        </button>
      </form>
    </div>
  )
}

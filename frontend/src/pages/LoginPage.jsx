import { useState } from 'react'
import { useNavigate, useLocation, Navigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function LoginPage() {
  const { user, loading, login } = useAuth()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const navigate = useNavigate()
  const location = useLocation()

  if (!loading && user) {
    const dest = location.state?.from?.pathname || '/'
    return <Navigate to={dest} replace />
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSubmitting(true)
    try {
      await login(username, password)
      navigate('/')
    } catch (err) {
      const msg = err.response?.data?.errors?.username?.[0]
        || err.response?.data?.message
        || 'Não foi possível entrar. Tente novamente.'
      setError(msg)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div style={{ minHeight: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 16 }}>
      <form className="card" style={{ width: '100%', maxWidth: 380 }} onSubmit={handleSubmit}>
        <h1 style={{ fontSize: '1.2rem', marginTop: 0 }}>Neurologia Hospitalar</h1>
        <p style={{ color: 'var(--color-text-muted)', fontSize: '0.88rem', marginTop: -8 }}>
          Acesso restrito à equipe.
        </p>

        {error && <div className="alert alert-danger">{error}</div>}

        <div className="form-group">
          <label htmlFor="username">Usuário</label>
          <input
            id="username"
            className="input"
            autoComplete="username"
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            required
          />
        </div>

        <div className="form-group">
          <label htmlFor="password">Senha</label>
          <input
            id="password"
            type="password"
            className="input"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </div>

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          {submitting ? 'Entrando…' : 'Entrar'}
        </button>
      </form>
    </div>
  )
}

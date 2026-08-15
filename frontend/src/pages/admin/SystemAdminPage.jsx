import { useEffect, useState } from 'react'
import api from '../../lib/api'
import { formatDateTime } from '../../lib/format'

export default function SystemAdminPage() {
  const [integrity, setIntegrity] = useState(null)
  const [backups, setBackups] = useState([])
  const [loadingBackups, setLoadingBackups] = useState(true)

  const [showDanger, setShowDanger] = useState(false)
  const [password, setPassword] = useState('')
  const [phrase, setPhrase] = useState('')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    loadBackups()
  }, [])

  function loadBackups() {
    setLoadingBackups(true)
    api.get('/admin/system/backups').then(({ data }) => setBackups(data)).finally(() => setLoadingBackups(false))
  }

  async function checkIntegrity() {
    const { data } = await api.get('/admin/system/integrity-check')
    setIntegrity(data.result)
  }

  async function handleReset(e) {
    e.preventDefault()
    setError('')
    setSuccess('')
    setSubmitting(true)
    try {
      const { data } = await api.post('/admin/system/reset-clinical-data', {
        password, confirmation_phrase: phrase,
      })
      setSuccess(`${data.message} (backup de segurança: ${data.safety_backup})`)
      setPassword('')
      setPhrase('')
      setShowDanger(false)
      loadBackups()
    } catch (err) {
      setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {}).flat()[0] || 'Erro ao zerar dados.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Sistema</h2>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Verificar banco (integrity_check)</div>
        <button className="btn btn-outline" onClick={checkIntegrity}>Verificar agora</button>
        {integrity && (
          <pre style={{ marginTop: 8, fontSize: '0.8rem', whiteSpace: 'pre-wrap' }}>{JSON.stringify(integrity, null, 2)}</pre>
        )}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Backups</div>
        <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>
          Criados automaticamente (agendado) ou via <code>php artisan neurologia:backup</code>. Restaurar um backup exige acesso direto ao servidor (<code>php artisan neurologia:restore</code>) — não é feito por aqui, por segurança.
        </p>
        {loadingBackups ? (
          <div className="empty-state">Carregando…</div>
        ) : backups.length === 0 ? (
          <div className="empty-state">Nenhum backup encontrado ainda.</div>
        ) : (
          <table className="admin-table">
            <thead><tr><th>Arquivo</th><th>Tamanho</th><th>Criado em</th></tr></thead>
            <tbody>
              {backups.map((b) => (
                <tr key={b.filename}>
                  <td>{b.filename}</td>
                  <td>{(b.size / 1024).toFixed(0)} KB</td>
                  <td>{formatDateTime(b.created_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div className="card" style={{ borderColor: 'var(--color-danger)' }}>
        <div className="section-title" style={{ marginTop: 0, color: 'var(--color-danger)' }}>Zona de perigo</div>

        {!showDanger ? (
          <button className="btn btn-danger" onClick={() => { setShowDanger(true); setError(''); setSuccess('') }}>
            Zerar dados clínicos
          </button>
        ) : (
          <form onSubmit={handleReset}>
            {error && <div className="alert alert-danger">{error}</div>}
            {success && <div className="alert alert-warning">{success}</div>}

            <div className="alert alert-danger">
              Isso vai apagar TODOS os pacientes e episódios (ativos, encerrados e excluídos). Usuários, CID-10,
              especialidades e planos de saúde serão preservados. Um backup de segurança é criado e verificado
              antes — se o backup falhar, nada é apagado.
            </div>

            <div className="form-group">
              <label>Sua senha (reautenticação)</label>
              <input type="password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required />
            </div>

            <div className="form-group">
              <label>Digite: ZERAR DADOS CLINICOS</label>
              <input className="input" value={phrase} onChange={(e) => setPhrase(e.target.value)} required />
            </div>

            <div style={{ display: 'flex', gap: 8 }}>
              <button type="submit" className="btn btn-danger" disabled={submitting}>
                {submitting ? 'Processando…' : 'Confirmar — zerar dados clínicos'}
              </button>
              <button type="button" className="btn btn-outline" onClick={() => setShowDanger(false)}>Cancelar</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}

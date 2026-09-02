import { useEffect, useState } from 'react'
import api from '../../lib/api'
import { formatDateTime } from '../../lib/format'

export default function SystemAdminPage() {
  const [integrity, setIntegrity] = useState(null)
  const [backups, setBackups] = useState([])
  const [backupTotals, setBackupTotals] = useState(null)
  const [loadingBackups, setLoadingBackups] = useState(true)

  // Um único formulário de senha para as duas ações destrutivas de backup
  // (excluir um arquivo, limpar os fora da política): pedir a senha em dois
  // lugares diferentes da mesma tela não acrescentaria segurança nenhuma.
  const [backupAction, setBackupAction] = useState(null)
  const [backupPassword, setBackupPassword] = useState('')
  const [backupError, setBackupError] = useState('')
  const [backupSuccess, setBackupSuccess] = useState('')
  const [backupBusy, setBackupBusy] = useState(false)

  const [migrations, setMigrations] = useState(null)
  const [showMigrate, setShowMigrate] = useState(false)
  const [migratePassword, setMigratePassword] = useState('')
  const [migratePhrase, setMigratePhrase] = useState('')
  const [migrateError, setMigrateError] = useState('')
  const [migrateSuccess, setMigrateSuccess] = useState('')
  const [migrating, setMigrating] = useState(false)

  const [showDanger, setShowDanger] = useState(false)
  const [password, setPassword] = useState('')
  const [phrase, setPhrase] = useState('')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [submitting, setSubmitting] = useState(false)

  useEffect(() => {
    loadBackups()
    loadMigrations()
  }, [])

  function loadMigrations() {
    api.get('/admin/system/migration-status')
      .then(({ data }) => setMigrations(data))
      .catch(() => setMigrations(null))
  }

  async function handleMigrate(e) {
    e.preventDefault()
    setMigrateError('')
    setMigrateSuccess('')
    setMigrating(true)
    try {
      const { data } = await api.post('/admin/system/apply-migrations', {
        password: migratePassword,
        confirmation_phrase: migratePhrase,
      })
      const applied = data.applied?.length
        ? ` Aplicadas: ${data.applied.join(', ')}.`
        : ''
      const backup = data.safety_backup ? ` Backup de segurança: ${data.safety_backup}.` : ''
      setMigrateSuccess(`${data.message}${applied}${backup}`)
      setMigratePassword('')
      setMigratePhrase('')
      setShowMigrate(false)
      loadMigrations()
      loadBackups()
    } catch (err) {
      setMigrateError(
        err.response?.data?.message
        || Object.values(err.response?.data?.errors || {}).flat()[0]
        || 'Erro ao atualizar o banco.'
      )
    } finally {
      setMigrating(false)
    }
  }

  function loadBackups() {
    setLoadingBackups(true)
    api.get('/admin/system/backups')
      .then(({ data }) => {
        setBackups(data.backups)
        setBackupTotals({ total_size: data.total_size, retention: data.retention })
      })
      .finally(() => setLoadingBackups(false))
  }

  function armBackupAction(action) {
    setBackupAction(action)
    setBackupPassword('')
    setBackupError('')
    setBackupSuccess('')
  }

  async function runBackupAction(e) {
    e.preventDefault()
    setBackupError('')
    setBackupSuccess('')
    setBackupBusy(true)
    try {
      const { data } = backupAction.type === 'PRUNE'
        ? await api.post('/admin/system/backups/prune', { password: backupPassword })
        : await api.delete(`/admin/system/backups/${backupAction.filename}`, { data: { password: backupPassword } })

      setBackupSuccess(data.message)
      setBackupAction(null)
      setBackupPassword('')
      loadBackups()
    } catch (err) {
      setBackupError(
        err.response?.data?.message
        || Object.values(err.response?.data?.errors || {}).flat()[0]
        || 'Não foi possível concluir a operação.'
      )
    } finally {
      setBackupBusy(false)
    }
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
        <div className="section-title" style={{ marginTop: 0 }}>Estrutura do banco</div>
        <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>
          Depois de subir uma versão nova dos arquivos do sistema, o banco pode precisar de colunas novas.
          Esta tela aplica só o que falta — nenhum dado já cadastrado é apagado, e um backup verificado é
          criado antes (se o backup falhar, nada é executado).
        </p>

        {migrateError && <div className="alert alert-danger">{migrateError}</div>}
        {migrateSuccess && <div className="alert alert-success">{migrateSuccess}</div>}

        {migrations === null ? (
          <div className="empty-state">Verificando…</div>
        ) : migrations.up_to_date ? (
          <div className="alert alert-success">
            ✓ Banco atualizado — {migrations.ran} de {migrations.total} atualizações aplicadas, nada pendente.
          </div>
        ) : (
          <>
            <div className="alert alert-warning">
              ⚠ {migrations.pending.length} atualização{migrations.pending.length === 1 ? '' : 'ões'} pendente{migrations.pending.length === 1 ? '' : 's'}:
              <ul style={{ margin: '6px 0 0', paddingLeft: 18 }}>
                {migrations.pending.map((name) => <li key={name} style={{ fontSize: '0.85rem' }}>{name}</li>)}
              </ul>
            </div>

            {!showMigrate ? (
              <button className="btn btn-primary" onClick={() => { setShowMigrate(true); setMigrateError(''); setMigrateSuccess('') }}>
                Atualizar estrutura do banco
              </button>
            ) : (
              <form onSubmit={handleMigrate}>
                <div className="form-group">
                  <label>Sua senha (reautenticação)</label>
                  <input type="password" className="input" value={migratePassword}
                    onChange={(e) => setMigratePassword(e.target.value)} required />
                </div>
                <div className="form-group">
                  <label>Digite: ATUALIZAR BANCO</label>
                  <input className="input" value={migratePhrase} onChange={(e) => setMigratePhrase(e.target.value)} required />
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button type="submit" className="btn btn-primary" disabled={migrating}>
                    {migrating ? 'Aplicando…' : 'Confirmar atualização'}
                  </button>
                  <button type="button" className="btn btn-outline" onClick={() => setShowMigrate(false)}>Cancelar</button>
                </div>
              </form>
            )}
          </>
        )}
      </div>

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Backups</div>
        <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>
          Criados automaticamente sempre que a estrutura do banco é atualizada, ao usar a zona de
          perigo, ou pelo backup agendado. Restaurar um backup exige acesso direto ao servidor
          (<code>php artisan neurologia:restore</code>) — não é feito por aqui, por segurança.
        </p>

        {backupTotals && (
          <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>
            <strong>Retenção automática:</strong> mantém {backupTotals.retention.daily} diários,{' '}
            {backupTotals.retention.weekly} semanais e {backupTotals.retention.monthly} mensais — o que
            sai dessa janela é apagado sozinho quando um backup novo é criado.{' '}
            <strong>Ocupando agora:</strong> {(backupTotals.total_size / 1024 / 1024).toFixed(1)} MB em{' '}
            {backups.length} arquivo{backups.length === 1 ? '' : 's'}.
          </p>
        )}

        {backupError && <div className="alert alert-danger">{backupError}</div>}
        {backupSuccess && <div className="alert alert-success">{backupSuccess}</div>}

        {backupAction && (
          <form onSubmit={runBackupAction} className="card" style={{ background: 'var(--color-bg)' }}>
            <div className="alert alert-warning">
              {backupAction.type === 'PRUNE'
                ? 'Excluir agora todos os backups fora da política de retenção. Ação irreversível.'
                : `Excluir definitivamente o backup ${backupAction.filename}. Ação irreversível.`}
            </div>
            <div className="form-group">
              <label>Sua senha (reautenticação)</label>
              <input type="password" className="input" value={backupPassword} autoFocus
                onChange={(e) => setBackupPassword(e.target.value)} required />
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button type="submit" className="btn btn-danger" disabled={backupBusy}>
                {backupBusy ? 'Excluindo…' : 'Confirmar exclusão'}
              </button>
              <button type="button" className="btn btn-outline"
                onClick={() => { setBackupAction(null); setBackupPassword('') }}>
                Cancelar
              </button>
            </div>
          </form>
        )}

        {loadingBackups ? (
          <div className="empty-state">Carregando…</div>
        ) : backups.length === 0 ? (
          <div className="empty-state">Nenhum backup encontrado ainda.</div>
        ) : (
          <>
            <table className="admin-table">
              <thead><tr><th>Arquivo</th><th>Tamanho</th><th>Criado em</th><th></th></tr></thead>
              <tbody>
                {backups.map((b) => (
                  <tr key={b.filename}>
                    <td>{b.filename}</td>
                    <td>{(b.size / 1024).toFixed(0)} KB</td>
                    <td>{formatDateTime(b.created_at)}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn-outline"
                        style={{ minHeight: 32, padding: '4px 10px', color: 'var(--color-danger)', borderColor: 'var(--color-danger)' }}
                        onClick={() => armBackupAction({ type: 'DELETE', filename: b.filename })}
                      >
                        Excluir
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>

            <button type="button" className="btn btn-outline" style={{ marginTop: 10 }}
              onClick={() => armBackupAction({ type: 'PRUNE' })}>
              🧹 Aplicar retenção agora
            </button>
          </>
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

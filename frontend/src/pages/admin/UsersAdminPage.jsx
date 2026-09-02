import { useEffect, useState } from 'react'
import api from '../../lib/api'
import { useAuth } from '../../context/AuthContext'

const EMPTY_FORM = { full_name: '', crm: '', username: '', role: 'PHYSICIAN' }

export default function UsersAdminPage() {
  const { user: currentUser } = useAuth()
  const [users, setUsers] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState(EMPTY_FORM)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')

  // Só um usuário em edição por vez, e só um em exclusão por vez — manter
  // os dois abertos ao mesmo tempo na mesma tabela confunde mais do que
  // ajuda.
  const [editing, setEditing] = useState(null)
  const [deleting, setDeleting] = useState(null)
  const [deletePassword, setDeletePassword] = useState('')
  const [busy, setBusy] = useState(false)

  function load() {
    api.get('/admin/users').then(({ data }) => setUsers(data.data))
  }

  useEffect(() => { load() }, [])

  function reportError(err, fallback) {
    setError(
      err.response?.data?.message
      || Object.values(err.response?.data?.errors || {}).flat()[0]
      || fallback
    )
  }

  async function handleCreate(e) {
    e.preventDefault()
    setError('')
    setSuccess('')
    try {
      await api.post('/admin/users', form)
      setForm(EMPTY_FORM)
      setShowForm(false)
      load()
    } catch (err) {
      reportError(err, 'Erro ao criar usuário.')
    }
  }

  async function handleAction(userId, action) {
    if (action === 'reset-password' && !confirm('Redefinir a senha deste usuário para o padrão?')) return
    setError('')
    setSuccess('')
    try {
      await api.post(`/admin/users/${userId}/${action}`)
      load()
    } catch (err) {
      reportError(err, 'Não foi possível concluir a ação.')
    }
  }

  async function handleUpdate(e) {
    e.preventDefault()
    setError('')
    setSuccess('')
    setBusy(true)
    try {
      await api.put(`/admin/users/${editing.id}`, {
        full_name: editing.full_name,
        crm: editing.crm || null,
        username: editing.username,
        role: editing.role,
      })
      setSuccess('Cadastro atualizado.')
      setEditing(null)
      load()
    } catch (err) {
      reportError(err, 'Não foi possível salvar.')
    } finally {
      setBusy(false)
    }
  }

  async function handleDelete(e) {
    e.preventDefault()
    setError('')
    setSuccess('')
    setBusy(true)
    try {
      const { data } = await api.delete(`/admin/users/${deleting.id}`, {
        data: { password: deletePassword },
      })
      setSuccess(data.message)
      setDeleting(null)
      setDeletePassword('')
      load()
    } catch (err) {
      reportError(err, 'Não foi possível excluir.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Equipe</h2>

      {error && <div className="alert alert-danger">{error}</div>}
      {success && <div className="alert alert-success">{success}</div>}

      {!showForm ? (
        <button className="btn btn-primary" style={{ marginBottom: 12 }} onClick={() => setShowForm(true)}>+ Novo médico</button>
      ) : (
        <form className="card" onSubmit={handleCreate}>
          <div className="form-group">
            <label>Nome completo</label>
            <input className="input" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} required />
          </div>
          <div className="form-group">
            <label>CRM</label>
            <input className="input" value={form.crm} onChange={(e) => setForm({ ...form, crm: e.target.value })} />
          </div>
          <div className="form-group">
            <label>Usuário (login)</label>
            <input className="input" value={form.username} onChange={(e) => setForm({ ...form, username: e.target.value })} required />
          </div>
          <div className="form-group">
            <label>Perfil</label>
            <select className="input" value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })}>
              <option value="PHYSICIAN">Médico</option>
              <option value="ADMIN">Administrador</option>
            </select>
          </div>
          <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>Senha inicial: <code>senha@1234</code> (troca obrigatória no primeiro login).</p>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" className="btn btn-primary">Criar</button>
            <button type="button" className="btn btn-outline" onClick={() => setShowForm(false)}>Cancelar</button>
          </div>
        </form>
      )}

      {editing && (
        <form className="card" onSubmit={handleUpdate} style={{ borderColor: 'var(--color-primary)' }}>
          <div className="section-title" style={{ marginTop: 0 }}>Editar {editing.full_name}</div>
          <div className="form-group">
            <label>Nome completo</label>
            <input className="input" value={editing.full_name}
              onChange={(e) => setEditing({ ...editing, full_name: e.target.value })} required />
          </div>
          <div className="form-group">
            <label>CRM</label>
            <input className="input" value={editing.crm || ''}
              onChange={(e) => setEditing({ ...editing, crm: e.target.value })} />
          </div>
          <div className="form-group">
            <label>Usuário (login)</label>
            <input className="input" value={editing.username}
              onChange={(e) => setEditing({ ...editing, username: e.target.value })} required />
          </div>
          <div className="form-group">
            <label>Perfil</label>
            <select className="input" value={editing.role}
              onChange={(e) => setEditing({ ...editing, role: e.target.value })}>
              <option value="PHYSICIAN">Médico</option>
              <option value="ADMIN">Administrador</option>
            </select>
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" className="btn btn-primary" disabled={busy}>
              {busy ? 'Salvando…' : 'Salvar'}
            </button>
            <button type="button" className="btn btn-outline" onClick={() => setEditing(null)}>Cancelar</button>
          </div>
        </form>
      )}

      {deleting && (
        <form className="card" onSubmit={handleDelete} style={{ borderColor: 'var(--color-danger)' }}>
          <div className="alert alert-danger">
            Excluir definitivamente <strong>{deleting.full_name}</strong> ({deleting.username}).
            Ação irreversível. Use apenas para conta criada por engano — quem já participou de
            atendimentos deve ser <strong>desativado</strong>, para preservar a autoria dos registros.
          </div>
          <div className="form-group">
            <label>Sua senha (reautenticação)</label>
            <input type="password" className="input" value={deletePassword} autoFocus
              onChange={(e) => setDeletePassword(e.target.value)} required />
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <button type="submit" className="btn btn-danger" disabled={busy}>
              {busy ? 'Excluindo…' : 'Confirmar exclusão'}
            </button>
            <button type="button" className="btn btn-outline"
              onClick={() => { setDeleting(null); setDeletePassword('') }}>Cancelar</button>
          </div>
        </form>
      )}

      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table">
          <thead>
            <tr><th>Nome</th><th>Usuário</th><th>CRM</th><th>Perfil</th><th>Status</th><th>Ações</th></tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td>{u.full_name}{u.id === currentUser?.id ? ' (você)' : ''}</td>
                <td>{u.username}</td>
                <td>{u.crm || '—'}</td>
                <td>{u.role === 'ADMIN' ? 'Administrador' : 'Médico'}</td>
                <td>{u.active ? 'Ativo' : 'Inativo'}</td>
                <td>
                  <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap' }}>
                    <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }}
                      onClick={() => { setEditing({ ...u }); setDeleting(null); setError(''); setSuccess('') }}>
                      Editar
                    </button>
                    <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }}
                      onClick={() => handleAction(u.id, 'reset-password')}>Resetar senha</button>
                    <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }}
                      onClick={() => handleAction(u.id, u.active ? 'deactivate' : 'reactivate')}>
                      {u.active ? 'Desativar' : 'Reativar'}
                    </button>
                    {/* can_delete vem do backend: falso para quem já assinou
                        algum atendimento ou é o último admin ativo. */}
                    {u.can_delete && u.id !== currentUser?.id && (
                      <button className="btn btn-outline"
                        style={{ minHeight: 32, padding: '4px 8px', color: 'var(--color-danger)', borderColor: 'var(--color-danger)' }}
                        onClick={() => { setDeleting(u); setEditing(null); setDeletePassword(''); setError(''); setSuccess('') }}>
                        Excluir
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <p style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)', marginTop: 10 }}>
        <strong>Excluir × Desativar:</strong> o botão “Excluir” só aparece para contas que nunca
        participaram de um atendimento. Quem já criou episódio, assinou visita ou resolveu
        pendência não pode ser excluído — apagar o usuário apagaria junto a autoria desses
        registros. Nesse caso use “Desativar”: o acesso é bloqueado e o histórico permanece.
      </p>
    </div>
  )
}

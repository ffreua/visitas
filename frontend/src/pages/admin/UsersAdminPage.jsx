import { useEffect, useState } from 'react'
import api from '../../lib/api'

export default function UsersAdminPage() {
  const [users, setUsers] = useState([])
  const [showForm, setShowForm] = useState(false)
  const [form, setForm] = useState({ full_name: '', crm: '', username: '', role: 'PHYSICIAN' })
  const [error, setError] = useState('')

  function load() {
    api.get('/admin/users').then(({ data }) => setUsers(data.data))
  }

  useEffect(() => { load() }, [])

  async function handleCreate(e) {
    e.preventDefault()
    setError('')
    try {
      await api.post('/admin/users', form)
      setForm({ full_name: '', crm: '', username: '', role: 'PHYSICIAN' })
      setShowForm(false)
      load()
    } catch (err) {
      setError(Object.values(err.response?.data?.errors || {}).flat()[0] || 'Erro ao criar usuário.')
    }
  }

  async function handleAction(userId, action) {
    if (action === 'reset-password' && !confirm('Redefinir a senha deste usuário para o padrão?')) return
    await api.post(`/admin/users/${userId}/${action}`)
    load()
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Equipe</h2>

      {!showForm ? (
        <button className="btn btn-primary" style={{ marginBottom: 12 }} onClick={() => setShowForm(true)}>+ Novo médico</button>
      ) : (
        <form className="card" onSubmit={handleCreate}>
          {error && <div className="alert alert-danger">{error}</div>}
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

      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table">
          <thead>
            <tr><th>Nome</th><th>Usuário</th><th>Perfil</th><th>Status</th><th>Ações</th></tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id}>
                <td>{u.full_name}</td>
                <td>{u.username}</td>
                <td>{u.role === 'ADMIN' ? 'Administrador' : 'Médico'}</td>
                <td>{u.active ? 'Ativo' : 'Inativo'}</td>
                <td style={{ display: 'flex', gap: 4 }}>
                  <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }}
                    onClick={() => handleAction(u.id, 'reset-password')}>Resetar senha</button>
                  <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }}
                    onClick={() => handleAction(u.id, u.active ? 'deactivate' : 'reactivate')}>
                    {u.active ? 'Desativar' : 'Reativar'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

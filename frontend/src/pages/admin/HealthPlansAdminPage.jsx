import { useEffect, useState } from 'react'
import api from '../../lib/api'

export default function HealthPlansAdminPage() {
  const [plans, setPlans] = useState([])
  const [name, setName] = useState('')
  const [error, setError] = useState('')

  function load() {
    api.get('/admin/health-plans').then(({ data }) => setPlans(data.data))
  }

  useEffect(() => { load() }, [])

  async function handleCreate(e) {
    e.preventDefault()
    setError('')
    try {
      await api.post('/admin/health-plans', { name })
      setName('')
      load()
    } catch (err) {
      setError(err.response?.data?.errors?.name?.[0] || 'Erro ao criar plano.')
    }
  }

  async function toggleActive(plan) {
    await api.put(`/admin/health-plans/${plan.id}`, { active: !plan.active })
    load()
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Planos de saúde</h2>

      <form className="card" onSubmit={handleCreate}>
        {error && <div className="alert alert-danger">{error}</div>}
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="input" placeholder="Nome do plano" value={name} onChange={(e) => setName(e.target.value)} required />
          <button type="submit" className="btn btn-primary">+ Adicionar</button>
        </div>
      </form>

      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table">
          <thead><tr><th>Nome</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            {plans.map((p) => (
              <tr key={p.id}>
                <td>{p.name}</td>
                <td>{p.active ? 'Ativo' : 'Inativo'}</td>
                <td>
                  <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }} onClick={() => toggleActive(p)}>
                    {p.active ? 'Desativar' : 'Reativar'}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p style={{ fontSize: '0.82rem', color: 'var(--color-text-muted)' }}>Planos nunca são apagados definitivamente — apenas desativados, preservando o histórico de episódios já registrados.</p>
    </div>
  )
}

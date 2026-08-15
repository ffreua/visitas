import { useEffect, useState } from 'react'
import api from '../../lib/api'

export default function MedicalSpecialtiesAdminPage() {
  const [items, setItems] = useState([])
  const [name, setName] = useState('')
  const [error, setError] = useState('')

  function load() {
    api.get('/admin/medical-specialties').then(({ data }) => setItems(data.data))
  }

  useEffect(() => { load() }, [])

  async function handleCreate(e) {
    e.preventDefault()
    setError('')
    try {
      await api.post('/admin/medical-specialties', { name })
      setName('')
      load()
    } catch (err) {
      setError(err.response?.data?.errors?.name?.[0] || 'Erro ao criar especialidade.')
    }
  }

  async function toggleActive(item) {
    await api.put(`/admin/medical-specialties/${item.id}`, { active: !item.active })
    load()
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Especialidades solicitantes</h2>

      <form className="card" onSubmit={handleCreate}>
        {error && <div className="alert alert-danger">{error}</div>}
        <div style={{ display: 'flex', gap: 8 }}>
          <input className="input" placeholder="Nome da especialidade" value={name} onChange={(e) => setName(e.target.value)} required />
          <button type="submit" className="btn btn-primary">+ Adicionar</button>
        </div>
      </form>

      <div style={{ overflowX: 'auto' }}>
        <table className="admin-table">
          <thead><tr><th>Nome</th><th>Status</th><th>Ações</th></tr></thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>{item.name}</td>
                <td>{item.active ? 'Ativo' : 'Inativo'}</td>
                <td>
                  <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 8px' }} onClick={() => toggleActive(item)}>
                    {item.active ? 'Desativar' : 'Reativar'}
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

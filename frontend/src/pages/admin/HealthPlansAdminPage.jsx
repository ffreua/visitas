import { useEffect, useRef, useState } from 'react'
import api from '../../lib/api'

export default function HealthPlansAdminPage() {
  const [plans, setPlans] = useState([])
  const [name, setName] = useState('')
  const [error, setError] = useState('')
  const [importMessage, setImportMessage] = useState('')
  const [importing, setImporting] = useState(false)
  const fileInputRef = useRef(null)

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

  async function handleImport(e) {
    const file = e.target.files?.[0]
    if (!file) return

    setImporting(true)
    setImportMessage('')
    setError('')
    try {
      const formData = new FormData()
      formData.append('file', file)
      const { data } = await api.post('/admin/health-plans/import', formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      setImportMessage(`${data.created} plano(s) novo(s) importado(s), ${data.skipped_existing} já existiam.`)
      load()
    } catch (err) {
      setError(err.response?.data?.message || 'Erro ao importar CSV.')
    } finally {
      setImporting(false)
      if (fileInputRef.current) fileInputRef.current.value = ''
    }
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

      <div className="card">
        <div className="section-title" style={{ marginTop: 0 }}>Importar planos via CSV</div>
        <p style={{ fontSize: '0.82rem', color: 'var(--color-text-muted)' }}>Arquivo com cabeçalho contendo a coluna "name". Planos já existentes (por nome) são ignorados, nunca duplicados.</p>
        {importMessage && <div className="alert alert-warning">{importMessage}</div>}
        <input ref={fileInputRef} type="file" accept=".csv,.txt" onChange={handleImport} disabled={importing} />
      </div>

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

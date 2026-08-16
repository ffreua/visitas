import { useState } from 'react'
import api from '../../lib/api'
import { basePath } from '../../lib/basePath'

export default function ExportsAdminPage() {
  const [filters, setFilters] = useState({
    date_from: '', date_to: '', care_type: '', followup_mode: '', payer_type: '', status: '',
  })
  const [pseudonymized, setPseudonymized] = useState(false)
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    setSuccess('')
    setSubmitting(true)
    try {
      const payload = { ...filters, pseudonymized }
      Object.keys(payload).forEach((k) => { if (payload[k] === '') delete payload[k] })
      if (!pseudonymized) payload.password = password

      const { data } = await api.post('/admin/exports', payload)
      setSuccess(`Exportação gerada: ${data.row_count} episódio(s). Iniciando download…`)
      setPassword('')

      // Download via navegação direta — mesma origem, cookie de sessão enviado
      // automaticamente. basePath porque em produção o app fica em /visitas.
      window.location.href = `${basePath}/api/admin/exports/${data.download_token}/download`
    } catch (err) {
      setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {}).flat()[0] || 'Erro ao gerar exportação.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Exportar dados</h2>

      <form className="card" onSubmit={handleSubmit}>
        {error && <div className="alert alert-danger">{error}</div>}
        {success && <div className="alert alert-warning">{success}</div>}

        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <div className="form-group" style={{ flex: 1, minWidth: 140 }}>
            <label>De</label>
            <input type="date" className="input" value={filters.date_from} onChange={(e) => setFilters({ ...filters, date_from: e.target.value })} />
          </div>
          <div className="form-group" style={{ flex: 1, minWidth: 140 }}>
            <label>Até</label>
            <input type="date" className="input" value={filters.date_to} onChange={(e) => setFilters({ ...filters, date_to: e.target.value })} />
          </div>
        </div>

        <div className="form-group">
          <label>Tipo de atendimento</label>
          <select className="input" value={filters.care_type} onChange={(e) => setFilters({ ...filters, care_type: e.target.value })}>
            <option value="">Todos</option>
            <option value="INSTITUTIONAL">Institucional</option>
            <option value="INTERCONSULT">Interconsulta</option>
          </select>
        </div>

        <div className="form-group">
          <label>Modalidade</label>
          <select className="input" value={filters.followup_mode} onChange={(e) => setFilters({ ...filters, followup_mode: e.target.value })}>
            <option value="">Todas</option>
            <option value="ONGOING">Acompanhamento</option>
            <option value="SINGLE_EVALUATION">Avaliação única</option>
          </select>
        </div>

        <div className="form-group">
          <label>Forma de pagamento</label>
          <select className="input" value={filters.payer_type} onChange={(e) => setFilters({ ...filters, payer_type: e.target.value })}>
            <option value="">Todas</option>
            <option value="PRIVATE">Particular</option>
            <option value="HEALTH_PLAN">Plano de saúde</option>
          </select>
        </div>

        <div className="form-group">
          <label>Status</label>
          <select className="input" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
            <option value="">Todos</option>
            <option value="ACTIVE">Ativo</option>
            <option value="CLOSED">Encerrado</option>
          </select>
        </div>

        <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: '0.9rem', marginBottom: 12 }}>
          <input type="checkbox" checked={pseudonymized} onChange={(e) => setPseudonymized(e.target.checked)} />
          Exportar sem identificação direta (substitui nome/prontuário por código)
        </label>

        {!pseudonymized && (
          <div className="form-group">
            <label>Confirme sua senha (exportação identificável exige reautenticação)</label>
            <input type="password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </div>
        )}

        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          {submitting ? 'Gerando…' : 'Gerar e baixar XLSX'}
        </button>
      </form>
    </div>
  )
}

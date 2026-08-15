import { useEffect, useState } from 'react'
import api from '../../lib/api'
import { formatDateTime } from '../../lib/format'

export default function TrashedAdminPage() {
  const [items, setItems] = useState([])
  const [forceTarget, setForceTarget] = useState(null)
  const [password, setPassword] = useState('')
  const [reason, setReason] = useState('')
  const [phrase, setPhrase] = useState('')
  const [error, setError] = useState('')

  function load() {
    api.get('/admissions/trashed').then(({ data }) => setItems(data.data))
  }

  useEffect(() => { load() }, [])

  async function handleRestore(id) {
    if (!confirm('Restaurar este atendimento para a lista assistencial?')) return
    await api.post(`/admissions/${id}/restore`)
    load()
  }

  async function handleForceDelete(e) {
    e.preventDefault()
    setError('')
    try {
      await api.delete(`/admissions/${forceTarget}/force`, {
        data: { password, reason, confirmation_phrase: phrase },
      })
      setForceTarget(null)
      setPassword('')
      setReason('')
      setPhrase('')
      load()
    } catch (err) {
      setError(err.response?.data?.message || Object.values(err.response?.data?.errors || {}).flat()[0] || 'Erro ao excluir definitivamente.')
    }
  }

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Registros excluídos</h2>

      {items.length === 0 ? (
        <div className="empty-state">Nenhum registro excluído.</div>
      ) : (
        items.map((a) => (
          <div className="card" key={a.id}>
            <div style={{ fontWeight: 700 }}>{a.patient?.full_name}</div>
            <div className="meta" style={{ color: 'var(--color-text-muted)', fontSize: '0.85rem' }}>
              Prontuário {a.patient?.medical_record_number} · Atendimento em {formatDateTime(a.admission_at)}
            </div>
            <div className="meta" style={{ color: 'var(--color-text-muted)', fontSize: '0.85rem' }}>
              Excluído por {a.deleter?.full_name || '—'} em {formatDateTime(a.deleted_at)}
            </div>
            <div style={{ marginTop: 4 }}>Motivo: {a.deletion_reason}</div>

            <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
              <button className="btn btn-outline" onClick={() => handleRestore(a.id)}>Restaurar</button>
              <button className="btn btn-danger" onClick={() => { setForceTarget(a.id); setError('') }}>Excluir definitivamente</button>
            </div>

            {forceTarget === a.id && (
              <form onSubmit={handleForceDelete} className="card" style={{ background: 'var(--color-bg)', marginTop: 10 }}>
                {error && <div className="alert alert-danger">{error}</div>}
                <div className="alert alert-danger">Esta ação é irreversível e remove o registro definitivamente do banco.</div>
                <div className="form-group">
                  <label>Sua senha (reautenticação)</label>
                  <input type="password" className="input" value={password} onChange={(e) => setPassword(e.target.value)} required />
                </div>
                <div className="form-group">
                  <label>Motivo</label>
                  <input className="input" value={reason} onChange={(e) => setReason(e.target.value)} required />
                </div>
                <div className="form-group">
                  <label>Digite: EXCLUIR DEFINITIVAMENTE</label>
                  <input className="input" value={phrase} onChange={(e) => setPhrase(e.target.value)} required />
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button type="submit" className="btn btn-danger">Confirmar exclusão definitiva</button>
                  <button type="button" className="btn btn-outline" onClick={() => setForceTarget(null)}>Cancelar</button>
                </div>
              </form>
            )}
          </div>
        ))
      )}
    </div>
  )
}

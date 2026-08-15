import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api'
import { formatDate } from '../lib/format'

export default function ClosedListPage() {
  const [admissions, setAdmissions] = useState([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/admissions/closed').then(({ data }) => {
      setAdmissions(data.data)
      setLoading(false)
    })
  }, [])

  return (
    <div>
      <h2 className="section-title" style={{ marginTop: 0 }}>Altas / Histórico</h2>

      {loading ? (
        <div className="empty-state">Carregando…</div>
      ) : admissions.length === 0 ? (
        <div className="empty-state">Nenhum atendimento encerrado ainda.</div>
      ) : (
        admissions.map((a) => {
          const finalDx = (a.diagnoses || []).find((d) => d.phase === 'FINAL' && d.is_primary)
          return (
            <Link to={`/atendimentos/${a.id}`} key={a.id} className="timeline-entry" style={{ display: 'block', textDecoration: 'none', color: 'inherit' }}>
              <div style={{ fontWeight: 700 }}>{a.patient?.full_name}</div>
              <div className="meta" style={{ color: 'var(--color-text-muted)', fontSize: '0.85rem' }}>
                Prontuário {a.patient?.medical_record_number} · Encerrado em {formatDate(a.neurology_followup_closed_at)}
              </div>
              {finalDx && <div style={{ fontSize: '0.9rem' }}>{finalDx.cid_code} — {finalDx.description_snapshot}</div>}
            </Link>
          )
        })
      )}
    </div>
  )
}

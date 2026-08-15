import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api'
import AdmissionCard from '../components/AdmissionCard'

const FILTERS = [
  { key: 'all', label: 'Todos', params: {} },
  { key: 'institutional', label: 'Institucionais', params: { care_type: 'INSTITUTIONAL' } },
  { key: 'interconsult', label: 'Interconsultas', params: { care_type: 'INTERCONSULT' } },
  { key: 'single', label: 'Avaliação única', params: { followup_mode: 'SINGLE_EVALUATION' } },
  { key: 'ongoing', label: 'Acompanhamento', params: { followup_mode: 'ONGOING' } },
  { key: 'unassigned', label: 'Sem responsável', params: { unassigned_today: 1 } },
  { key: 'not_visited', label: 'Não visitados', params: { not_visited_today: 1 } },
  { key: 'pending', label: 'Com pendências', params: { with_pending: 1 } },
]

export default function DashboardPage() {
  const [activeFilter, setActiveFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [admissions, setAdmissions] = useState([])
  const [total, setTotal] = useState(null)
  const [loading, setLoading] = useState(true)
  const debounceRef = useRef(null)

  useEffect(() => {
    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeFilter])

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current)
    debounceRef.current = setTimeout(load, 300)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  async function load() {
    setLoading(true)
    try {
      const filter = FILTERS.find((f) => f.key === activeFilter)
      const { data } = await api.get('/admissions', { params: { ...filter.params, search: search || undefined } })
      setAdmissions(data.data)
      setTotal(data.total)
    } finally {
      setLoading(false)
    }
  }

  const today = new Date().toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' })

  return (
    <div>
      <div style={{ marginBottom: 12 }}>
        <div style={{ fontSize: '0.85rem', color: 'var(--color-text-muted)' }}>{today}</div>
        {total !== null && <div style={{ fontSize: '1.1rem', fontWeight: 700 }}>{total} caso{total === 1 ? '' : 's'} ativo{total === 1 ? '' : 's'}</div>}
      </div>

      <input
        className="input"
        placeholder="Buscar por nome ou prontuário"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        style={{ marginBottom: 12 }}
      />

      <div className="filter-bar">
        {FILTERS.map((f) => (
          <button
            key={f.key}
            className={`filter-chip ${activeFilter === f.key ? 'active' : ''}`}
            onClick={() => setActiveFilter(f.key)}
          >
            {f.label}
          </button>
        ))}
      </div>

      <Link to="/novo" className="btn btn-primary btn-block" style={{ marginBottom: 12 }}>
        + Novo atendimento
      </Link>

      {loading ? (
        <div className="empty-state">Carregando…</div>
      ) : admissions.length === 0 ? (
        <div className="empty-state">Nenhum caso encontrado com esse filtro.</div>
      ) : (
        admissions.map((admission) => <AdmissionCard key={admission.id} admission={admission} />)
      )}
    </div>
  )
}

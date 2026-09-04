import { useEffect, useState } from 'react'
import api from '../../lib/api'
import Autocomplete from '../../components/Autocomplete'
import {
  BarList, CoverageGauge, Delta, DurationSummary, MonthlySeriesChart, formatNumber,
} from '../../components/DashboardCharts'

const EMPTY_FILTERS = {
  date_from: '',
  date_to: '',
  period_mode: 'OVERLAP',
  care_type: '',
  followup_mode: '',
  payer_type: '',
  status: '',
  health_plan_id: '',
  requesting_specialty_id: '',
  physician_id: '',
  cid_code: '',
}

function iso(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/**
 * Atalhos de período. Sem eles, ver "o mês passado" exigia calcular e digitar
 * duas datas — atrito suficiente para ninguém olhar o indicador.
 */
const PRESETS = [
  {
    key: 'this_month',
    label: 'Este mês',
    range: () => {
      const now = new Date()
      return [iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)]
    },
  },
  {
    key: 'last_month',
    label: 'Mês passado',
    range: () => {
      const now = new Date()
      return [
        iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)),
        iso(new Date(now.getFullYear(), now.getMonth(), 0)),
      ]
    },
  },
  {
    key: 'last_90',
    label: '90 dias',
    range: () => {
      const now = new Date()
      const from = new Date(now)
      from.setDate(from.getDate() - 89)
      return [iso(from), iso(now)]
    },
  },
  {
    key: 'year',
    label: 'Este ano',
    range: () => {
      const now = new Date()
      return [iso(new Date(now.getFullYear(), 0, 1)), iso(now)]
    },
  },
  { key: 'all', label: 'Tudo', range: () => ['', ''] },
]

function Kpi({ label, value, previous, suffix = '', digits = 0, colored = false, hint }) {
  const numeric = typeof value === 'number'

  return (
    <div className="kpi">
      <div className="kpi-label">{label}</div>
      <div className="kpi-value">{numeric ? formatNumber(value, digits) : value}{suffix}</div>
      <div className="kpi-foot">
        <Delta current={numeric ? value : null} previous={previous} suffix={suffix} colored={colored} />
        {hint && <span className="kpi-hint">{hint}</span>}
      </div>
    </div>
  )
}

function Card({ title, subtitle, children, right }) {
  return (
    <div className="card dash-card">
      <div className="dash-card-head">
        <div>
          <div className="section-title" style={{ margin: 0 }}>{title}</div>
          {subtitle && <div className="dash-card-sub">{subtitle}</div>}
        </div>
        {right}
      </div>
      {children}
    </div>
  )
}

function formatBR(isoDate) {
  if (!isoDate) return null
  const [y, m, d] = isoDate.slice(0, 10).split('-')
  return `${d}/${m}/${y}`
}

export default function DashboardAdminPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [labels, setLabels] = useState({ plan: '', specialty: '', cid: '' })
  const [includeDeleted, setIncludeDeleted] = useState(false)
  const [showAdvanced, setShowAdvanced] = useState(false)
  const [physicians, setPhysicians] = useState([])

  const [data, setData] = useState(null)
  const [quality, setQuality] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    api.get('/physicians').then(({ data: list }) => setPhysicians(list)).catch(() => setPhysicians([]))
  }, [])

  async function load(next = filters, deleted = includeDeleted) {
    setLoading(true)
    setError('')
    try {
      const params = { include_deleted: deleted ? 1 : undefined }
      Object.entries(next).forEach(([key, value]) => {
        if (value !== '' && value !== null && value !== undefined) params[key] = value
      })

      const [{ data: dashboard }, { data: dq }] = await Promise.all([
        api.get('/admin/dashboard', { params }),
        api.get('/admin/dashboard/data-quality'),
      ])
      setData(dashboard)
      setQuality(dq)
    } catch (err) {
      setError(
        err.response?.data?.message
        || Object.values(err.response?.data?.errors || {}).flat()[0]
        || 'Não foi possível carregar os indicadores.'
      )
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { load() }, []) // eslint-disable-line react-hooks/exhaustive-deps

  function applyPreset(preset) {
    const [from, to] = preset.range()
    const next = { ...filters, date_from: from, date_to: to }
    setFilters(next)
    load(next)
  }

  function clearAll() {
    setFilters(EMPTY_FILTERS)
    setLabels({ plan: '', specialty: '', cid: '' })
    setIncludeDeleted(false)
    load(EMPTY_FILTERS, false)
  }

  if (loading && !data) return <div className="empty-state">Carregando indicadores…</div>
  if (error && !data) return <div className="alert alert-danger">{error}</div>
  if (!data) return null

  const { volume, previous_period: prev, period } = data
  const coverage = data.visit_coverage
  const activeExtraFilters = Object.entries(filters)
    .filter(([key, value]) => value !== '' && !['date_from', 'date_to', 'period_mode'].includes(key)).length

  const periodLabel = period.from
    ? `${formatBR(period.from)} a ${formatBR(period.to)} · ${period.days} dia${period.days === 1 ? '' : 's'}`
    : `Tudo até ${formatBR(period.to)}`

  return (
    <div className="dashboard">
      <div className="dash-header">
        <div>
          <h2 className="section-title" style={{ margin: 0 }}>Dashboard — Indicadores</h2>
          <div className="dash-context">
            {periodLabel}
            {' · '}
            {period.mode === 'OVERLAP' ? 'carga assistencial no período' : 'entradas no período'}
            {prev && ` · comparado a ${formatBR(prev.from)}–${formatBR(prev.to)}`}
          </div>
        </div>
        <button type="button" className="btn btn-outline no-print" onClick={() => window.print()}>
          🖨️ Imprimir
        </button>
      </div>

      {/* ---------------- Filtros ---------------- */}
      <form
        className="card no-print"
        onSubmit={(e) => { e.preventDefault(); load() }}
      >
        <div className="preset-row">
          {PRESETS.map((p) => (
            <button key={p.key} type="button" className="filter-chip" onClick={() => applyPreset(p)}>
              {p.label}
            </button>
          ))}
        </div>

        <div className="filter-grid">
          <div className="form-group">
            <label>De</label>
            <input type="date" className="input" value={filters.date_from}
              onChange={(e) => setFilters({ ...filters, date_from: e.target.value })} />
          </div>
          <div className="form-group">
            <label>Até</label>
            <input type="date" className="input" value={filters.date_to}
              onChange={(e) => setFilters({ ...filters, date_to: e.target.value })} />
          </div>
          <div className="form-group">
            <label>Contar</label>
            <select className="input" value={filters.period_mode}
              onChange={(e) => setFilters({ ...filters, period_mode: e.target.value })}>
              <option value="OVERLAP">Quem esteve sob acompanhamento</option>
              <option value="ADMISSION">Quem entrou no período</option>
            </select>
          </div>
        </div>

        <button type="button" className="link-button" onClick={() => setShowAdvanced((v) => !v)}>
          {showAdvanced ? '▾' : '▸'} Mais filtros{activeExtraFilters > 0 ? ` (${activeExtraFilters} ativo${activeExtraFilters > 1 ? 's' : ''})` : ''}
        </button>

        {showAdvanced && (
          <div className="filter-grid" style={{ marginTop: 8 }}>
            <div className="form-group">
              <label>Tipo de atendimento</label>
              <select className="input" value={filters.care_type}
                onChange={(e) => setFilters({ ...filters, care_type: e.target.value })}>
                <option value="">Todos</option>
                <option value="INSTITUTIONAL">Institucional</option>
                <option value="INTERCONSULT">Interconsulta</option>
              </select>
            </div>
            <div className="form-group">
              <label>Modalidade</label>
              <select className="input" value={filters.followup_mode}
                onChange={(e) => setFilters({ ...filters, followup_mode: e.target.value })}>
                <option value="">Todas</option>
                <option value="ONGOING">Acompanhamento</option>
                <option value="SINGLE_EVALUATION">Avaliação única</option>
              </select>
            </div>
            <div className="form-group">
              <label>Pagador</label>
              <select className="input" value={filters.payer_type}
                onChange={(e) => setFilters({ ...filters, payer_type: e.target.value })}>
                <option value="">Todos</option>
                <option value="PRIVATE">Particular</option>
                <option value="HEALTH_PLAN">Plano de saúde</option>
              </select>
            </div>
            <div className="form-group">
              <label>Situação</label>
              <select className="input" value={filters.status}
                onChange={(e) => setFilters({ ...filters, status: e.target.value })}>
                <option value="">Todas</option>
                <option value="ACTIVE">Ativos</option>
                <option value="CLOSED">Encerrados</option>
              </select>
            </div>
            <div className="form-group">
              <label>Médico</label>
              <select className="input" value={filters.physician_id}
                onChange={(e) => setFilters({ ...filters, physician_id: e.target.value })}>
                <option value="">Todos</option>
                {physicians.map((p) => <option key={p.id} value={p.id}>{p.full_name}</option>)}
              </select>
            </div>
            <div className="form-group">
              <label>Plano de saúde</label>
              <Autocomplete
                searchUrl="/health-plans/search"
                placeholder="Digite 2 letras…"
                initialLabel={labels.plan}
                onSelect={(opt) => {
                  setFilters((f) => ({ ...f, health_plan_id: opt?.id || '' }))
                  setLabels((l) => ({ ...l, plan: opt?.name || '' }))
                }}
              />
            </div>
            <div className="form-group">
              <label>Especialidade solicitante</label>
              <Autocomplete
                searchUrl="/medical-specialties/search"
                placeholder="Digite 2 letras…"
                initialLabel={labels.specialty}
                onSelect={(opt) => {
                  setFilters((f) => ({ ...f, requesting_specialty_id: opt?.id || '' }))
                  setLabels((l) => ({ ...l, specialty: opt?.name || '' }))
                }}
              />
            </div>
            <div className="form-group">
              <label>CID-10</label>
              <Autocomplete
                searchUrl="/cid10/search"
                valueKey="code"
                labelKey="description"
                placeholder="Digite 2 letras…"
                initialLabel={labels.cid}
                renderOption={(opt) => `${opt.code} — ${opt.description}`}
                onSelect={(opt) => {
                  setFilters((f) => ({ ...f, cid_code: opt?.code || '' }))
                  setLabels((l) => ({ ...l, cid: opt ? `${opt.code} — ${opt.description}` : '' }))
                }}
              />
            </div>
          </div>
        )}

        <label className="checkbox-line">
          <input type="checkbox" checked={includeDeleted}
            onChange={(e) => { setIncludeDeleted(e.target.checked); load(filters, e.target.checked) }} />
          Incluir excluídos (auditoria)
        </label>

        {error && <div className="alert alert-danger" style={{ marginTop: 8 }}>{error}</div>}

        <div style={{ display: 'flex', gap: 8, marginTop: 8, flexWrap: 'wrap' }}>
          <button type="submit" className="btn btn-primary" disabled={loading}>
            {loading ? 'Calculando…' : 'Aplicar filtros'}
          </button>
          <button type="button" className="btn btn-outline" onClick={clearAll} disabled={loading}>Limpar</button>
        </div>
      </form>

      <div className={loading ? 'dash-body is-loading' : 'dash-body'}>
        {/* ---------------- KPIs ---------------- */}
        <div className="kpi-grid">
          <Kpi label="Episódios no período" value={volume.episodes} previous={prev?.episodes} />
          <Kpi label="Pacientes únicos" value={volume.unique_patients} previous={prev?.unique_patients} />
          <Kpi label="Entradas novas" value={volume.new_admissions} previous={prev?.new_admissions} />
          <Kpi label="Encerramentos Neurologia" value={volume.neurology_closures} previous={prev?.neurology_closures} />
          <Kpi label="Patient-days" value={volume.neurology_patient_days} previous={prev?.neurology_patient_days} />
          <Kpi
            label="Cobertura de visita"
            value={coverage.coverage_pct ?? '—'}
            digits={1}
            suffix={coverage.coverage_pct === null ? '' : '%'}
            previous={prev?.coverage_pct}
            colored
          />
        </div>

        {/* ---------------- Hoje ---------------- */}
        <div className="today-strip">
          <div className="today-title">Agora — plantão de hoje <span>não segue o filtro de período</span></div>
          <div className="today-items">
            <span><strong>{data.today.active_cases}</strong> casos ativos</span>
            <span className={data.today.unassigned > 0 ? 'today-alert' : ''}>
              <strong>{data.today.unassigned}</strong> sem responsável
            </span>
            <span className={data.today.not_visited > 0 ? 'today-alert' : ''}>
              <strong>{data.today.not_visited}</strong> não visitados
            </span>
          </div>
        </div>

        <Card title="Evolução mensal" subtitle="Volume de episódios e cobertura de visita, mês a mês">
          <MonthlySeriesChart series={data.monthly_series} />
        </Card>

        <Card
          title="Cobertura de visita diária"
          subtitle="Dias de calendário sob acompanhamento no período em que a visita foi assinada"
        >
          <div className="coverage-layout">
            <CoverageGauge
              pct={coverage.coverage_pct}
              visited={coverage.visited_patient_days}
              expected={coverage.expected_patient_days}
            />
            <div className="coverage-detail">
              <div><strong>{formatNumber(coverage.expected_patient_days)}</strong> patient-days sob acompanhamento</div>
              <div><strong>{formatNumber(coverage.visited_patient_days)}</strong> com visita assinada</div>
              <div><strong>{formatNumber(coverage.assigned_patient_days)}</strong> com responsável definido</div>
              <div className="dash-note">
                Cada dia sob acompanhamento conta como uma oportunidade de visita, tenha havido
                registro naquele dia ou não.
              </div>
            </div>
          </div>
        </Card>

        <Card title="Tempo de permanência" subtitle="Episódios cujo evento ocorreu dentro do período">
          <div className="metric-line">
            <span>Internação hospitalar</span>
            <DurationSummary summary={data.length_of_stay.hospital_los_days} />
          </div>
          <div className="metric-line">
            <span>Acompanhamento da Neurologia (encerrados)</span>
            <DurationSummary summary={data.length_of_stay.neurology_followup_days} />
          </div>
          <div className="metric-line">
            <span>Em curso — tempo já acumulado</span>
            <DurationSummary summary={data.length_of_stay.active_days_so_far} />
          </div>
          <div className="dash-note">
            Altas hospitalares registradas no período: {volume.hospital_discharges}. A alta hospitalar
            é opcional no encerramento, então a amostra dela costuma ser menor que a de encerramentos.
          </div>
        </Card>

        <Card title="Procedência" subtitle="Vocabulário fechado — categoria sem caso aparece como zero">
          <BarList
            items={data.origins.by_origin.map((o) => ({ label: o.label, value: o.episodes, alwaysShow: true }))}
          />
          {data.origins.not_informed > 0 && (
            <div className="dash-note">
              {data.origins.not_informed} episódio(s) sem procedência — cadastrados antes de o campo existir.
            </div>
          )}
        </Card>

        <Card title="Pagador" subtitle={`Particular ${data.payers.private_vs_plan.PRIVATE} · Plano ${data.payers.private_vs_plan.HEALTH_PLAN}${data.payers.private_vs_plan.NOT_INFORMED > 0 ? ` · Sem definição ${data.payers.private_vs_plan.NOT_INFORMED}` : ''}`}>
          <BarList items={data.payers.by_plan.map((p) => ({ label: p.plan, value: p.episodes }))} />
          {data.payers.by_plan.length > 0 && (
            <div className="table-scroll">
              <table className="admin-table" style={{ marginTop: 10 }}>
                <thead><tr><th>Plano</th><th>Episódios</th><th>Patient-days</th><th>Mediana acomp.</th></tr></thead>
                <tbody>
                  {data.payers.by_plan.map((p) => (
                    <tr key={p.plan}>
                      <td>{p.plan}</td>
                      <td>{p.episodes}</td>
                      <td>{formatNumber(p.patient_days)}</td>
                      <td>{p.median_followup_days === null ? '—' : `${formatNumber(p.median_followup_days, 1)} dias`}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card title="Interconsultas" subtitle={`${data.interconsults.count} no período`}>
          <div className="metric-line">
            <span>Solicitação → 1ª avaliação</span>
            <DurationSummary summary={data.interconsults.response_time_days} />
          </div>
          {data.interconsults.awaiting_first_evaluation > 0 && (
            <div className="dash-note">
              {data.interconsults.awaiting_first_evaluation} interconsulta(s) ainda sem 1ª avaliação
              assinada — não entram na mediana acima.
            </div>
          )}
          <BarList
            items={data.interconsults.by_specialty.map((s) => ({ label: s.specialty, value: s.count }))}
            emptyLabel="Nenhuma interconsulta no período."
          />
        </Card>

        <Card title="Diagnósticos" subtitle="Hipótese principal na entrada × diagnóstico final no encerramento">
          <div className="two-col">
            <div>
              <div className="col-title">Top hipóteses</div>
              <BarList
                items={data.diagnoses.top_suspected.map((d) => ({
                  label: `${d.cid_code} — ${d.description}`, value: d.count, title: d.description,
                }))}
              />
            </div>
            <div>
              <div className="col-title">Top diagnósticos finais</div>
              <BarList
                items={data.diagnoses.top_final.map((d) => ({
                  label: `${d.cid_code} — ${d.description}`, value: d.count, title: d.description,
                }))}
                emptyLabel="Nenhum encerramento com CID final no período."
              />
            </div>
          </div>
          <div className="dash-note">
            Concordância entre hipótese e final ({data.diagnostic_agreement.evaluated} episódio(s) avaliáveis):{' '}
            <strong>{data.diagnostic_agreement.concordant}</strong> concordantes ·{' '}
            <strong>{data.diagnostic_agreement.changed}</strong> com mudança
            {data.diagnostic_agreement.changed_same_category > 0
              && ` (${data.diagnostic_agreement.changed_same_category} apenas de código dentro da mesma categoria CID)`}
            {data.diagnostic_agreement.undetermined > 0
              && ` · ${data.diagnostic_agreement.undetermined} sem os dois diagnósticos registrados`}.
          </div>
        </Card>

        <Card title="Reinternações" subtitle="Reentradas ocorridas no período">
          <div className="metric-line"><span>Até 7 dias do encerramento anterior</span><strong>{data.readmissions.within_7_days}</strong></div>
          <div className="metric-line"><span>Até 30 dias (inclui as de até 7)</span><strong>{data.readmissions.within_30_days}</strong></div>
          <div className="metric-line"><span>Reentradas analisadas</span><strong>{data.readmissions.transitions}</strong></div>
          <div className="dash-note">{data.readmissions.note}</div>
        </Card>

        <Card title="Pendências" subtitle={`${data.pending_items.created} criadas no conjunto do período`}>
          <div className="metric-line"><span>Abertas</span><strong>{data.pending_items.open}</strong></div>
          <div className="metric-line"><span>Resolvidas</span><strong>{data.pending_items.resolved}</strong></div>
          <div className="metric-line">
            <span>Tempo até resolver</span>
            <DurationSummary summary={data.pending_items.resolution_days} />
          </div>
          <div className="metric-line">
            <span>Abertas no momento do encerramento</span>
            <strong className={data.pending_items.open_at_closure > 0 ? 'value-warn' : ''}>
              {data.pending_items.open_at_closure}
            </strong>
          </div>
        </Card>

        <Card title="Avaliações únicas" subtitle={`${data.single_evaluations.count} no período`}>
          <div className="metric-line">
            <span>Tempo de resposta</span>
            <DurationSummary summary={data.single_evaluations.response_time_days} />
          </div>
          <div className="metric-line">
            <span>Concluídas no mesmo dia da solicitação</span>
            <strong>
              {data.single_evaluations.same_day_pct === null
                ? '—'
                : `${formatNumber(data.single_evaluations.same_day_pct, 1)}% (de ${data.single_evaluations.same_day_base})`}
            </strong>
          </div>
          <BarList
            items={data.single_evaluations.by_specialty.map((s) => ({ label: s.specialty, value: s.count }))}
            emptyLabel="Nenhuma avaliação única no período."
          />
        </Card>

        <Card title="Médicos — cobertura operacional" subtitle="Visitas assinadas dentro do período">
          {data.physicians.by_physician.length === 0 ? (
            <div className="chart-empty">Nenhuma visita assinada no período.</div>
          ) : (
            <div className="table-scroll">
              <table className="admin-table">
                <thead>
                  <tr>
                    <th>Médico</th><th>Visitas</th><th>Episódios</th><th>Pacientes</th>
                    <th>1ªs avaliações</th><th>Aval. únicas</th>
                  </tr>
                </thead>
                <tbody>
                  {data.physicians.by_physician.map((p) => (
                    <tr key={p.physician}>
                      <td>{p.physician}</td>
                      <td>{p.rounds}</td>
                      <td>{p.episodes}</td>
                      <td>{p.unique_patients}</td>
                      <td>{p.first_evaluations}</td>
                      <td>{p.single_evaluations}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <div className="dash-note">
            "1ªs avaliações" conta os episódios cuja <em>primeira</em> visita foi assinada por
            aquele médico. "Pacientes" é pessoa: duas internações do mesmo paciente contam uma vez.
          </div>
        </Card>

        {quality && <QualityPanel quality={quality} />}
      </div>
    </div>
  )
}

const QUALITY_ITEMS = [
  ['without_responsible_today', 'Ativos sem responsável hoje'],
  ['not_visited_today', 'Ativos não visitados hoje'],
  ['active_without_suspected_diagnosis', 'Ativos sem hipótese diagnóstica'],
  ['discharges_without_final_diagnosis', 'Encerrados sem diagnóstico final'],
  ['interconsults_without_specialty', 'Interconsultas sem especialidade solicitante'],
  ['interconsults_without_request_time', 'Interconsultas sem horário de solicitação'],
  ['without_origin', 'Ativos sem procedência'],
  ['without_payer_defined', 'Ativos sem pagador definido'],
  ['single_evaluations_open_over_3_days', 'Avaliações únicas abertas há mais de 3 dias'],
  ['pending_items_open_over_14_days', 'Pendências abertas há mais de 14 dias'],
  ['admissions_over_30_days', 'Internações há mais de 30 dias'],
]

/**
 * Só o que precisa de ação. Uma lista de onze zeros treina o olho a não ler o
 * painel — e é justamente aqui que o item diferente de zero precisa saltar.
 */
function QualityPanel({ quality }) {
  const pending = QUALITY_ITEMS
    .map(([key, label]) => ({ key, label, count: quality[key] ?? 0 }))
    .filter((item) => item.count > 0)

  return (
    <Card title="Qualidade dos dados" subtitle="Situação de agora — não segue o filtro de período">
      {pending.length === 0 ? (
        <div className="quality-ok">✓ Nenhuma pendência de cadastro. Tudo em ordem.</div>
      ) : (
        <ul className="quality-list">
          {pending.map((item) => (
            <li key={item.key}>
              <span className="quality-count">{item.count}</span>
              <span>{item.label}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  )
}

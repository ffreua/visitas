/**
 * Gráficos do dashboard — SVG e CSS puros, sem biblioteca.
 *
 * Uma lib de gráficos custaria ~100 KB no bundle de um PWA que a equipe abre
 * no celular, dentro do hospital, para ler meia dúzia de séries. O que estas
 * telas precisam (barra, coluna, arco) cabe em SVG escrito à mão e herda os
 * tokens de cor do app sem tema paralelo.
 */

const COLORS = {
  primary: '#0b5d8f',
  primarySoft: '#7fb3d3',
  accent: '#0e7490',
  good: '#16a34a',
  warn: '#d97706',
  bad: '#dc2626',
  grid: '#e2e8f0',
  muted: '#64748b',
}

/** Cobertura tem leitura clínica: abaixo de 70% é problema, não estilo. */
function coverageTone(pct) {
  if (pct === null || pct === undefined) return COLORS.muted
  if (pct >= 90) return COLORS.good
  if (pct >= 70) return COLORS.warn
  return COLORS.bad
}

export function formatNumber(value, digits = 0) {
  if (value === null || value === undefined) return '—'
  return Number(value).toLocaleString('pt-BR', { minimumFractionDigits: digits, maximumFractionDigits: digits })
}

/**
 * Lista de barras horizontais para categorias (procedência, plano,
 * especialidade, CID). Barra em div: acompanha a largura do card sozinha e
 * quebra melhor no celular que um SVG de largura fixa.
 */
export function BarList({ items, emptyLabel = 'Sem dados no período', valueSuffix = '' }) {
  const rows = (items || []).filter((i) => i.value > 0 || i.alwaysShow)

  if (rows.length === 0) {
    return <div className="chart-empty">{emptyLabel}</div>
  }

  const max = Math.max(...rows.map((r) => r.value), 1)

  return (
    <div className="bar-list">
      {rows.map((row) => (
        <div className="bar-row" key={row.label}>
          <div className="bar-row-head">
            <span className="bar-row-label" title={row.title || row.label}>{row.label}</span>
            <span className="bar-row-value">{formatNumber(row.value)}{valueSuffix}</span>
          </div>
          <div className="bar-track">
            <div
              className="bar-fill"
              style={{ width: `${(row.value / max) * 100}%`, background: row.color || COLORS.primary }}
            />
          </div>
        </div>
      ))}
    </div>
  )
}

/**
 * Série mensal: colunas de episódios com a linha de cobertura por cima.
 * As duas juntas respondem a pergunta que importa — "o volume subiu e a
 * cobertura acompanhou?" — que dois gráficos separados não respondem.
 */
export function MonthlySeriesChart({ series }) {
  const data = series || []

  if (data.length === 0) {
    return <div className="chart-empty">Sem meses no período selecionado.</div>
  }

  const colWidth = 58
  const padLeft = 34
  const padRight = 34
  const padTop = 14
  const plotHeight = 150
  const labelHeight = 26
  const width = padLeft + padRight + data.length * colWidth
  const height = padTop + plotHeight + labelHeight

  const maxEpisodes = Math.max(...data.map((d) => d.episodes), 1)
  const barWidth = Math.min(30, colWidth - 16)

  const x = (i) => padLeft + i * colWidth + colWidth / 2
  const yEpisodes = (v) => padTop + plotHeight - (v / maxEpisodes) * plotHeight
  const yCoverage = (v) => padTop + plotHeight - (v / 100) * plotHeight

  const coveragePoints = data
    .map((d, i) => (d.coverage_pct === null ? null : `${x(i)},${yCoverage(d.coverage_pct)}`))
    .filter(Boolean)
    .join(' ')

  return (
    <div className="chart-scroll">
      <svg
        className="monthly-chart"
        viewBox={`0 0 ${width} ${height}`}
        style={{ width: Math.max(width, 320), maxWidth: '100%', height: 'auto' }}
        role="img"
        aria-label="Episódios e cobertura de visita por mês"
      >
        {[0, 0.5, 1].map((f) => (
          <g key={f}>
            <line
              x1={padLeft - 6} x2={width - padRight + 6}
              y1={padTop + plotHeight - f * plotHeight} y2={padTop + plotHeight - f * plotHeight}
              stroke={COLORS.grid} strokeWidth="1"
            />
            <text x={4} y={padTop + plotHeight - f * plotHeight + 3} fontSize="9" fill={COLORS.muted}>
              {Math.round(f * maxEpisodes)}
            </text>
            <text x={width - padRight + 10} y={padTop + plotHeight - f * plotHeight + 3} fontSize="9" fill={COLORS.accent}>
              {Math.round(f * 100)}%
            </text>
          </g>
        ))}

        {data.map((d, i) => (
          <rect
            key={d.month}
            x={x(i) - barWidth / 2}
            y={yEpisodes(d.episodes)}
            width={barWidth}
            height={Math.max(padTop + plotHeight - yEpisodes(d.episodes), d.episodes > 0 ? 2 : 0)}
            rx="2"
            fill={COLORS.primarySoft}
          >
            <title>{`${d.label}: ${d.episodes} episódio(s), ${d.new_admissions} entrada(s), ${d.neurology_closures} encerramento(s)`}</title>
          </rect>
        ))}

        {coveragePoints && (
          <polyline points={coveragePoints} fill="none" stroke={COLORS.accent} strokeWidth="2" strokeLinejoin="round" />
        )}

        {data.map((d, i) => (
          d.coverage_pct === null ? null : (
            <circle key={`c-${d.month}`} cx={x(i)} cy={yCoverage(d.coverage_pct)} r="3" fill={COLORS.accent}>
              <title>{`${d.label}: cobertura ${d.coverage_pct}%`}</title>
            </circle>
          )
        ))}

        {data.map((d, i) => (
          <text key={`l-${d.month}`} x={x(i)} y={height - 8} fontSize="9.5" fill={COLORS.muted} textAnchor="middle">
            {d.label}
          </text>
        ))}
      </svg>

      <div className="chart-legend">
        <span><i style={{ background: COLORS.primarySoft }} /> Episódios no mês</span>
        <span><i style={{ background: COLORS.accent }} /> Cobertura de visita (%)</span>
      </div>
    </div>
  )
}

/**
 * Arco de cobertura. O número sozinho não diz se é bom; a cor diz, e o
 * denominador embaixo impede a leitura de "100%" sobre 2 patient-days.
 */
export function CoverageGauge({ pct, visited, expected }) {
  const size = 132
  const stroke = 13
  const radius = (size - stroke) / 2
  const circumference = Math.PI * radius // meia volta
  const value = pct === null || pct === undefined ? 0 : Math.max(0, Math.min(100, pct))
  const tone = coverageTone(pct)

  return (
    <div className="gauge">
      <svg viewBox={`0 0 ${size} ${size / 2 + 12}`} style={{ width: size, maxWidth: '100%' }} role="img"
        aria-label={`Cobertura de visita ${pct === null ? 'sem dados' : `${pct}%`}`}>
        <path
          d={`M ${stroke / 2} ${size / 2} A ${radius} ${radius} 0 0 1 ${size - stroke / 2} ${size / 2}`}
          fill="none" stroke={COLORS.grid} strokeWidth={stroke} strokeLinecap="round"
        />
        <path
          d={`M ${stroke / 2} ${size / 2} A ${radius} ${radius} 0 0 1 ${size - stroke / 2} ${size / 2}`}
          fill="none" stroke={tone} strokeWidth={stroke} strokeLinecap="round"
          strokeDasharray={`${(value / 100) * circumference} ${circumference}`}
        />
        <text x={size / 2} y={size / 2 - 6} textAnchor="middle" fontSize="26" fontWeight="800" fill={tone}>
          {pct === null || pct === undefined ? '—' : `${formatNumber(pct, 1)}%`}
        </text>
      </svg>
      <div className="gauge-caption">
        {formatNumber(visited)} de {formatNumber(expected)} patient-days visitados
      </div>
    </div>
  )
}

/**
 * Variação contra o período anterior. Volume que sobe não é bom nem ruim (é
 * mais trabalho), então só a cobertura ganha cor — usar verde para "mais
 * internações" seria uma afirmação clínica que o dado não sustenta.
 */
export function Delta({ current, previous, suffix = '', colored = false }) {
  if (previous === null || previous === undefined || current === null || current === undefined) {
    return null
  }

  const diff = Number(current) - Number(previous)
  const rounded = Math.round(diff * 10) / 10

  if (rounded === 0) {
    return <span className="delta delta-flat">sem variação</span>
  }

  const up = rounded > 0
  const pct = Number(previous) !== 0 ? Math.abs((diff / Number(previous)) * 100) : null
  const tone = colored ? (up ? 'delta-good' : 'delta-bad') : 'delta-neutral'

  return (
    <span className={`delta ${tone}`}>
      {up ? '▲' : '▼'} {formatNumber(Math.abs(rounded), Number.isInteger(rounded) ? 0 : 1)}{suffix}
      {pct !== null && pct < 1000 ? ` (${formatNumber(pct, 0)}%)` : ''}
    </span>
  )
}

/**
 * Mediana com o tamanho da amostra sempre visível. "mediana 4 dias" com n=2 e
 * com n=200 são afirmações muito diferentes e, sem o n, idênticas na tela.
 */
export function DurationSummary({ summary, unit = 'dias' }) {
  if (!summary || !summary.n) {
    return <span className="chart-empty-inline">sem amostra no período</span>
  }

  return (
    <span>
      <strong>{formatNumber(summary.median, 1)} {unit}</strong>{' '}
      <span className="summary-detail">
        (n={summary.n} · P25 {formatNumber(summary.p25, 1)} · P75 {formatNumber(summary.p75, 1)} · P90 {formatNumber(summary.p90, 1)})
      </span>
    </span>
  )
}

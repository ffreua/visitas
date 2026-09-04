import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api'
import { formatDate } from '../lib/format'

/**
 * As visitas do próprio médico — a folha de conferência e faturamento.
 *
 * O recorte é sempre o usuário autenticado: o endpoint não aceita
 * parâmetro de médico, então esta tela não tem como (nem oferece) mostrar
 * a lista de outra pessoa. Entram apenas as visitas ASSINADAS: visita
 * atribuída e não assinada não foi feita e não se cobra.
 *
 * Reaproveita o mesmo CSS de impressão da lista do dia (.print-*); a única
 * diferença é a página nomeada `rounds`, que sai em pé — são poucas
 * colunas, e deitada sobraria papel.
 */

/** Primeiro dia do mês corrente, no fuso local. */
function firstOfMonth() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-01`
}

function today() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

export default function MyRoundsPage() {
  const [range, setRange] = useState({ date_from: firstOfMonth(), date_to: today() })
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)

  const load = useCallback(async (params) => {
    setLoading(true)
    setError('')
    try {
      const res = await api.get('/my-rounds', { params })
      setData(res.data)
    } catch (err) {
      setError(err.response?.data?.message || 'Não foi possível carregar suas visitas.')
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load({ date_from: firstOfMonth(), date_to: today() }) }, [load])

  function handleApply(e) {
    e.preventDefault()
    load(range)
  }

  const rows = data?.rows || []
  const amhsLabel = data?.amhs_label || 'Cobrar via AMHS'
  const periodLabel = data
    ? `${formatDate(data.date_from)} a ${formatDate(data.date_to)}`
    : ''

  return (
    <div className="print-page rounds-print">
      <div className="no-print" style={{ marginBottom: 14 }}>
        <h2 className="section-title" style={{ marginTop: 0 }}>Minhas visitas</h2>
        <p style={{ color: 'var(--color-text-muted)', fontSize: '0.86rem', marginTop: -6 }}>
          As visitas que <strong>você assinou</strong> no período — visita atribuída e ainda não
          assinada não entra na lista. Serve de conferência e base de faturamento.
        </p>

        <form onSubmit={handleApply} className="card" style={{ background: 'var(--color-bg)' }}>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <div className="form-group" style={{ flex: '1 1 140px', marginBottom: 0 }}>
              <label htmlFor="de">De</label>
              <input id="de" type="date" className="input" value={range.date_from}
                onChange={(e) => setRange({ ...range, date_from: e.target.value })} required />
            </div>
            <div className="form-group" style={{ flex: '1 1 140px', marginBottom: 0 }}>
              <label htmlFor="ate">Até</label>
              <input id="ate" type="date" className="input" value={range.date_to}
                onChange={(e) => setRange({ ...range, date_to: e.target.value })} required />
            </div>
          </div>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginTop: 10 }}>
            <button type="submit" className="btn btn-primary" disabled={loading}>
              {loading ? 'Carregando…' : 'Aplicar período'}
            </button>
            <button type="button" className="btn btn-outline" disabled={loading || rows.length === 0}
              onClick={() => window.print()}>
              🖨️ Imprimir
            </button>
            <Link to="/" className="btn btn-outline">Voltar</Link>
          </div>
        </form>

        {error && <div className="alert alert-danger" style={{ marginTop: 10 }}>{error}</div>}
        {data?.truncated && (
          <div className="alert alert-warning" style={{ marginTop: 10 }}>
            O período escolhido tem mais visitas do que cabe numa folha só. A lista foi cortada —
            imprima em intervalos menores (por mês, por exemplo) para não faltar linha.
          </div>
        )}
      </div>

      {!data ? (
        <div className="empty-state">Carregando…</div>
      ) : (
        <>
          <div className="print-header">
            <div>
              <strong>Neurologia — Equipe Dr Fernando Freua</strong>
              <div className="print-subtitle">
                {data.physician?.full_name}
                {data.physician?.crm ? ` · CRM ${data.physician.crm}` : ''}
              </div>
              <div className="print-subtitle">Visitas de {periodLabel}</div>
            </div>
            <div className="print-subtitle">
              {rows.length} visita{rows.length === 1 ? '' : 's'}
            </div>
          </div>

          {rows.length === 0 ? (
            <div className="empty-state">Nenhuma visita assinada por você neste período.</div>
          ) : (
            <table className="print-table">
              <thead>
                <tr>
                  <th style={{ width: '4%' }}>#</th>
                  <th style={{ width: '26%' }}>Paciente</th>
                  <th style={{ width: '12%' }}>Atendimento</th>
                  <th style={{ width: '12%' }}>Prontuário</th>
                  <th style={{ width: '12%' }}>Data da visita</th>
                  <th style={{ width: '17%' }}>CID</th>
                  <th style={{ width: '17%' }}>Convênio</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r, i) => (
                  <tr key={r.round_id}>
                    <td>{i + 1}</td>
                    <td>{r.patient_name}</td>
                    <td>{r.attendance_number || <span className="print-dim">—</span>}</td>
                    <td>{r.medical_record_number || <span className="print-dim">— pendente</span>}</td>
                    <td>{formatDate(r.round_date)}</td>
                    <td>
                      {r.cid_code
                        ? <>
                            {r.cid_code}
                            {r.cid_description && <div className="print-dim">{r.cid_description}</div>}
                          </>
                        : <span className="print-dim">sem CID registrado</span>}
                    </td>
                    <td>
                      {r.payer || <span className="print-dim">não informado</span>}
                      {r.amhs && <div className="amhs-tag">{amhsLabel}</div>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}

          <div className="print-footer">
            Documento assistencial de uso interno — contém dados de paciente. Descarte em lixo
            de papel confidencial. Impresso em {new Date().toLocaleString('pt-BR')}.
          </div>
        </>
      )}
    </div>
  )
}

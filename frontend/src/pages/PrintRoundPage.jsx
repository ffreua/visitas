import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../lib/api'
import { calculateAge, todayISODate, isSameLocalDate } from '../lib/format'

/**
 * Lista impressa dos casos ativos do dia — a folha que se leva para a
 * visita. Reaproveita exatamente o mesmo endpoint da tela de Casos Ativos
 * (/admissions, sem filtro), então o papel nunca mostra um recorte
 * diferente do que a equipe vê na tela.
 *
 * Não é exportação de arquivo: nada é gerado no servidor nem gravado em
 * disco: é a própria tela, formatada para a impressora do navegador.
 */

/**
 * O endpoint pagina de 20 em 20. Numa lista de visita, faltar o 21º
 * paciente é pior do que demorar mais um instante para carregar — daí
 * percorrer todas as páginas antes de montar a folha.
 */
async function fetchAllActive() {
  const all = []
  let page = 1
  let lastPage = 1

  do {
    const { data } = await api.get('/admissions', { params: { page } })
    all.push(...(data.data || []))
    lastPage = data.last_page || 1
    page += 1
  } while (page <= lastPage && page <= 20)

  return all
}

/** Ordem em que a enfermaria é percorrida: setor, leito, e só então nome. */
function roundOrder(a, b) {
  const key = (x) => [x.unit || '\uffff', x.bed || '\uffff', x.patient?.full_name || '']
  const [au, ab, an] = key(a)
  const [bu, bb, bn] = key(b)
  return au.localeCompare(bu, 'pt-BR', { numeric: true })
    || ab.localeCompare(bb, 'pt-BR', { numeric: true })
    || an.localeCompare(bn, 'pt-BR')
}

export default function PrintRoundPage() {
  const [admissions, setAdmissions] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    fetchAllActive()
      .then((list) => setAdmissions([...list].sort(roundOrder)))
      .catch(() => setError('Não foi possível carregar a lista para impressão.'))
  }, [])

  if (error) return <div className="alert alert-danger">{error}</div>
  if (!admissions) return <div className="empty-state">Montando a lista…</div>

  const today = todayISODate()
  // Só a primeira letra: `text-transform: capitalize` deixaria
  // "Quinta-Feira, 03 De Setembro De 2026".
  const rawToday = new Date().toLocaleDateString('pt-BR', {
    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
  })
  const todayLabel = rawToday.charAt(0).toUpperCase() + rawToday.slice(1)

  return (
    <div className="print-page">
      <div className="no-print" style={{ marginBottom: 14 }}>
        <h2 className="section-title" style={{ marginTop: 0 }}>Imprimir lista do dia</h2>
        <p style={{ color: 'var(--color-text-muted)', fontSize: '0.86rem', marginTop: -6 }}>
          {admissions.length} caso{admissions.length === 1 ? '' : 's'} ativo{admissions.length === 1 ? '' : 's'},
          na ordem em que a enfermaria é percorrida (setor, leito, nome). A folha sai em A4
          deitado, com uma coluna em branco para anotar à mão durante a visita.
        </p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
          <button type="button" className="btn btn-primary" onClick={() => window.print()}>
            🖨️ Imprimir
          </button>
          <Link to="/" className="btn btn-outline">Voltar</Link>
        </div>
      </div>

      <div className="print-header">
        <div>
          <strong>Neurologia — Equipe Dr Fernando Freua</strong>
          <div className="print-subtitle">{todayLabel}</div>
        </div>
        <div className="print-subtitle">
          {admissions.length} caso{admissions.length === 1 ? '' : 's'} ativo{admissions.length === 1 ? '' : 's'}
        </div>
      </div>

      {admissions.length === 0 ? (
        <div className="empty-state">Nenhum caso ativo hoje.</div>
      ) : (
        <table className="print-table">
          <thead>
            <tr>
              <th style={{ width: '3%' }}>#</th>
              <th style={{ width: '19%' }}>Paciente</th>
              <th style={{ width: '13%' }}>Identificação</th>
              <th style={{ width: '10%' }}>Local</th>
              <th style={{ width: '13%' }}>Tipo / convênio</th>
              <th style={{ width: '17%' }}>Hipótese e pendências</th>
              <th style={{ width: '12%' }}>Responsável hoje</th>
              <th style={{ width: '13%' }}>Anotações</th>
            </tr>
          </thead>
          <tbody>
            {admissions.map((a, i) => {
              const age = calculateAge(a.patient?.date_of_birth)
              const round = (a.daily_rounds || []).find((r) => isSameLocalDate(r.round_date, today))
              const pending = (a.pending_items || []).filter((p) => p.status === 'OPEN')
              const suspected = (a.diagnoses || []).find((d) => d.phase === 'SUSPECTED' && d.is_primary)
              const payer = a.payer_type === 'PRIVATE'
                ? 'Particular'
                : (a.health_plan_name_snapshot || a.health_plan?.name || 'Convênio')
              const responsible = round?.assigned_physician?.full_name
                || round?.completer?.full_name
                || null

              return (
                <tr key={a.id}>
                  <td>{i + 1}</td>
                  <td>
                    <strong>{a.patient?.full_name}</strong>
                    {age !== null && <div className="print-dim">{age} anos</div>}
                  </td>
                  <td>
                    <div>{a.patient?.medical_record_number || '— prontuário pendente'}</div>
                    <div className="print-dim">
                      {a.attendance_number ? `Atend. ${a.attendance_number}` : 'Atend. não informado'}
                    </div>
                  </td>
                  <td>
                    {a.unit || '—'}
                    {a.bed && <div className="print-dim">Leito {a.bed}</div>}
                  </td>
                  <td>
                    {a.care_type === 'INTERCONSULT'
                      ? `IC${a.requesting_specialty ? ` · ${a.requesting_specialty.name}` : ''}`
                      : 'Institucional'}
                    {a.followup_mode === 'SINGLE_EVALUATION' && <div className="print-dim">avaliação única</div>}
                    <div className="print-dim">{payer}</div>
                  </td>
                  <td>
                    {suspected
                      ? `${suspected.cid_code} — ${suspected.description_snapshot}`
                      : <span className="print-dim">sem hipótese registrada</span>}
                    {pending.length > 0 && (
                      <ul className="print-pending">
                        {pending.map((p) => <li key={p.id}>{p.description}</li>)}
                      </ul>
                    )}
                  </td>
                  <td>
                    {responsible || <span className="print-dim">não definido</span>}
                    <div className="print-dim">
                      {round?.completed_at ? '✓ visitado' : '☐ visita pendente'}
                    </div>
                  </td>
                  <td className="print-notes"></td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}

      <div className="print-footer">
        Documento assistencial de uso interno — contém dados de paciente. Descarte em lixo
        de papel confidencial. Impresso em {new Date().toLocaleString('pt-BR')}.
      </div>
    </div>
  )
}

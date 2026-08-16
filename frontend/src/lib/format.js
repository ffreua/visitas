export function calculateAge(dateOfBirth) {
  if (!dateOfBirth) return null
  const parts = String(dateOfBirth).slice(0, 10).split('-').map(Number)
  if (parts.length < 3 || isNaN(parts[0])) return null
  const [y, m, d] = parts
  const today = new Date()
  let age = today.getFullYear() - y
  const curM = today.getMonth() + 1
  const curD = today.getDate()
  if (curM < m || (curM === m && curD < d)) age--
  return age
}

export function formatDate(value) {
  if (!value) return null
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}/.test(value)) {
    const [y, m, d] = value.slice(0, 10).split('-')
    return `${d}/${m}/${y}`
  }
  return new Date(value).toLocaleDateString('pt-BR')
}

export function formatDateTime(value) {
  if (!value) return null
  return new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}

export function todayISODate() {
  const d = new Date()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function nowLocalISOString() {
  const d = new Date()
  const year = d.getFullYear()
  const month = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  const hours = String(d.getHours()).padStart(2, '0')
  const minutes = String(d.getMinutes()).padStart(2, '0')
  return `${year}-${month}-${day}T${hours}:${minutes}`
}

export function isSameLocalDate(isoValue, isoDate) {
  if (!isoValue || !isoDate) return false
  return String(isoValue).slice(0, 10) === String(isoDate).slice(0, 10)
}


export function calculateAge(dateOfBirth) {
  if (!dateOfBirth) return null
  const dob = new Date(dateOfBirth)
  const today = new Date()
  let age = today.getFullYear() - dob.getFullYear()
  const m = today.getMonth() - dob.getMonth()
  if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--
  return age
}

export function formatDate(value) {
  if (!value) return null
  return new Date(value).toLocaleDateString('pt-BR')
}

export function formatDateTime(value) {
  if (!value) return null
  return new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}

export function todayISODate() {
  const d = new Date()
  return d.toISOString().slice(0, 10)
}

export function isSameLocalDate(isoValue, isoDate) {
  if (!isoValue) return false
  return new Date(isoValue).toISOString().slice(0, 10) === isoDate
}

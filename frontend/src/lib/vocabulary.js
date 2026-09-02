/**
 * Vocabulário fechado da procedência — precisa espelhar exatamente
 * Admission::ORIGINS no backend (que é quem valida). Texto livre aqui
 * inviabilizaria comparar séries ao longo do tempo: "PS", "P.S." e
 * "pronto socorro" virariam três categorias distintas na análise.
 */
export const ORIGINS = [
  ['EMERGENCY_ROOM', 'Pronto Socorro'],
  ['ICU', 'UTI'],
  ['WARD', 'Enfermaria'],
  ['OPERATING_ROOM', 'Centro Cirúrgico'],
  ['OTHER', 'Outro'],
]

export function originLabel(code) {
  if (!code) return null
  return ORIGINS.find(([value]) => value === code)?.[1] || code
}

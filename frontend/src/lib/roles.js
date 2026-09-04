/**
 * Papéis de usuário — precisa espelhar o enum `users.role` no backend.
 *
 * OBSERVER ("gestor observador") é somente leitura: acompanha a lista, a
 * movimentação da equipe e os dashboards, sem escrever nada nem exportar.
 * Quem garante isso é o backend (middleware DenyObserverWrites + Policies);
 * estas funções servem só para não OFERECER na tela um botão que o servidor
 * vai recusar.
 */
export function isObserver(user) {
  return user?.role === 'OBSERVER'
}

export function canWrite(user) {
  return !!user && !isObserver(user)
}

export function roleLabel(role) {
  if (role === 'ADMIN') return 'Administrador'
  if (role === 'OBSERVER') return 'Gestor observador'
  return 'Médico'
}

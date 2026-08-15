import { useState } from 'react'

function isIos() {
  return /iphone|ipad|ipod/i.test(window.navigator.userAgent)
}

function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true
}

/**
 * iOS não suporta o prompt nativo de instalação de PWA — a única forma é
 * o usuário usar Compartilhar → Adicionar à Tela de Início (seção 64).
 */
export default function IosInstallHint() {
  const [dismissed, setDismissed] = useState(() => sessionStorage.getItem('ios_install_hint_dismissed') === '1')

  if (dismissed || !isIos() || isStandalone()) return null

  return (
    <div className="alert alert-warning" style={{ margin: 12, display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'center' }}>
      <span>Instale este aplicativo: toque em Compartilhar e depois em "Adicionar à Tela de Início".</span>
      <button
        className="btn btn-outline"
        style={{ minHeight: 28, padding: '2px 8px', flex: 'none' }}
        onClick={() => {
          sessionStorage.setItem('ios_install_hint_dismissed', '1')
          setDismissed(true)
        }}
      >
        Ok
      </button>
    </div>
  )
}

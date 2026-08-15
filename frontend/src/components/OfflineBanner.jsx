import { useEffect, useState } from 'react'

/**
 * Seção 63 do PRD: sem conexão, nenhuma informação clínica é exibida —
 * o app nunca deve parecer "funcionar offline" mostrando dados antigos.
 */
export default function OfflineBanner() {
  const [online, setOnline] = useState(navigator.onLine)

  useEffect(() => {
    const goOnline = () => setOnline(true)
    const goOffline = () => setOnline(false)
    window.addEventListener('online', goOnline)
    window.addEventListener('offline', goOffline)
    return () => {
      window.removeEventListener('online', goOnline)
      window.removeEventListener('offline', goOffline)
    }
  }, [])

  if (online) return null

  return (
    <div className="alert alert-danger" style={{ margin: 12, marginBottom: 0 }}>
      Sem conexão com o servidor.<br />
      Por segurança, informações clínicas não são disponibilizadas offline.
    </div>
  )
}

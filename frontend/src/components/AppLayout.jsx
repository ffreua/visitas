import { Link, Outlet, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { useAuth } from '../context/AuthContext'
import IosInstallHint from './IosInstallHint'

export default function AppLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [menuOpen, setMenuOpen] = useState(false)

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="app-shell">
      <header className="topbar">
        <h1>Neurologia</h1>
        <div className="topbar-actions">
          <button className="btn btn-outline" style={{ minHeight: 32, padding: '4px 10px', color: '#fff', borderColor: 'rgba(255,255,255,0.5)' }} onClick={() => setMenuOpen((v) => !v)}>
            {user?.full_name?.split(' ')[0] || 'Menu'} ▾
          </button>
        </div>
      </header>

      {menuOpen && (
        <nav className="card" style={{ margin: 12, marginBottom: 0 }}>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
            <Link to="/" onClick={() => setMenuOpen(false)}>Casos ativos</Link>
            <Link to="/altas" onClick={() => setMenuOpen(false)}>Altas / Histórico</Link>
            <Link to="/novo" onClick={() => setMenuOpen(false)}>+ Novo atendimento</Link>
            {user?.role === 'ADMIN' && (
              <>
                <hr style={{ width: '100%', border: 'none', borderTop: '1px solid var(--color-border)' }} />
                <Link to="/admin/dashboard" onClick={() => setMenuOpen(false)}>Administração — Dashboard</Link>
                <Link to="/admin/exportacoes" onClick={() => setMenuOpen(false)}>Administração — Exportações</Link>
                <Link to="/admin/equipe" onClick={() => setMenuOpen(false)}>Administração — Equipe</Link>
                <Link to="/admin/planos" onClick={() => setMenuOpen(false)}>Administração — Planos de saúde</Link>
                <Link to="/admin/especialidades" onClick={() => setMenuOpen(false)}>Administração — Especialidades</Link>
                <Link to="/admin/excluidos" onClick={() => setMenuOpen(false)}>Administração — Excluídos</Link>
              </>
            )}
            <hr style={{ width: '100%', border: 'none', borderTop: '1px solid var(--color-border)' }} />
            <button className="btn btn-outline" onClick={handleLogout}>Sair</button>
          </div>
        </nav>
      )}

      <main className="main-content">
        <IosInstallHint />
        <Outlet />
      </main>
    </div>
  )
}

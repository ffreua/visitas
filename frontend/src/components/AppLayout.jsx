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
        <h1>Neurologia - Equipe Dr Freua</h1>
        <div className="topbar-actions">
          <button
            type="button"
            className="topbar-menu-btn"
            onClick={() => setMenuOpen((v) => !v)}
            aria-label="Abrir menu"
          >
            👤 {user?.full_name?.split(' ')[0] || 'Menu'} ▾
          </button>
        </div>
      </header>

      {menuOpen && (
        <>
          <div className="menu-backdrop" onClick={() => setMenuOpen(false)} />
          <nav className="menu-drawer">
            <div className="menu-header">
              <span className="menu-user-name">{user?.full_name || 'Médico'}</span>
              <span className="menu-user-role">{user?.role === 'ADMIN' ? 'Admin' : 'Médico'}</span>
            </div>

            <div className="menu-section-title">Atendimento Clínico</div>
            <div className="menu-items-list">
              <Link to="/" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                <div className="menu-item-content">
                  <span className="menu-item-icon">📋</span>
                  <span>Casos Ativos</span>
                </div>
                <span className="menu-item-arrow">›</span>
              </Link>
              <Link to="/altas" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                <div className="menu-item-content">
                  <span className="menu-item-icon">📁</span>
                  <span>Altas / Histórico</span>
                </div>
                <span className="menu-item-arrow">›</span>
              </Link>
              <Link to="/novo" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                <div className="menu-item-content">
                  <span className="menu-item-icon">➕</span>
                  <span style={{ fontWeight: 600, color: 'var(--color-primary)' }}>Novo Atendimento</span>
                </div>
                <span className="menu-item-arrow">›</span>
              </Link>
            </div>

            {user?.role === 'ADMIN' && (
              <>
                <div className="menu-section-title" style={{ borderTop: '1px solid var(--color-border)', marginTop: 4, paddingTop: 10 }}>
                  Administração
                </div>
                <div className="menu-items-list">
                  <Link to="/admin/dashboard" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">📊</span>
                      <span>Indicadores & Dashboard</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/exportacoes" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">📥</span>
                      <span>Exportações XLSX</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/equipe" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">👥</span>
                      <span>Gestão da Equipe</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/planos" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">🏥</span>
                      <span>Planos de Saúde</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/especialidades" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">🩺</span>
                      <span>Especialidades</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/excluidos" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">🗑️</span>
                      <span>Registros Excluídos</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                  <Link to="/admin/sistema" className="menu-item-link" onClick={() => setMenuOpen(false)}>
                    <div className="menu-item-content">
                      <span className="menu-item-icon">⚙️</span>
                      <span>Sistema & Backups</span>
                    </div>
                    <span className="menu-item-arrow">›</span>
                  </Link>
                </div>
              </>
            )}

            <div className="menu-footer">
              <button type="button" className="btn-logout" onClick={handleLogout}>
                🚪 Sair da conta
              </button>
            </div>
          </nav>
        </>
      )}

      <main className="main-content">
        <IosInstallHint />
        <Outlet />
      </main>

      <footer style={{ textAlign: 'center', padding: '20px 12px', fontSize: '0.82rem', color: 'var(--color-text-muted)', borderTop: '1px solid var(--color-border)', marginTop: 32 }}>
        Desenvolvido por Dr Fernando Freua
      </footer>
    </div>
  )
}

import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider } from './context/AuthContext'
import ProtectedRoute from './components/ProtectedRoute'
import AppLayout from './components/AppLayout'
import LoginPage from './pages/LoginPage'
import ChangePasswordPage from './pages/ChangePasswordPage'
import DashboardPage from './pages/DashboardPage'
import ClosedListPage from './pages/ClosedListPage'
import NewAdmissionPage from './pages/NewAdmissionPage'
import AdmissionDetailPage from './pages/AdmissionDetailPage'
import UsersAdminPage from './pages/admin/UsersAdminPage'
import HealthPlansAdminPage from './pages/admin/HealthPlansAdminPage'
import MedicalSpecialtiesAdminPage from './pages/admin/MedicalSpecialtiesAdminPage'
import TrashedAdminPage from './pages/admin/TrashedAdminPage'
import DashboardAdminPage from './pages/admin/DashboardAdminPage'
import ExportsAdminPage from './pages/admin/ExportsAdminPage'
import SystemAdminPage from './pages/admin/SystemAdminPage'
import OfflineBanner from './components/OfflineBanner'

// import.meta.env.BASE_URL reflete o "base" do vite.config.js (ver
// VITE_BASE_PATH ali) — mantém as rotas corretas caso o app seja servido
// de uma subpasta do domínio em produção (drfernandofreua.com.br/visitas).
const basename = import.meta.env.BASE_URL.replace(/\/$/, '') || '/'

export default function App() {
  return (
    <BrowserRouter basename={basename}>
      <AuthProvider>
        <OfflineBanner />
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          <Route element={<ProtectedRoute />}>
            <Route path="/trocar-senha" element={<ChangePasswordPage />} />

            <Route element={<AppLayout />}>
              <Route path="/" element={<DashboardPage />} />
              <Route path="/altas" element={<ClosedListPage />} />
              <Route path="/novo" element={<NewAdmissionPage />} />
              <Route path="/atendimentos/:id" element={<AdmissionDetailPage />} />

              <Route element={<ProtectedRoute adminOnly />}>
                <Route path="/admin/dashboard" element={<DashboardAdminPage />} />
                <Route path="/admin/exportacoes" element={<ExportsAdminPage />} />
                <Route path="/admin/equipe" element={<UsersAdminPage />} />
                <Route path="/admin/planos" element={<HealthPlansAdminPage />} />
                <Route path="/admin/especialidades" element={<MedicalSpecialtiesAdminPage />} />
                <Route path="/admin/excluidos" element={<TrashedAdminPage />} />
                <Route path="/admin/sistema" element={<SystemAdminPage />} />
              </Route>
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}

import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function ProtectedRoute({ adminOnly = false }) {
  const { user, loading } = useAuth()
  const location = useLocation()

  if (loading) return null

  if (!user) {
    return <Navigate to="/login" state={{ from: location }} replace />
  }

  if (user.must_change_password && location.pathname !== '/trocar-senha') {
    return <Navigate to="/trocar-senha" replace />
  }

  if (adminOnly && user.role !== 'ADMIN') {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}

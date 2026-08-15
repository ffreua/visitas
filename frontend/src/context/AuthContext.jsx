import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import api from '../lib/api'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    try {
      const { data } = await api.get('/auth/me')
      setUser(data.user)
    } catch {
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  const login = async (username, password) => {
    const { data } = await api.post('/auth/login', { username, password })
    setUser(data.user)
    return data.user
  }

  const logout = async () => {
    await api.post('/auth/logout')
    setUser(null)
  }

  const changePassword = async (currentPassword, newPassword, newPasswordConfirmation) => {
    const { data } = await api.post('/auth/change-password', {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPasswordConfirmation,
    })
    setUser(data.user)
    return data.user
  }

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, changePassword, refresh }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth deve ser usado dentro de AuthProvider')
  return ctx
}

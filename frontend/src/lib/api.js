import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
  },
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error.response?.status
    const path = window.location.pathname

    if (status === 401 && path !== '/login') {
      window.location.href = '/login'
    } else if (status === 423 && path !== '/trocar-senha') {
      window.location.href = '/trocar-senha'
    }

    return Promise.reject(error)
  }
)

export default api

import axios from 'axios'
import { basePath } from './basePath'

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

    // basePath prefixa /login e /trocar-senha com a subpasta de produção
    // (/visitas) quando aplicável — sem isso, este redirect "cru" (fora do
    // react-router) levava pra https://dominio/login em vez de
    // https://dominio/visitas/login, quebrando a sessão expirada/troca de
    // senha obrigatória assim que o app rodasse fora da raiz do domínio.
    if (status === 401 && path !== `${basePath}/login`) {
      window.location.href = `${basePath}/login`
    } else if (status === 423 && path !== `${basePath}/trocar-senha`) {
      window.location.href = `${basePath}/trocar-senha`
    }

    return Promise.reject(error)
  }
)

export default api

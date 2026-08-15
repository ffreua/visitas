import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Em dev, o Vite roda em porta separada do Laravel (php artisan serve).
// Proxy faz o navegador enxergar tudo como mesma origem — sem isso,
// cookie de sessão e CSRF do Laravel não funcionariam corretamente.
//
// Produção vai ficar em drfernandofreua.com.br/visitas (subpasta, não raiz
// do domínio) — se o document root do domínio não apontar diretamente para
// essa pasta, os caminhos absolutos dos assets ("/assets/...") quebram.
// Ajustar VITE_BASE_PATH=/visitas/ no build de produção se for o caso.
export default defineConfig({
  base: process.env.VITE_BASE_PATH || '/',
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
})

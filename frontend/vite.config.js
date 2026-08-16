import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { VitePWA } from 'vite-plugin-pwa'

// Em dev, o Vite roda em porta separada do Laravel (php artisan serve).
// Proxy faz o navegador enxergar tudo como mesma origem — sem isso,
// cookie de sessão e CSRF do Laravel não funcionariam corretamente.
//
// CONFIRMADO: produção fica em drfernandofreua.com.br/visitas, uma subpasta
// dentro de um public_html que já tem outro site no domínio principal — não
// é o document root dedicado. Por isso o BUILD DE PRODUÇÃO é OBRIGATORIAMENTE:
//   VITE_BASE_PATH=/visitas/ npm run build
// (sem isso os caminhos absolutos dos assets, "/assets/...", quebram — o
// navegador buscaria em drfernandofreua.com.br/assets/..., não .../visitas/assets/...)
const basePath = process.env.VITE_BASE_PATH || '/'

export default defineConfig({
  base: basePath,
  plugins: [
    react(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['favicon.svg', 'icons/apple-touch-icon.png'],
      manifest: {
        id: basePath,
        name: 'Neurologia Hospitalar',
        short_name: 'Neurologia',
        description: 'Gestão dos pacientes acompanhados pela equipe de Neurologia hospitalar.',
        start_url: basePath,
        scope: basePath,
        display: 'standalone',
        background_color: '#f4f6f8',
        theme_color: '#0b5d8f',
        icons: [
          { src: 'icons/icon-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icons/icon-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        // Precache só o shell estático (HTML/CSS/JS/ícones) — NUNCA dados
        // clínicos. globPatterns restrito a assets de build; nenhuma regra
        // de runtimeCaching é definida, então /api/* nunca é interceptado
        // pelo service worker (passa direto pra rede, seção 62-63 do PRD).
        globPatterns: ['**/*.{js,css,html,ico,png,svg,webmanifest}'],
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: [],
      },
      devOptions: {
        enabled: false,
      },
    }),
  ],
  server: {
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
  // Mesmo proxy para `vite preview` — usado para testar o build de produção
  // (com service worker ativo) localmente antes do deploy real.
  preview: {
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

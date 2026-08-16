// import.meta.env.BASE_URL reflete o "base" do vite.config.js (ver
// VITE_BASE_PATH ali): vazio/"/" em dev (raiz), ou "/visitas/" no build de
// produção (subpasta dentro de um public_html que já tem outro site).
//
// basePath aqui já vem SEM barra final, pronto para concatenar com um path
// ("/login" etc.) — "" na raiz, "/visitas" na subpasta. Não usar direto como
// basename do BrowserRouter (que precisa de "/" na raiz, não "") — para
// isso, ver `basename` abaixo.
export const basePath = import.meta.env.BASE_URL.replace(/\/$/, '')

export const basename = basePath || '/'

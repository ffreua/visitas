# STATUS

ETAPA ATUAL: Frontend React — fluxo assistencial principal (mobile-first) construído e testado ponta a ponta no navegador. Faltam: dashboard de indicadores, exportação XLSX, PWA, admin de excluídos com detalhe completo, hardening final e deploy.

ETAPAS CONCLUÍDAS:

## Backend (Laravel 12 + SQLite) — ver histórico anterior para o detalhamento completo
- Schema completo, autenticação/RBAC, pacientes/episódios, planos/especialidades/CID-10, interconsulta/avaliação única, visita diária, pendências, soft delete/restore/hard delete, backups com retenção — tudo testado (25 testes automatizados, Pint limpo).
- Novo: `GET /api/physicians` — lista enxuta de médicos ativos (para atribuir responsável do dia), acessível a qualquer usuário autenticado.

## Frontend (React + Vite)
- Cliente axios (`src/lib/api.js`) com sessão/cookie (sem Sanctum), CSRF automático via `withXSRFToken`, e interceptors para 401 (sessão expirada → `/login`) e 423 (troca de senha pendente → `/trocar-senha`).
- `AuthContext` (login/logout/troca de senha/`me`) + `ProtectedRoute` (bloqueia acesso sem sessão, força troca de senha, restringe rotas admin).
- Vite configurado com proxy `/api` → Laravel em dev (mesma origem do ponto de vista do navegador) e `base`/`basename` parametrizáveis via `VITE_BASE_PATH` para o caso de produção ficar em subpasta do domínio (`drfernandofreua.com.br/visitas`).
- Telas construídas: Login, Troca de senha obrigatória, Dashboard (cards de resumo, busca, filtros da seção 56, lista de casos ativos mobile-first — seção 57), Novo atendimento (busca por prontuário → cadastro se não encontrado → alerta de reinternação → bloqueio de episódio ativo duplicado → formulário completo com autocomplete de plano/especialidade/CID), Detalhe do atendimento (identificação, internação, pagamento, diagnósticos, visita de hoje com atribuição de responsável e "visita realizada", pendências, encerrar acompanhamento/concluir avaliação única, converter avaliação única em acompanhamento, excluir com motivo), Altas/Histórico, e páginas admin (Equipe, Planos de saúde, Especialidades, Excluídos com restaurar/excluir definitivamente).
- CSS mobile-first próprio (`index.css`) — sem framework, cards/pills/badges, testado visualmente em viewport 390px.
- `npm run lint` (oxlint) e `npm run build` rodando sem erros.

## Teste end-to-end no navegador (Playwright, headless, viewport 390×844)
Executado o fluxo completo: login com `admin`/`senha@1234` → troca de senha obrigatória → dashboard → novo atendimento com prontuário inexistente → cadastro de paciente → formulário de episódio (Institucional/Acompanhamento/Particular + autocomplete de CID) → detalhe do atendimento → atribuir responsável → marcar visita realizada → criar pendência → resolver pendência → encerrar acompanhamento com diagnóstico final → confirmar que o caso sai da lista de ativos e aparece em Altas/Histórico. Sem erros de console além dos esperados (401 antes do login, 404 na busca de prontuário inexistente — comportamento correto). Screenshots confirmam layout mobile correto em cada etapa.

## PENDENTE (não iniciado ou parcial) — próximas fases
- **Dashboard administrativo e indicadores** (FASE 14, seções 55/67-80): os cards de resumo do dashboard hoje mostram apenas a contagem total do filtro ativo (via paginação), não os KPIs completos (patient-days, tempo de resposta, concordância diagnóstica, etc.) — precisa de endpoints de agregação dedicados.
- **Exportação XLSX** (FASE 15, seções 81-88): não iniciada.
- **Restore de backup** (seção 95) e **Zona de perigo / zerar dados** (seções 96-97): não implementados.
- **PWA** (FASE 17): manifest.webmanifest, service worker (shell-only), ícones — não iniciado. `vite-plugin-pwa` está instalado mas não configurado.
- **Painel de qualidade dos dados** (seção 80): não implementado.
- **Importação CSV de planos** (seção 16, opcional): não implementada.
- **Hardening final e revisão independente** (FASE 19, seção 132): ainda não realizada — IDOR/CSRF/XSS precisam de uma revisão dedicada antes do deploy real.
- **Deploy HostGator real** (FASE 20): `DEPLOYMENT_HOSTGATOR.md` tem o roteiro; falta confirmar a estrutura exata do domínio/subpasta (ver nota abaixo) e executar.

TESTES EXECUTADOS:
- Backend: `php artisan test` (25 passando), `vendor/bin/pint --test` (limpo), `migrate:fresh --seed` contra SQLite real.
- Frontend: `npm run lint` (oxlint, 1 warning não bloqueante), `npm run build` (sucesso).
- E2E manual assistido por Playwright headless: fluxo completo login → novo episódio → ações clínicas → encerramento → histórico, com screenshots e checagem de console/erros HTTP.
TESTES APROVADOS: todos os executados acima.

PROBLEMAS CONHECIDOS:
- Ambiente de desenvolvimento não possui PHP/Composer nativos no PATH — usar sempre `C:\xampp\php\php.exe` e `C:\xampp\php\php.exe C:\xampp\php\composer.phar`.
- No Windows, `npm run dev` do Vite só ficou acessível via `localhost` (IPv6 `::1`), não via `127.0.0.1` diretamente — sem impacto em produção (Linux/cPanel).
- **Atenção para o deploy**: `drfernandofreua.com.br/visitas` é uma subpasta, não a raiz do domínio. Se o document root do domínio não apontar diretamente para essa pasta, é necessário buildar o frontend com `VITE_BASE_PATH=/visitas/ npm run build` (já parametrizado em `vite.config.js` e `App.jsx`) — decidir isso durante a FASE 20 antes de gerar o build final.
- Projeto está dentro de uma pasta sincronizada pelo OneDrive — considerar excluir `vendor/`, `node_modules/`, `equipe/data`/`equipe/backups` da sincronização.

PRÓXIMO PASSO:
- Dashboard de indicadores (endpoints de agregação + UI), depois exportação XLSX, PWA (manifest/service worker shell-only) e só então a revisão de segurança independente (seção 132) fim a fim antes de cogitar deploy real em produção.

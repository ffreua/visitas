# CHANGELOG

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/).

## [Unreleased]

### Changed
- `config/database.php`: caminho do SQLite agora resolve relativo (`base_path('../data/neurologia.sqlite3')`) em vez de exigir um caminho absoluto no `.env` de produção.
- `DEPLOYMENT_HOSTGATOR.md` reescrito para um fluxo sem terminal (upload direto via cPanel File Manager).

### Fixed
- Revisão de segurança independente (seção 132 do PRD): timezone hardcoded em UTC (impacto clínico real na visita noturna), episódio ativo duplicado via restore/corrida de criação simultânea (+ índice único parcial), snapshot de plano de saúde dessincronizado, `.env.example` inseguro, sessão sobrevivendo à desativação de usuário, fallback SPA apontando pro caminho errado em produção, e uma dezena de itens menores (rate limiting de reautenticação, password spraying no login, `PatientPolicy` ausente, listagem admin de planos/especialidades acessível a médicos, validação de datas inoperante, atribuição de responsável a usuário inativo, `ip_hash`/`request_id` de auditoria). Todos corrigidos e cobertos por teste de regressão.
- Estrutura de deploy confirmada: app fica em `public_html/visitas/` (subpasta de um `public_html` que já hospeda outro site), não no document root. `index.php`, `.htaccess`, `bootstrap/app.php` e `vite.config.js` ajustados. Também corrigido: requisição sem `Accept: application/json` causava 500 (tentava redirecionar pra uma rota de login inexistente) em vez de um redirect/401 adequado.
- Testado o build de produção real com o prefixo `/visitas/` de ponta a ponta (não só configurado): corrigida a estrutura de pastas (build fica junto com `index.php`, sem subpasta `build/` própria, para bater com os caminhos de asset que o Vite gera) e um redirect absoluto no `api.js` que ignorava o prefixo `/visitas` ao lidar com sessão expirada (levaria a um 404 em produção toda vez que a sessão expirasse).

### Added
- Restore de backup (`php artisan neurologia:restore`, CLI-only por segurança) e Zona de Perigo (zerar dados clínicos preservando referências, com backup verificado obrigatório antes).
- Tela `/admin/sistema` (integrity_check, lista de backups, zona de perigo).
- `public_html/index.php` e `.htaccess` reais para o deploy em produção.
- Dashboard de indicadores administrativos (volume, planos, interconsultas, tempo de internação, cobertura de visita, diagnósticos/concordância, reinternação, pendências, avaliações únicas, cobertura por médico, qualidade dos dados).
- Exportação XLSX (Pacientes, Episodios, Diagnosticos, Visitas, Pendencias), identificável (reautenticação) ou pseudonimizada, entregue por rota autenticada e apagada após o download.
- PWA: manifest, service worker (shell-only, nunca cacheia `/api/*`), ícones, hint de instalação iOS, aviso de offline.
- Frontend React completo para o fluxo assistencial principal: login, troca de senha obrigatória, dashboard com filtros e busca, novo atendimento (busca por prontuário/cadastro/reinternação/bloqueio de episódio duplicado), detalhe do atendimento (visita diária, pendências, encerramento, avaliação única, exclusão), altas/histórico, e páginas admin (equipe, planos, especialidades, excluídos).
- Endpoint `GET /api/physicians` para atribuição de responsável do dia.
- Suporte a `VITE_BASE_PATH` para build em subpasta de domínio (produção fica em `drfernandofreua.com.br/visitas`).
- Teste end-to-end via Playwright headless (mobile viewport) cobrindo o fluxo assistencial completo.
- Estrutura inicial do repositório (equipe/app, equipe/data, equipe/backups, equipe/exports, equipe/logs, public_html).
- Scaffold Laravel 12 em `equipe/app` (Laravel 11 fora do período de suporte de segurança em 2026).
- Scaffold React + Vite em `frontend`, com react-router-dom, axios e vite-plugin-pwa.
- Documentação inicial: README, STATUS, IMPLEMENTATION_LOG, SECURITY_CHECKLIST, DEPLOYMENT_HOSTGATOR, DATABASE_SCHEMA.
- Schema completo (migrations) para users, patients, health_plans, medical_specialties, cid10, admissions, admission_diagnoses, pending_items, daily_rounds, audit_logs — com WAL, busy_timeout e foreign_keys ativos no SQLite.
- Autenticação por sessão (troca de senha obrigatória no primeiro login) e RBAC via Policies (ADMIN/PHYSICIAN).
- CRUD de pacientes/episódios com reinternação, bloqueio de episódio ativo duplicado, snapshot de plano de saúde e optimistic locking.
- Autocomplete de planos de saúde, especialidades e CID-10; comando `cid10:import` para importação em produção.
- Interconsulta, avaliação única (com conclusão e conversão para acompanhamento), visita diária com reset lógico por dia, pendências.
- Soft delete (médico), restore e hard delete (admin, com reautenticação e frase de confirmação) para episódios.
- Comandos `neurologia:preflight` e `neurologia:backup` (checkpoint WAL, checksum, retenção diária/semanal/mensal configurável).
- 25 testes automatizados cobrindo os cenários críticos do PRD; Laravel Pint configurado e limpo.

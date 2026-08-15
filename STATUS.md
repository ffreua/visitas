# STATUS

ETAPA ATUAL: Restore de backup, Zona de perigo (zerar dados clínicos) e arquivos de deploy (`public_html/index.php` + `.htaccess`) concluídos e testados. Falta: revisão de segurança independente (seção 132) e o deploy real (depende de acesso à hospedagem).

## Restore, Zona de perigo e deploy prep (2026-08-15)
- `php artisan neurologia:restore {backup.sqlite3}` — **exclusivamente CLI**, nunca rota web (seção 95 do PRD permite essa saída quando o restore via navegador não é seguro — trocar o arquivo do SQLite por baixo de uma aplicação com PHP-FPM ativo é arriscado). Verifica integridade do backup escolhido, cria e verifica um backup de segurança do estado atual antes de sobrescrever, verifica integridade do resultado, audita a ação.
- `App\Services\BackupService` extraído de dentro do comando `neurologia:backup` para ser reusado pela Zona de Perigo e pelo restore — resolve o caminho do banco a partir da conexão padrão ATUAL (`config('database.default')`), não mais hardcoded `'sqlite'`, para funcionar corretamente sob troca dinâmica de conexão (necessário para testar o fluxo com um arquivo real em vez de `:memory:`).
- `POST /api/admin/system/reset-clinical-data` (Zona de Perigo, seções 96-97): reautenticação por senha + frase exata "ZERAR DADOS CLINICOS", cria e verifica um backup de segurança **antes** de apagar qualquer coisa (aborta sem apagar nada se o backup falhar), apaga `admissions` (cascata para diagnósticos/pendências/visitas) e `patients`, preserva usuários/CID-10/especialidades/planos/schema/`audit_logs` (inclusive o próprio registro `RESET_DATABASE` da operação).
- `GET /api/admin/system/backups` (somente leitura — lista o que existe, a restauração em si é só CLI).
- Tela `/admin/sistema` no frontend (integrity_check, lista de backups, zona de perigo) — testada visualmente e funcionalmente via Playwright (criou paciente real, zerou dados, confirmou desaparecimento do dashboard).
- `public_html/index.php` e `.htaccess` reais criados (carregam o Laravel de `equipe/app`, servem assets estáticos direto via Apache, só caem no PHP para `/api/*` e o shell do SPA) — documentada a decisão pendente sobre se `visitas` será document root dedicado ou subpasta dentro de um `public_html` compartilhado (afeta a profundidade dos `../` no index.php e se `VITE_BASE_PATH` precisa ser usado no build).
- `DEPLOYMENT_HOSTGATOR.md` atualizado com o passo a passo completo, agendamento via cron, e as instruções de backup/restore/zona de perigo.
- 5 novos testes de feature (`DangerZoneTest`) — 41 testes no total, todos passando (verificado também com `--order-by=random` duas vezes, sem dependência de ordem).
- **Bug de teste encontrado e corrigido** (não um bug de produção): o teste do fluxo completo de reset precisava de um SQLite em arquivo real (não `:memory:`) para o backup de segurança conseguir copiar algo; a primeira tentativa mutou a conexão `sqlite` compartilhada com `DB::purge()`, o que vazou e quebrou ~20 testes de outras classes que rodam depois na mesma execução do PHPUnit (o Laravel mantém uma única conexão `:memory:` viva pelo processo inteiro para otimizar `RefreshDatabase`). Corrigido usando uma conexão nomeada separada só para esse teste, nunca tocando na conexão `sqlite` padrão.

## Backend (Laravel 12 + SQLite)
- Schema completo (migrations): users, patients, health_plans, medical_specialties, cid10, admissions, admission_diagnoses, pending_items, daily_rounds, audit_logs — WAL, busy_timeout=5000 e foreign_keys=ON confirmados via `PRAGMA` real, não só configurado.
- Autenticação por sessão/cookie (sem Sanctum — mesma origem), troca de senha obrigatória no primeiro login, RBAC via Policies (ADMIN/PHYSICIAN).
- Pacientes/episódios: busca por prontuário (reinternação), bloqueio de episódio ativo duplicado, snapshot do plano de saúde por episódio, optimistic locking (`version`, 409 em edição concorrente).
- Planos de saúde/especialidades: autocomplete accent/case-insensitive, CRUD admin-only, nunca apaga registro usado (só `active=false`).
- CID-10: seed inicial (~24 códigos comuns em Neurologia) + autocomplete + `cid10:import {csv}` para a tabela completa em produção.
- Interconsulta, avaliação única (conclusão fecha o episódio automaticamente + `convert-to-followup`), visita diária (reset lógico diário sem perder histórico), pendências.
- Soft delete (médico) / restore e hard delete (admin, reautenticação + frase "EXCLUIR DEFINITIVAMENTE") — testado que PHYSICIAN recebe 403 mesmo chamando a API diretamente.
- `neurologia:preflight`, `neurologia:backup` (checkpoint WAL + checksum + retenção diária/semanal/mensal, agendado via `Schedule::command`), `cid10:import`.
- Dashboard de indicadores: `GET /api/admin/dashboard` (volume, particular×plano, interconsultas, tempo de internação hospitalar×Neurologia — nunca misturados, cobertura de visita diária, diagnósticos + concordância hipótese→final, reinternação 7/30 dias, pendências, avaliações únicas, cobertura operacional por médico sem ranking) e `GET /api/admin/dashboard/data-quality` (seção 80). Percentis calculados em PHP (`App\Services\Percentiles`, SQLite não tem `PERCENTILE_CONT`).
- Exportação XLSX: `POST /api/admin/exports` + `GET /api/admin/exports/{token}/download` — workbook (Pacientes, Episodios, Diagnosticos, Visitas, Pendencias) via PhpSpreadsheet, export identificável exige reautenticação por senha, export pseudonimizado troca nome/prontuário por código, arquivo fora de `public_html`, apagado após o download, `exports:cleanup` como backstop para órfãos. Filtros compartilhados com o dashboard via `App\Services\AdmissionFilters`.
- `GET /api/physicians` — lista de médicos ativos para atribuir responsável do dia (qualquer usuário autenticado, distinto da gestão de equipe admin-only).
- 36 testes automatizados passando, cobrindo os cenários críticos do PRD (seções 107-114) + dashboard + exportação + retenção de backup. Laravel Pint limpo.

## Frontend (React + Vite)
- Cliente axios com sessão/cookie, CSRF automático (`withXSRFToken`), interceptors para 401 (sessão expirada → `/login`) e 423 (troca de senha pendente → `/trocar-senha`).
- Telas: Login, Troca de senha obrigatória, Dashboard (busca/filtros/cards mobile-first), Novo atendimento (busca por prontuário → cadastro se não encontrado → alerta de reinternação → bloqueio de episódio ativo duplicado → formulário com autocomplete de plano/especialidade/CID), Detalhe do atendimento (diagnósticos, visita de hoje com atribuição de responsável, pendências, encerrar/concluir avaliação única, converter para acompanhamento, excluir com motivo), Altas/Histórico, e páginas admin (Dashboard de indicadores, Exportações, Equipe, Planos, Especialidades, Excluídos com restaurar/excluir definitivamente).
- CSS mobile-first próprio (`index.css`), testado visualmente em viewport 390px.
- PWA: `vite-plugin-pwa` com precache restrito ao shell estático, **nenhuma** regra de `runtimeCaching` e `navigateFallbackDenylist` para `/api/*` — o service worker gerado não intercepta nenhuma chamada de API. Ícones placeholder (192/512/512-maskable/apple-touch-icon) gerados via GD. Meta tags iOS + hint de instalação (`IosInstallHint`, seção 64). `OfflineBanner` com a mensagem exata da seção 63 quando offline.
- `npm run lint` (oxlint, 1 warning não bloqueante) e `npm run build` rodando sem erros.

## Testes de verdade no navegador (não só a suíte automatizada)
Sem `chromium-cli` disponível neste Windows, foi instalado Playwright isolado numa pasta de scratchpad (não como dependência do projeto) para dirigir um Chromium headless (viewport mobile 390×844) através de três fluxos reais:
1. **Fluxo assistencial completo**: login → troca de senha obrigatória → novo paciente → novo episódio com autocomplete de CID → atribuir responsável → visita realizada → pendência → resolver pendência → encerrar acompanhamento → confirmar saída de ativos e entrada em histórico. Sem erros de console além dos esperados.
2. **Dashboard e exportação**: dados de exemplo criados via API, dashboard renderizado e inspecionado visualmente (encontrou e corrigiu o bug do "patient-days" sem arredondamento), exportação XLSX baixada de verdade e validada abrindo o arquivo com PhpSpreadsheet.
3. **PWA**: build de produção servido via `vite preview`, service worker confirmado ativo, Cache Storage/localStorage/IndexedDB inspecionados após login + criação de paciente real — zero rastro de dados clínicos fora do shell estático (seção 116 do PRD).

## PENDENTE — próximas fases
- **Importação CSV de planos** (seção 16, opcional): não implementada.
- **Hardening final e revisão independente** (FASE 19, seção 132): ainda não realizada — IDOR/CSRF/XSS precisam de uma revisão dedicada antes do deploy real. Este é o próximo passo recomendado.
- **Deploy HostGator real** (FASE 20): arquivos (`public_html/index.php`, `.htaccess`, roteiro completo em `DEPLOYMENT_HOSTGATOR.md`) estão prontos; falta confirmar a estrutura exata do domínio/subpasta no cPanel real e executar o upload — isso exige acesso à hospedagem, que não está disponível neste ambiente de desenvolvimento.

TESTES EXECUTADOS: `php artisan test` (41 passando, inclusive `--order-by=random` 2x), `vendor/bin/pint --test` (limpo), `migrate:fresh --seed` contra SQLite real, `npm run lint`/`npm run build` do frontend, e quatro fluxos Playwright reais no navegador (fluxo assistencial completo, dashboard+exportação, PWA, sistema/zona de perigo).
TESTES APROVADOS: todos os executados acima.

PROBLEMAS CONHECIDOS:
- Ambiente de desenvolvimento não possui PHP/Composer nativos no PATH — usar sempre `C:\xampp\php\php.exe` e `C:\xampp\php\php.exe C:\xampp\php\composer.phar`.
- No Windows, `npm run dev`/`npm run preview` do Vite só ficaram acessíveis via `localhost` (IPv6 `::1`), não via `127.0.0.1` diretamente — sem impacto em produção (Linux/cPanel).
- Pastas dentro do OneDrive às vezes ganham o atributo **ReadOnly** (efeito colateral inofensivo do OneDrive/Explorer, não uma permissão real), o que faz `is_writable()` do PHP mentir e quebra comandos do Laravel (aconteceu com `bootstrap/cache`). Se acontecer de novo: `attrib -R <pasta> /S /D` no PowerShell resolve. Sem relevância para produção.
- **Atenção para o deploy**: `drfernandofreua.com.br/visitas` é uma subpasta, não a raiz do domínio. Se o document root do domínio não apontar diretamente para essa pasta, é necessário buildar o frontend com `VITE_BASE_PATH=/visitas/ npm run build` (já parametrizado em `vite.config.js` e `App.jsx`) — decidir isso durante a FASE 20 antes de gerar o build final.
- Projeto está dentro de uma pasta sincronizada pelo OneDrive — considerar excluir `vendor/`, `node_modules/`, `equipe/data`/`equipe/backups` da sincronização.

PRÓXIMO PASSO:
- Revisão de segurança independente (seção 132 do PRD): rotas sem autenticação, IDOR, bypass de RBAC, CSRF, XSS, SQL injection, mass assignment, soft delete ignorado por queries, dados excluídos vazando em dashboards/exports, sobrescrita de plano anterior, erros de timezone, cache PWA de dados clínicos, concorrência SQLite/`database is locked` — revisitando o código como se não tivesse sido eu quem o escreveu. Depois disso, o projeto está pronto para a FASE 20 (deploy real), que só pode ser executada com acesso à hospedagem HostGator.

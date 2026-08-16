# STATUS

ETAPA ATUAL: Revisão de segurança independente (seção 132 do PRD) concluída e todos os achados corrigidos e testados. **O sistema atende a todo o "Definition of Done" da seção 131 do PRD, exceto o deploy real em produção**, que depende de acesso à hospedagem HostGator (fora do alcance deste ambiente de desenvolvimento).

## Revisão de segurança independente (2026-08-15)
Um agente sem contexto prévio do desenvolvimento auditou os 20 itens da seção 132 do PRD lendo o código real (rotas, policies, controllers, migrations) e validando empiricamente os pontos críticos. Resultado: núcleo de segurança (RBAC, CSRF, SQL injection, XSS, mass assignment, soft delete, hard delete) **genuinamente sólido**; 10 achados reais de correção clínica/configuração, todos corrigidos nesta sessão com teste de regressão:

- **Alto**: `config/app.php` tinha `'timezone' => 'UTC'` hardcoded, ignorando `APP_TIMEZONE` do `.env` — aplicação operava 3h adiantada (maior impacto: visita diária noturna a partir das 21h BRT gravava no dia errado). Corrigido.
- **Médio**: `restore()` de episódio excluído não revalidava conflito de episódio ativo → corrigido + índice único parcial `admissions(patient_id) WHERE status='ACTIVE' AND deleted_at IS NULL` fecha também a janela de corrida em `store()`.
- **Médio**: `health_plan_name_snapshot` podia dessincronizar de `health_plan_id` ao editar só esse campo sem `payer_type` junto → corrigido.
- **Médio**: `.env.example` não refletia produção segura (`APP_DEBUG=true`, faltavam `SESSION_SECURE_COOKIE`/`HTTP_ONLY`/`SAME_SITE`/`APP_TIMEZONE`) → reescrito.
- **Médio**: sessões sobreviviam à desativação de usuário ou reset de senha → novo middleware `EnsureUserIsActive` derruba a sessão na próxima requisição.
- **Médio**: fallback SPA resolvia `public_path()` para dentro de `equipe/app/public` em vez de `public_html` → refresh direto em subrotas quebraria em produção. Corrigido via `Application::usePublicPath()`, validado servindo o build real.
- **Baixo** (todos corrigidos): `PatientController` sem Policy (adicionada `PatientPolicy`); `HealthPlanPolicy`/`MedicalSpecialtyPolicy::viewAny` permitiam qualquer médico acessar a listagem admin completa; sem rate limit em reautenticações sensíveis (`throttle:reauth` adicionado); login throttle só por usuário+IP permitia password spraying entre contas (limitador extra só por IP); `ip_hash` do audit log usava hash simples reversível (trocado por HMAC); `X-Request-Id` do cliente ia direto pro banco sem validar formato; validação de `hospital_discharge_at` em `UpdateAdmissionRequest` era um no-op silencioso (comparava contra campo inexistente); atribuição de responsável do dia aceitava usuário desativado.
- 7 novos testes de regressão, um para cada achado corrigido testável (51 testes no total, verificado também com `--order-by=random`).

Ver `IMPLEMENTATION_LOG.md` para o relatório completo com arquivo:linha de cada achado, e `SECURITY_CHECKLIST.md` para o checklist atualizado.

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

## Estrutura de deploy confirmada (2026-08-15)
`public_html` já hospeda o site principal do domínio — este app fica confirmadamente na subpasta `public_html/visitas/`, não no document root. Ajustado:
- `public_html/index.php` movido para `public_html/visitas/index.php`, caminho relativo para `equipe/app` corrigido (`../../equipe/app`).
- `public_html/.htaccess` movido para `public_html/visitas/.htaccess` (conteúdo não muda — regras já eram relativas).
- `bootstrap/app.php`: `usePublicPath()` agora aponta para `public_html/visitas` (não `public_html`).
- `vite.config.js`: build de produção passa a ser **obrigatoriamente** `VITE_BASE_PATH=/visitas/ npm run build`.
- **Bug real encontrado e corrigido durante a validação**: uma requisição sem header `Accept: application/json` (ex.: colar uma URL de API direto no navegador) fazia o Laravel tentar redirecionar para `route('login')`, que não existe nesta SPA — virava 500 em vez de um redirect/401 adequado. Clientes reais (axios sempre manda `Accept: application/json`) nunca passavam por esse caminho, por isso não tinha aparecido antes. Corrigido com `$middleware->redirectGuestsTo('/')` em `bootstrap/app.php`. Teste de regressão adicionado (52 testes no total).
- Validado servindo um build real (com os caminhos `/visitas/...` corretos) via `php artisan serve`: `/` e uma rota profunda funcionam, `/api/*` continua respondendo JSON.
- `DEPLOYMENT_HOSTGATOR.md` reescrito com instruções objetivas e definitivas (não mais uma decisão pendente) sobre o que sobe para `public_html/visitas/` e o que sobe para a pasta privada `equipe/`.

## PENDENTE — apenas isto
- **Deploy HostGator real** (FASE 20): todos os arquivos estão prontos e testados localmente com a estrutura de subpasta confirmada; falta o upload de verdade no cPanel, que exige acesso à hospedagem (não disponível neste ambiente de desenvolvimento).

Todo o resto do escopo do PRD (backend, frontend, dashboard, exportação, PWA, backups/restore, zona de perigo, importação CSV, revisão de segurança independente) está implementado e testado.

TESTES EXECUTADOS: `php artisan test` (52 passando, inclusive `--order-by=random` 2x), `vendor/bin/pint --test` (limpo), `migrate:fresh --seed` contra SQLite real, `npm run lint`/`npm run build` do frontend (inclusive com `VITE_BASE_PATH=/visitas/`), cinco fluxos Playwright reais no navegador, servidor real testado com a estrutura de subpasta confirmada, e uma revisão de segurança independente completa (seção 132).
TESTES APROVADOS: todos os executados acima.

PROBLEMAS CONHECIDOS:
- Ambiente de desenvolvimento não possui PHP/Composer nativos no PATH — usar sempre `C:\xampp\php\php.exe` e `C:\xampp\php\php.exe C:\xampp\php\composer.phar`.
- No Windows, `npm run dev`/`npm run preview` do Vite só ficaram acessíveis via `localhost` (IPv6 `::1`), não via `127.0.0.1` diretamente — sem impacto em produção (Linux/cPanel).
- Pastas dentro do OneDrive às vezes ganham o atributo **ReadOnly** (efeito colateral inofensivo do OneDrive/Explorer, não uma permissão real), o que faz `is_writable()` do PHP mentir e quebra comandos do Laravel (aconteceu com `bootstrap/cache`). Se acontecer de novo: `attrib -R <pasta> /S /D` no PowerShell resolve. Sem relevância para produção.
- **Atenção para o deploy**: `drfernandofreua.com.br/visitas` é uma subpasta, não a raiz do domínio. Se o document root do domínio não apontar diretamente para essa pasta, é necessário buildar o frontend com `VITE_BASE_PATH=/visitas/ npm run build` (já parametrizado em `vite.config.js` e `App.jsx`) — decidir isso durante a FASE 20 antes de gerar o build final.
- Projeto está dentro de uma pasta sincronizada pelo OneDrive — considerar excluir `vendor/`, `node_modules/`, `equipe/data`/`equipe/backups` da sincronização.

PRÓXIMO PASSO:
- FASE 20 (deploy real): confirmar com o cliente/hosting a estrutura exata do domínio/subpasta no cPanel (document root dedicado vs. subpasta compartilhada — ver nota em `DEPLOYMENT_HOSTGATOR.md`), depois seguir o passo a passo já documentado. É o único item que exige acesso a algo fora deste ambiente de desenvolvimento.

# SECURITY CHECKLIST

Marcar cada item apenas após verificação real (teste executado), não por inspeção visual.

## Autenticação e sessão
- [x] Login via sessão/cookie Laravel (não token em localStorage/sessionStorage/IndexedDB) — sem Sanctum, guard `web` padrão
- [x] Cookies: HttpOnly, SameSite=Lax sempre; Secure em produção (`.env.example` já traz `SESSION_SECURE_COOKIE=true`; local dev sobrescreve para `false` por não ter HTTPS)
- [ ] HTTPS obrigatório em produção — só pode ser confirmado após o deploy real (FASE 20)
- [x] Login throttling ativo — `RateLimiter::for('login')`: 5/min por usuário+IP **e** 20/min só por IP (evita password spraying entre várias contas)
- [x] Regeneração de sessão no login — `$request->session()->regenerate()`
- [x] Senha padrão `senha@1234` força `must_change_password=true` e bloqueia navegação até troca — testado (`AuthTest`)
- [x] Hash de senha via mecanismo padrão do Laravel (bcrypt) — nunca texto puro
- [x] Não existe rota de self-signup — usuários só são criados por ADMIN (`Admin\UserController`)
- [x] Desativar um usuário derruba a sessão dele imediatamente — middleware `EnsureUserIsActive` (`active`), testado (`AuthTest::deactivating_user_kills_their_live_session_immediately`)
- [x] Reautenticação por senha em ações irreversíveis (hard delete, zona de perigo) tem rate limit próprio (`throttle:reauth`, 5/min)

## RBAC
- [x] Toda rota sensível protegida por Policy/Middleware no backend (nunca apenas ocultar botão no frontend) — Policies para Admission/User/HealthPlan/MedicalSpecialty/Patient
- [x] PHYSICIAN não consegue hard delete via API (testar chamada direta → 403) — testado (`SoftDeleteTest`)
- [x] Apenas ADMIN acessa: Excluídos, Exportações, Backups (leitura), Sistema, Zona de perigo, gestão de usuários/planos/especialidades — todos testados
- [x] Listagem administrativa completa de planos/especialidades (`/admin/health-plans`, `/admin/medical-specialties`) é admin-only, distinta do autocomplete (`/health-plans/search`) que continua aberto a qualquer médico — corrigido após a revisão independente (`HealthPlanPolicy`/`MedicalSpecialtyPolicy::viewAny`), testado

## Proteções web
- [x] CSRF ativo em todas as rotas de estado mutável — toda a API vive dentro do grupo `web` (nunca excluída via `$except`/`withoutMiddleware`, confirmado por busca no código)
- [x] Rate limiting nas rotas de autenticação, exportação e reautenticação sensível
- [x] Form Requests validando entradas — zero ocorrências de `$request->all()` em todo o backend, confirmado por busca no código
- [x] Content-Security-Policy, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, X-Frame-Options configurados (`SecurityHeaders` middleware, global)
- [x] APP_DEBUG=false em produção (`.env.example` e `DEPLOYMENT_HOSTGATOR.md`); nenhum stack trace exposto

## Dados sensíveis / arquivos
- [x] `.env` fora de `public_html` — confirmado, `public_html/` só tem `index.php`, `.htaccess`, `assets/`
- [x] SQLite fora de `public_html` — `equipe/data/`, sibling de `public_html/`
- [x] `vendor/` não exposto — Laravel vive inteiro em `equipe/app/`, fora do document root
- [x] Backups/exports fora de `public_html`, não listáveis publicamente — `equipe/backups/`, `equipe/exports/`
- [x] Exports entregues via rota autenticada, nome não previsível (uuid parcial), sem path traversal (`basename()`), removidos logo após o download e por `exports:cleanup` (órfãos)
- [x] Nenhum `phpinfo()` público — `neurologia:preflight` é comando Artisan, nunca uma página web

## Soft delete / hard delete
- [x] SoftDeletes aplicado a Admission — testado que a linha permanece no banco após soft delete
- [x] Queries padrão (ativos, altas, dashboard, exportação) excluem `deleted_at != null` por padrão — `App\Services\AdmissionFilters` só inclui excluídos com opt-in explícito de ADMIN
- [x] Hard delete (`forceDelete`) somente ADMIN, com reautenticação (senha) + frase de confirmação "EXCLUIR DEFINITIVAMENTE" + motivo — testado
- [x] Tentativa de hard delete por PHYSICIAN via API → 403 (testado diretamente, não apenas pela UI)
- [x] Tombstone de auditoria do hard delete não contém conteúdo clínico
- [x] Restaurar um episódio excluído revalida conflito de episódio ativo (corrigido após a revisão — antes permitia dois episódios ACTIVE simultâneos para o mesmo paciente)

## PWA / cache
- [x] Service worker cacheia apenas shell estático — `runtimeCaching: []`, `globPatterns` restrito a JS/CSS/HTML/ícones, confirmado inspecionando o `sw.js` compilado
- [x] Nenhum dado clínico em Cache Storage, IndexedDB ou LocalStorage — verificado via Playwright após login real + criação de paciente real

## Logs
- [x] Logs não contêm senha, cookie, token de sessão, história clínica completa
- [x] Audit log não duplica PHI — só nomes de campos alterados, nunca valores
- [x] `ip_hash` do audit log usa HMAC (não hash simples, que é reversível para IPv4 via rainbow table)
- [x] `request_id` do audit log valida formato UUID antes de aceitar valor vindo do cliente (X-Request-Id) — não confia cegamente em header controlado pelo usuário

## Concorrência / integridade
- [x] WAL + busy_timeout configurados — confirmado via `PRAGMA` real
- [x] Optimistic locking (`version`) previne sobrescrita silenciosa em edição concorrente — testado, retorna 409
- [x] `PRAGMA integrity_check` disponível para ADMIN
- [x] Índice único parcial `admissions(patient_id) WHERE status='ACTIVE' AND deleted_at IS NULL` — fecha a janela de corrida entre dois `POST /api/admissions` simultâneos para o mesmo paciente (a checagem em PHP sozinha tinha TOCTOU)

## Correção assistencial (achados da revisão de segurança, não só segurança)
- [x] Timezone: `config/app.php` estava com `'timezone' => 'UTC'` hardcoded, ignorando `APP_TIMEZONE=America/Sao_Paulo` do `.env` — a aplicação inteira operava 3h adiantada, com maior impacto na visita diária noturna (round_date de "hoje" virava o dia seguinte a partir das 21h BRT). **Corrigido e testado** (`TimezoneConfigTest`).
- [x] `health_plan_name_snapshot` podia dessincronizar de `health_plan_id` ao editar um episódio enviando só esse campo sem `payer_type` junto — corrigido e testado.
- [x] Validação de `hospital_discharge_at` em `UpdateAdmissionRequest` era um no-op silencioso (comparava contra um campo inexistente na request) — permitia gravar alta hospitalar anterior à entrada. Corrigido e testado.
- [x] Atribuição de responsável do dia (`DailyRoundController::assign`) aceitava usuário desativado — corrigido (`Rule::exists(...)->where('active', true)`) e testado.
- [x] Fallback SPA (`public_path('build/index.html')`) resolvia para dentro de `equipe/app/public`, não `public_html` — refresh direto em qualquer subrota quebraria em produção. Corrigido via `Application::usePublicPath()` em `bootstrap/app.php`, validado servindo o build real via `php artisan serve` e testando `/` e uma rota profunda.

## Revisão de segurança independente (seção 132 do PRD)

Realizada em 2026-08-15 por um agente sem contexto prévio do desenvolvimento, cobrindo os 20 itens da seção 132 (rotas sem autenticação, IDOR, bypass de RBAC, CSRF, XSS, SQL injection, mass assignment, sessões inseguras, dados expostos em public_html, backups/exports públicos, soft delete ignorado, dados excluídos em dashboards, hard delete, bugs de reinternação, sobrescrita de plano, timezone, cache PWA, concorrência SQLite, migrations, e uma varredura aberta por outras vulnerabilidades). Resultado: **10 achados reais** (1 alto — timezone —, 5 médios, o resto baixo), todos corrigidos e cobertos por teste de regressão nesta mesma sessão. Ver `IMPLEMENTATION_LOG.md` para o relatório completo com arquivo:linha de cada achado.

**Itens aceitos como estão** (documentados, não corrigidos, por serem de menor risco ou exigirem decisão de produto):
- `Options -Indexes` do `.htaccess` está aninhado em `<IfModule mod_negotiation.c>` — se esse módulo não estiver no HostGator, listagem de diretório de `public_html/assets/` fica possível. Verificar no primeiro deploy.
- `EnsureUserIsActive` e a checagem de senha em `AuthController::login` cobrem o essencial; não foi implementado `AuthenticateSession` completo do Laravel (exigiria coluna `remember_token`, que este schema não tem por decisão de design — sem "lembrar-me").

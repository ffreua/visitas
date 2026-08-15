# SECURITY CHECKLIST

Marcar cada item apenas após verificação real (teste executado), não por inspeção visual.

## Autenticação e sessão
- [x] Login via sessão/cookie Laravel (não token em localStorage/sessionStorage/IndexedDB) — sem Sanctum, guard `web` padrão
- [ ] Cookies: HttpOnly, Secure, SameSite=Lax — `HttpOnly`/`SameSite=lax` configurados; `Secure` só é validável com HTTPS real em produção
- [ ] HTTPS obrigatório em produção — pendente até o deploy (FASE 20)
- [x] Login throttling (rate limiting) ativo — `RateLimiter::for('login', ...)`, 5/min por usuário+IP
- [x] Regeneração de sessão no login — `$request->session()->regenerate()`
- [x] Senha padrão `senha@1234` força `must_change_password=true` e bloqueia navegação até troca — testado (`AuthTest`)
- [x] Hash de senha via mecanismo padrão do Laravel (bcrypt) — nunca texto puro
- [x] Não existe rota de self-signup — usuários só são criados por ADMIN (`Admin\UserController`)

## RBAC
- [x] Toda rota sensível protegida por Policy/Middleware no backend (nunca apenas ocultar botão no frontend) — Policies para Admission/User/HealthPlan/MedicalSpecialty
- [x] PHYSICIAN não consegue hard delete via API (testar chamada direta → 403) — testado (`SoftDeleteTest`)
- [x] Apenas ADMIN acessa: Excluídos, gestão de usuários/planos/especialidades — testado; Exportações/Backups/Sistema/Zona de perigo ainda não têm rotas (pendente)

## Proteções web
- [ ] CSRF ativo em todas as rotas de estado mutável
- [ ] Rate limiting nas rotas de autenticação e exportação
- [ ] Form Requests validando entradas (nunca mass assignment sem `$fillable`/`$guarded` revisado)
- [ ] Content-Security-Policy, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, frame-ancestors configurados
- [ ] APP_DEBUG=false em produção; nenhum stack trace exposto

## Dados sensíveis / arquivos
- [ ] `.env` fora de `public_html`
- [ ] SQLite (`neurologia.sqlite3` + `-wal`/`-shm`) fora de `public_html`
- [ ] `vendor/` completo não exposto publicamente onde desnecessário
- [ ] Backups fora de `public_html`, não listáveis publicamente
- [ ] Exports gerados fora de `public_html`, entregues via rota autenticada, removidos após período curto, sem link público previsível
- [ ] Nenhum `phpinfo()` público em produção (script de diagnóstico removível após instalação)

## Soft delete / hard delete
- [x] SoftDeletes aplicado a Admission — testado que a linha permanece no banco após soft delete
- [x] Queries padrão (ativos, altas) excluem `deleted_at != null` por padrão (comportamento nativo do SoftDeletes) — dashboards/exports ainda não existem
- [x] Hard delete (`forceDelete`) somente ADMIN, com reautenticação (senha) + frase de confirmação "EXCLUIR DEFINITIVAMENTE" + motivo — testado
- [x] Tentativa de hard delete por PHYSICIAN via API → 403 (testado diretamente, não apenas pela UI)
- [x] Tombstone de auditoria do hard delete não contém conteúdo clínico (`entity_type`, `entity_id`=uuid, motivo administrativo)

## PWA / cache
- [ ] Service worker cacheia apenas shell estático (HTML/CSS/JS/ícones/fontes) — nunca `/api/*`
- [ ] Nenhum dado clínico em Cache Storage, IndexedDB ou LocalStorage (verificado via DevTools após navegação real)

## Logs
- [ ] Logs não contêm senha, cookie, token de sessão, história clínica completa
- [ ] Audit log não duplica PHI (história inteira, pendências inteiras) — apenas metadados de ação

## Concorrência / integridade
- [x] WAL + busy_timeout configurados — confirmado via `PRAGMA` real (não apenas config), ver IMPLEMENTATION_LOG
- [x] Optimistic locking (`version`) previne sobrescrita silenciosa em edição concorrente — testado, retorna 409
- [x] `PRAGMA integrity_check` disponível para ADMIN — `GET /api/admin/system/integrity-check`

## Revisão final independente (seção 132 do PRD)
- [ ] Rotas sem autenticação
- [ ] IDOR (acesso a admission/patient de outro contexto por manipulação de ID)
- [ ] Bypass de RBAC
- [ ] CSRF / XSS / SQL injection
- [ ] Mass assignment
- [ ] Registros soft-deleted vazando para dashboards/exports
- [ ] Bugs de reinternação / sobrescrita de plano anterior
- [ ] Erros de timezone (America/Sao_Paulo)
- [ ] `database is locked` sob concorrência

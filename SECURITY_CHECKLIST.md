# SECURITY CHECKLIST

Marcar cada item apenas após verificação real (teste executado), não por inspeção visual.

## Autenticação e sessão
- [ ] Login via sessão/cookie Laravel (não token em localStorage/sessionStorage/IndexedDB)
- [ ] Cookies: HttpOnly, Secure, SameSite=Lax
- [ ] HTTPS obrigatório em produção
- [ ] Login throttling (rate limiting) ativo
- [ ] Regeneração de sessão no login
- [ ] Senha padrão `senha@1234` força `must_change_password=true` e bloqueia navegação até troca
- [ ] Hash de senha via Argon2id (ou mecanismo Laravel padrão se Argon2id indisponível) — nunca texto puro
- [ ] Não existe rota de self-signup

## RBAC
- [ ] Toda rota sensível protegida por Policy/Middleware no backend (nunca apenas ocultar botão no frontend)
- [ ] PHYSICIAN não consegue hard delete via API (testar chamada direta → 403)
- [ ] Apenas ADMIN acessa: Excluídos, Exportações, Backups, Sistema, Zona de perigo, gestão de usuários/planos/especialidades

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
- [ ] SoftDeletes aplicado a Admission (e Patient se aplicável)
- [ ] Queries padrão (ativos, altas, dashboards, exports) excluem `deleted_at != null` por padrão
- [ ] Hard delete (`forceDelete`) somente ADMIN, com reautenticação + frase de confirmação + motivo
- [ ] Tentativa de hard delete por PHYSICIAN via API → 403 (testado diretamente, não apenas pela UI)
- [ ] Tombstone de auditoria do hard delete não contém conteúdo clínico

## PWA / cache
- [ ] Service worker cacheia apenas shell estático (HTML/CSS/JS/ícones/fontes) — nunca `/api/*`
- [ ] Nenhum dado clínico em Cache Storage, IndexedDB ou LocalStorage (verificado via DevTools após navegação real)

## Logs
- [ ] Logs não contêm senha, cookie, token de sessão, história clínica completa
- [ ] Audit log não duplica PHI (história inteira, pendências inteiras) — apenas metadados de ação

## Concorrência / integridade
- [ ] WAL + busy_timeout configurados
- [ ] Optimistic locking (`version`) previne sobrescrita silenciosa em edição concorrente
- [ ] `PRAGMA integrity_check` disponível para ADMIN

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

# STATUS

ETAPA ATUAL: FASE 10 concluída (backend) — Pendências, visita diária, avaliação única, soft delete/restore/hard delete, backups. Frontend React ainda não iniciado (FASE 1 do frontend pendente).

ETAPAS CONCLUÍDAS:

## Backend (Laravel 12 + SQLite)
- FASE 0 — Pre-flight: comando `php artisan neurologia:preflight` valida PHP/extensões/permissões de escrita (com teste real de escrita, não `is_writable()`, que é pouco confiável no Windows).
- FASE 1 — Laravel 12.66.0 instalado em `equipe/app` (Laravel 11 está fora do período de suporte de segurança em 2026; ver IMPLEMENTATION_LOG.md).
- FASE 2 — Schema completo via migrations: users, patients, health_plans, medical_specialties, cid10, admissions, admission_diagnoses, pending_items, daily_rounds, audit_logs. SQLite com WAL + busy_timeout=5000 + foreign_keys=ON confirmados via teste real (não apenas configurado).
- FASE 3 — Autenticação por sessão/cookie Laravel (sem Sanctum, mesma origem), troca de senha obrigatória (`must_change_password`), RBAC via Policies (AdmissionPolicy, UserPolicy, HealthPlanPolicy, MedicalSpecialtyPolicy), rate limiting de login, security headers (CSP/X-Frame-Options/etc.).
- FASE 4 — Patients + Admissions: busca por prontuário (reinternação), bloqueio de episódio ativo duplicado, snapshot do plano de saúde no episódio, optimistic locking (`version` + 409 em edição concorrente), Form Requests com todas as validações da seção 106 do PRD.
- FASE 5 — Planos de saúde: autocomplete accent/case-insensitive, CRUD admin-only, nunca apaga plano usado (apenas `active=false`).
- FASE 6 — CID-10: seed inicial (~24 códigos comuns em Neurologia) + autocomplete + comando `php artisan cid10:import {csv}` para a tabela completa em produção.
- FASE 7/8 — Interconsulta (especialidade + horário obrigatórios) e Avaliação Única (conclusão fecha o episódio e preenche `first_neurology_evaluation_at` automaticamente; `convert-to-followup` para converter em acompanhamento).
- FASE 9 — Visita diária (`DailyRound`): responsável reseta a cada dia sem apagar histórico anterior (testado explicitamente).
- FASE 10 — Pendências (CRUD simples ligado ao episódio).
- FASE 12 (parcial) — SoftDelete real para médicos (nunca hard delete), rota admin de restauração e hard delete com reautenticação de senha + frase de confirmação "EXCLUIR DEFINITIVAMENTE"; testado que médico recebe 403 em qualquer uma dessas rotas mesmo chamando a API diretamente.
- FASE 16 (parcial) — `php artisan neurologia:backup`: checkpoint WAL antes de copiar, checksum SHA-256, retenção diária/semanal/mensal configurável (`config/neurologia.php`), com algoritmo de retenção coberto por testes unitários dedicados.

## Testes
- 25 testes automatizados passando (`php artisan test`), cobrindo os cenários críticos do PRD (seções 107-114): login/RBAC/troca de senha obrigatória, prontuário único e reinternação sem sobrescrever plano do episódio anterior, bloqueio de episódio ativo duplicado, interconsulta/particular/plano obrigatórios, avaliação única fechando corretamente, soft delete/restore/hard delete com RBAC real testado via chamada de API, reset diário do responsável sem perder histórico, optimistic locking com 409 em edição concorrente, e a lógica de retenção de backups.
- Laravel Pint (lint) limpo.

## PENDENTE (não iniciado ou parcial) — próximas fases
- **Frontend React** (FASE 1 do frontend): scaffold Vite+React existe em `frontend/`, mas nenhuma tela foi construída ainda (login, dashboard mobile-first, cadastro de paciente/episódio, autocomplete de plano/CID, visita diária, pendências, admin).
- **Dashboard administrativo e indicadores** (FASES 14, seções 55/67-80 do PRD): nenhum endpoint de indicadores/KPIs foi implementado.
- **Exportação XLSX** (FASE 15, seções 81-88): não iniciada — precisa de PhpSpreadsheet, geração fora de `public_html`, entrega autenticada, pseudonimização opcional.
- **Restore de backup** (seção 95): comando de backup existe; comando/rota de restore ainda não implementado.
- **Zona de perigo / zerar dados clínicos** (seções 96-97): não implementado.
- **PWA** (FASE 17): manifest.webmanifest, service worker (shell-only, nunca cachear `/api/*`), ícones — não iniciado.
- **QA mobile** (FASE 18): não aplicável ainda, sem frontend.
- **Hardening final e revisão independente** (FASES 19, seção 132): pendente até o frontend existir (IDOR/CSRF/XSS precisam ser testados fim a fim).
- **Deploy HostGator real** (FASE 20): `DEPLOYMENT_HOSTGATOR.md` tem o roteiro, mas nada foi publicado.
- **Admin**: dashboard de indicadores, painel de qualidade dos dados (seção 80), gestão de excluídos via UI (rotas de API já existem), importação CSV de planos (opcional, seção 16).

TESTES EXECUTADOS: `php artisan test` (25 passando), `php artisan vendor/bin/pint --test` (limpo), `php artisan migrate:fresh --seed` contra o SQLite real de desenvolvimento, `php artisan neurologia:preflight`, `php artisan neurologia:backup` (validado manualmente, artefato de teste removido).
TESTES APROVADOS: todos os executados acima.
PROBLEMAS CONHECIDOS:
- Ambiente de desenvolvimento não possui PHP/Composer nativos no PATH — usar sempre `C:\xampp\php\php.exe` e `C:\xampp\php\php.exe C:\xampp\php\composer.phar`.
- Projeto está dentro de uma pasta sincronizada pelo OneDrive — isso pode deixar `npm install`/`composer install` mais lentos; considerar excluir `vendor/`, `node_modules/` e `equipe/data`/`equipe/backups` da sincronização do OneDrive.
- `is_writable()` do PHP é pouco confiável no Windows para diretórios — os comandos de preflight/backup usam um teste real de escrita (`App\Services\DirectoryWriteCheck`) em vez de confiar nessa função.

PRÓXIMO PASSO:
- Construir o frontend React (login, troca de senha obrigatória, dashboard mobile-first com os cards/filtros da seção 55-58, fluxo completo de novo episódio com autocomplete de plano/especialidade/CID, tela de detalhe do caso, telas admin).
- Depois: dashboard de indicadores, exportação XLSX, PWA, e só então a revisão de segurança independente (seção 132) fim a fim antes de cogitar deploy real em produção.

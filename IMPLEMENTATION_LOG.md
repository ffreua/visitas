# IMPLEMENTATION LOG

Registro cronológico de decisões técnicas e etapas concluídas. Ordem: mais recente no topo.

## 2026-08-15 — Backend FASES 2 a 10 (parcial 12/16)

- **Users**: reescrita da migration padrão do Laravel para o schema do PRD (`uuid`, `full_name`, `crm`, `username`, `role`, `must_change_password`, `active`, `last_login_at`) — sem `email`, sem tabela `password_reset_tokens`/`sessions` no banco (`SESSION_DRIVER=file`, sem fluxo de reset por e-mail, admin reseta senha diretamente).
- Removidas as migrations de `cache`/`jobs` do Laravel padrão: `CACHE_STORE=file` e `QUEUE_CONNECTION=sync` (execução síncrona, sem worker residente) tornam essas tabelas desnecessárias — alinhado com a restrição de "sem processos residentes" do PRD.
- Migrations criadas na ordem de dependência correta (health_plans, medical_specialties, cid10, patients, admissions, admission_diagnoses, pending_items, daily_rounds, audit_logs) — todas testadas com `migrate:fresh`.
- `Admission` usa `SoftDeletes` real do Laravel + coluna `version` para optimistic locking (`assertVersion()` lança `StaleAdmissionException` → 409). O hook `updating` incrementa `version` automaticamente a partir do valor original carregado do banco.
- **Bug encontrado e corrigido**: colunas com apenas default no banco (`version`) ou nunca atribuídas em memória (`first_neurology_evaluation_at`, etc.) ficavam ausentes do JSON de resposta do `store()` porque `Model::create()` não repopula atributos a partir de defaults do SQL — corrigido com `$admission->refresh()` após criar, e com `$admission->version ??= 1` explícito no hook `creating`.
- Autenticação: sessão/cookie Laravel padrão (guard `web`), sem Sanctum — todas as rotas de API ficam dentro do grupo `web` do `routes/web.php` (não `routes/api.php`) propositalmente, para ganhar CSRF + sessão automaticamente já que o frontend é mesma origem. Rota SPA fallback (`GET /{any}`) serve `public_html/build/index.html` para o roteamento client-side.
- RBAC via Policies do Laravel (auto-discovery por convenção de nome), não por checagem manual de `role` espalhada pelos controllers.
- `App\Http\Controllers\Controller` do Laravel 12 vem **sem** `AuthorizesRequests`/`ValidatesRequests` por padrão (mudança do skeleton) — precisou adicionar os traits manualmente, senão `$this->authorize()` não existe.
- **Bug encontrado e corrigido**: `DailyRoundController` usava `firstOrNew(['round_date' => ...])` comparando string exata, mas o cast `date` do Eloquent serializa com componente de hora ao salvar (`2026-08-14 00:00:00`), quebrando o lookup e causando `UNIQUE constraint failed` ao tentar completar uma visita logo após atribuir o responsável do dia. Corrigido usando `whereDate()` para a busca (robusto a variações de formato) e `make()` explícito quando não encontrado.
- **Bug encontrado e corrigido**: algoritmo de retenção de backups (diário/semanal/mensal) tinha uma condição de corrida entre faixas — um backup já mantido pela faixa diária era pulado ao calcular o "dono" do balde semanal, permitindo que um backup mais antigo roubasse aquela vaga semanal por engano. Corrigido calculando os representantes de cada faixa de forma independente (por recência dentro do balde) e só then mesclando os conjuntos — coberto por teste unitário dedicado (`BackupRetentionPolicyTest`).
- `neurologia:backup`: `PRAGMA wal_checkpoint(TRUNCATE)` antes de copiar o arquivo (nunca copiar um `.sqlite3` "cru" com WAL ativo), checksum SHA-256, retenção configurável via `config/neurologia.php`.
- `neurologia:preflight` e `neurologia:backup` usam `App\Services\DirectoryWriteCheck` (escreve e remove um arquivo de teste) em vez de `is_writable()`, que é conhecidamente pouco confiável para diretórios no Windows — necessário porque o ambiente de desenvolvimento é Windows mas a produção é Linux/cPanel.
- 25 testes automatizados cobrindo os cenários críticos das seções 107-114 do PRD (login/RBAC, prontuário único, reinternação sem sobrescrever plano anterior, avaliação única, soft/restore/hard delete com RBAC real via API, reset diário do responsável, optimistic locking). Laravel Pint limpo.

## 2026-08-15 — FASE 0 + início FASE 1

- Ambiente local Windows sem PHP/Composer no PATH. Localizado PHP 8.2.12 via instalação XAMPP existente em `C:\xampp\php`.
- Habilitadas extensões necessárias no `C:\xampp\php\php.ini`: `intl`, `sqlite3`, `fileinfo` (já ativas: pdo_sqlite, mbstring, openssl, session, json, curl, dom, tokenizer, ctype, hash, filter, xml).
- Removida diretiva duplicada `extension=openssl` (openssl já é compilado estaticamente nesse build) para eliminar warning.
- Composer 2.10.2 instalado localmente como `C:\xampp\php\composer.phar` (sem instalação global no sistema).
- Estrutura de pastas HostGator criada localmente em espelho: `equipe/app`, `equipe/data`, `equipe/backups`, `equipe/exports`, `equipe/logs`, `public_html`.
- Git inicializado no diretório raiz do projeto; branch renomeada para `main`; remote `origin` configurado para `https://github.com/ffreua/visitas.git` (repositório estava vazio).
- Frontend: `npm create vite@latest frontend -- --template react`, seguido de `npm install` + `react-router-dom` + `axios` + `vite-plugin-pwa` (dev dependency). 0 vulnerabilidades reportadas pelo npm audit.
- Backend: `composer create-project laravel/laravel equipe/app` — nota: a primeira tentativa fixando `"^11.0"` falhou porque o Composer 2.10 bloqueia por padrão instalação de pacotes com advisories de segurança conhecidas nas versões antigas do range 11.31–11.55; resolvido usando a versão mais recente do laravel/laravel sem pin de versão (Composer resolve automaticamente para uma versão não afetada).

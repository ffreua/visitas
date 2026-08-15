# STATUS

ETAPA ATUAL: FASE 1 — Laravel + React funcionando (scaffolding inicial)

ETAPAS CONCLUÍDAS:
- FASE 0 — Pre-flight local: PHP 8.2.12 (XAMPP) com pdo_sqlite, sqlite3, intl, openssl, fileinfo, mbstring, session, json ativos. Composer 2.10.2 instalado localmente em C:\xampp\php\composer.phar.
- Estrutura de diretórios criada: equipe/app, equipe/data, equipe/backups, equipe/exports, equipe/logs, public_html.
- Repositório git inicializado, remoto origin apontando para https://github.com/ffreua/visitas.git (branch main).
- Frontend: scaffold Vite + React em /frontend, dependências base + react-router-dom + axios + vite-plugin-pwa instaladas (0 vulnerabilidades).

EM ANDAMENTO:
- Laravel 11 sendo instalado via composer create-project em equipe/app (rodando em background).

TESTES EXECUTADOS: nenhum ainda (aguardando conclusão do scaffold).
TESTES APROVADOS: —
PROBLEMAS CONHECIDOS:
- Ambiente de desenvolvimento não possui PHP/Composer nativos no PATH; usar sempre `C:\xampp\php\php.exe` e `C:\xampp\php\php.exe C:\xampp\php\composer.phar`.
- Projeto está dentro de uma pasta sincronizada pelo OneDrive — isso pode deixar `npm install`/`composer install` mais lentos e, em produção, `vendor/` e `node_modules/` NÃO devem ser sincronizados pelo OneDrive (considerar excluir essas pastas da sincronização).

PRÓXIMO PASSO:
- Confirmar conclusão do composer create-project, rodar `php artisan test` de baseline, configurar .env para SQLite apontando para ~/equipe/data/neurologia.sqlite3, e commitar o scaffold inicial.
- Em seguida, iniciar FASE 2 (migrations SQLite: Patient, Admission, HealthPlan, MedicalSpecialty, CID10, AdmissionDiagnosis, PendingItem, DailyRound, User, AuditLog).

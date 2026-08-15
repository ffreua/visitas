# DEPLOYMENT — HostGator / cPanel

Domínio de produção: `drfernandofreua.com.br/visitas`.

## Estrutura no servidor

```text
/home/USUARIO/
├── equipe/
│   ├── app/            (Laravel: app, bootstrap, config, database, routes, storage, vendor, .env)
│   ├── data/            neurologia.sqlite3
│   ├── backups/
│   ├── exports/
│   └── logs/
└── public_html/
    ├── index.php        (já pronto neste repo — carrega o Laravel de fora de public_html)
    ├── .htaccess         (já pronto neste repo)
    ├── build/            (gerado por `npm run build`, não versionado)
    ├── assets/
    ├── icons/
    ├── manifest.webmanifest
    └── sw.js
```

> **Decisão pendente**: `public_html/index.php` e `.htaccess` deste repositório assumem que `public_html/` é o *document root* dedicado do domínio/subdomínio (sibling direto de `equipe/`, exatamente como no diagrama do PRD). Se `visitas` acabar sendo uma **subpasta** dentro de um `public_html` compartilhado com outro conteúdo do domínio principal, ajustar:
> - `public_html/index.php`: trocar `__DIR__.'/../equipe/app'` por `__DIR__.'/../../equipe/app'` (um nível a mais).
> - Build do frontend: rodar com `VITE_BASE_PATH=/visitas/ npm run build` (já suportado em `vite.config.js` e `App.jsx`) em vez do build padrão (`base: '/'`).
> Essa decisão só pode ser tomada com acesso real ao cPanel para ver como o addon domain/subdomínio foi configurado.

## Passo a passo

1. **Local**: `cd frontend && npm run build` (ou `VITE_BASE_PATH=/visitas/ npm run build`, ver nota acima) → gera `frontend/dist`. Copiar todo o conteúdo de `dist/` para dentro de `public_html/` (os arquivos `build/assets/*.js/css`, `manifest.webmanifest`, `sw.js`, `workbox-*.js`, `registerSW.js`, `icons/`, `favicon.svg`, `index.html` como `build/index.html` — ver a rota SPA fallback em `routes/web.php`, que espera `public_path('build/index.html')`).
2. **Local**: `cd equipe/app && composer install --no-dev --optimize-autoloader` → enviar `vendor/` junto com a aplicação (o servidor HostGator não precisa rodar `composer`).
3. Upload de `equipe/app` (sem o `.env` de desenvolvimento) para `~/equipe/app`, e de `public_html/index.php` + `.htaccess` (já neste repo) para `~/public_html/`, via cPanel File Manager ou FTP/SFTP.
4. Criar `.env` de produção diretamente no servidor (nunca via Git) — ver seção "ENV produção" abaixo.
5. Gerar `APP_KEY` (`php artisan key:generate`) se o cPanel oferecer terminal PHP; caso contrário gerar localmente e colar no `.env` do servidor.
6. Criar `~/equipe/data/neurologia.sqlite3` (arquivo vazio) e rodar `php artisan migrate --force` + `php artisan db:seed --force` no terminal do cPanel (`DatabaseSeeder` cria o admin inicial `admin`/`senha@1234` com troca obrigatória, além de especialidades/planos/CID-10 de exemplo). Depois, importar a tabela completa de CID-10 com `php artisan cid10:import {arquivo.csv}` (o seeder padrão só tem ~24 códigos de exemplo para desenvolvimento).
7. Rodar `php artisan neurologia:preflight` e conferir que tudo aparece `[OK]`; **remover o comando/pre-flight não é necessário** (não expõe nada sensível, mas pode ser removido do código se preferir não deixá-lo disponível).
8. Confirmar permissões de escrita em `~/equipe/data`, `~/equipe/backups`, `~/equipe/exports`, `~/equipe/app/storage`.
9. `php artisan config:cache && php artisan route:cache && php artisan view:cache` — somente depois que `.env` estiver 100% definitivo.
10. Configurar Cron Job do cPanel chamando `php artisan schedule:run` a cada minuto (ver seção "Agendamento" abaixo) — opcional, mas recomendado.

## ENV produção (referência)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://drfernandofreua.com.br/visitas
APP_TIMEZONE=America/Sao_Paulo

DB_CONNECTION=sqlite
DB_DATABASE=/home/USUARIO/equipe/data/neurologia.sqlite3

SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

CACHE_STORE=file
QUEUE_CONNECTION=sync

LOG_LEVEL=warning
```

## Pre-flight

`php artisan neurologia:preflight` checa: PHP >= 8.2, extensões `pdo`, `pdo_sqlite`, `sqlite3`, `openssl`, `mbstring`, `json`, `fileinfo`, `intl`, `session`; permissão de escrita real (não só `is_writable()`) em `equipe/data`, `equipe/backups`, `storage/`.

## Agendamento (opcional, mas recomendado)

Cron Job do cPanel, uma linha, a cada minuto:

```bash
* * * * * cd /home/USUARIO/equipe/app && php artisan schedule:run >> /dev/null 2>&1
```

Isso aciona (já configurado em `routes/console.php`):
- `neurologia:backup` — diariamente às 03:00 (checkpoint WAL + checksum + retenção diária/semanal/mensal).
- `exports:cleanup` — a cada hora (remove exports gerados e nunca baixados).

A aplicação funciona normalmente sem cron — backup vira uma tarefa manual do admin (`php artisan neurologia:backup` via terminal) nesse caso.

## Backup e restore

- **Backup**: `php artisan neurologia:backup` (ou via cron acima). Cria `equipe/backups/neurologia_{timestamp}.sqlite3`, verifica com `PRAGMA integrity_check` antes de manter, calcula checksum SHA-256, registra em `audit_logs`.
- **Restore**: **exclusivamente via CLI** — `php artisan neurologia:restore {nome_do_arquivo.sqlite3}` (pede confirmação interativa, ou `--force` para pular). Verifica integridade do backup escolhido, cria automaticamente um backup de segurança do estado atual antes de sobrescrever, e verifica integridade do resultado final. **Não existe rota web de restore** — trocar o arquivo do SQLite por baixo de uma aplicação em produção pode conflitar com conexões abertas de outros workers PHP-FPM; isso só deve ser feito com acesso direto ao servidor, de preferência fora do horário de maior uso, reiniciando o PHP-FPM/Apache logo em seguida.
- **Zona de perigo** (zerar dados clínicos, preservando usuários/CID/especialidades/planos): disponível via `Administração → Sistema` na interface, exige reautenticação por senha + frase de confirmação exata, e cria/verifica um backup de segurança antes — se o backup falhar, a operação é abortada e nada é apagado.

## Regras críticas
- Nunca `.env`, SQLite, backups, exports, logs clínicos dentro de `public_html`.
- Nunca depender de Node/Python/Docker/Redis/Postgres/MySQL rodando no servidor.
- Build do React sempre local; servidor só recebe estático.
- `public_html/.htaccess` serve arquivos estáticos existentes diretamente (rápido, sem PHP) e só cai no `index.php` do Laravel para `/api/*` e para o shell do SPA.

## Ainda pendente antes do deploy real
- Confirmar a estrutura exata do domínio/subdomínio no cPanel (ver nota no topo deste documento).
- Revisão de segurança independente (seção 132 do PRD) — ver `SECURITY_CHECKLIST.md`.
- Substituir os ícones placeholder (`frontend/public/icons/`, gerados via GD) por arte de marca real, se desejado.
- Testar o fluxo completo (E2E, seção 130 do PRD) direto em produção após o primeiro deploy.

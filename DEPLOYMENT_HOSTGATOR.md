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
    └── visitas/         (se o app ficar em subpasta do domínio principal)
        ├── index.php
        ├── .htaccess
        ├── build/
        ├── assets/
        ├── icons/
        ├── manifest.webmanifest
        └── service-worker.js
```

> Ajustar caminho exato conforme o addon domain/subpasta configurado no cPanel para `drfernandofreua.com.br/visitas`.

## Passo a passo

1. **Local**: `cd frontend && npm run build` → gera `frontend/dist`. Copiar conteúdo para `public_html/visitas/build` (ou raiz equivalente) + `assets/`, `icons/`, `manifest.webmanifest`, `service-worker.js`.
2. **Local**: `cd equipe/app && composer install --no-dev --optimize-autoloader` → enviar `vendor/` junto com a aplicação (o servidor HostGator não precisa rodar `composer`).
3. Upload de `equipe/app` (sem `.env` de dev) para `~/equipe/app` via cPanel File Manager ou FTP/SFTP.
4. Criar `.env` de produção diretamente no servidor (nunca via Git) — ver `equipe/app/.env.example` e seção "ENV produção" abaixo.
5. Gerar `APP_KEY` (`php artisan key:generate`) se o provedor cPanel oferecer terminal PHP; caso contrário gerar localmente e colar no `.env` do servidor.
6. Criar `~/equipe/data/neurologia.sqlite3` (arquivo vazio) e rodar migrations (`php artisan migrate --force`) via terminal cPanel, se disponível; caso contrário rodar localmente contra uma cópia e enviar o arquivo já migrado (cuidado com drift de schema — preferir sempre migrar no próprio servidor).
7. Confirmar permissões de escrita em `~/equipe/data`, `~/equipe/backups`, `~/equipe/exports`, `~/equipe/app/storage`.
8. `public_html/visitas/index.php` carrega o Laravel de fora da pasta pública (ver exemplo na FASE de deploy do PRD, seção 7).
9. Rodar script de diagnóstico pre-flight (seção "Pre-flight" abaixo) e depois **removê-lo**.
10. `php artisan config:cache && php artisan route:cache && php artisan view:cache` — somente depois que `.env` estiver 100% definitivo.
11. Configurar Cron Job do cPanel (opcional) para `php artisan neurologia:backup` diário.

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

LOG_LEVEL=warning
```

## Pre-flight (script removível)

Checar: PHP >= 8.2, extensões `pdo`, `pdo_sqlite`, `sqlite3`, `openssl`, `mbstring`, `json`, `fileinfo`, `intl`, `session`; permissão de escrita em `equipe/data`, `equipe/backups`, `storage/`. Implementado como comando Artisan (`php artisan neurologia:preflight`) — nunca como página pública com `phpinfo()`.

## Regras críticas
- Nunca `.env`, SQLite, backups, exports, logs clínicos dentro de `public_html`.
- Nunca depender de Node/Python/Docker/Redis/Postgres/MySQL rodando no servidor.
- Build do React sempre local; servidor só recebe estático.

_Este documento será detalhado com os comandos exatos de `.htaccess` e `index.php` na FASE 20 (Deploy HostGator)._

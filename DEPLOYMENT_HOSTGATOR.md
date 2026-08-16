# DEPLOYMENT — HostGator / cPanel

Domínio de produção: `drfernandofreua.com.br/visitas`.

**Confirmado**: `public_html` já hospeda o site principal do domínio — este app fica na subpasta `public_html/visitas/`, não no document root. Todo o código já reflete essa estrutura (não é mais uma decisão pendente).

## Estrutura no servidor

```text
/home/USUARIO/
├── equipe/                       ← PRIVADO, fora de public_html
│   ├── app/                       Laravel: app, bootstrap, config, database, routes, storage, vendor, .env
│   ├── data/                      neurologia.sqlite3
│   ├── backups/
│   ├── exports/
│   └── logs/
└── public_html/                  ← site principal existente — não mexer no que já está aqui
    ├── (arquivos do site atual, intocados)
    └── visitas/                   ← ESTE APP
        ├── index.php               já pronto neste repo — carrega o Laravel de fora de public_html
        ├── .htaccess                já pronto neste repo
        ├── build/                   gerado por `npm run build`, não versionado (ver passo 1)
        └── assets/
```

## O que sobe para `public_html/visitas/` (via cPanel File Manager ou FTP/SFTP)

Só conteúdo estático/público — nada sensível:

1. `public_html/visitas/index.php` — já está neste repositório, pronto (carrega o Laravel de `~/equipe/app`).
2. `public_html/visitas/.htaccess` — já está neste repositório, pronto.
3. `public_html/visitas/build/` — **gerado localmente**, não existe no repositório. Rodar:
   ```bash
   cd frontend
   VITE_BASE_PATH=/visitas/ npm run build
   ```
   e copiar **todo o conteúdo** de `frontend/dist/` para dentro de `public_html/visitas/build/` (o `index.html` de dentro de `dist/` vira `public_html/visitas/build/index.html` — é isso que a rota SPA fallback em `routes/web.php` espera via `public_path('build/index.html')`, e `public_path()` já resolve para `public_html/visitas`).

   > No Git Bash/MSYS, `VITE_BASE_PATH=/visitas/` sozinho pode ser convertido incorretamente para um caminho do Windows. Se isso acontecer (build gerar caminhos tipo `/C:/.../visitas/assets/...`), rodar com `MSYS_NO_PATHCONV=1` na frente do comando.

Nada mais precisa ir para `public_html/visitas/` — sem `.env`, sem `vendor/`, sem SQLite, sem `equipe/`.

## O que sobe para fora de `public_html` (pasta "privada", em `~/equipe/`)

1. **Todo o diretório `equipe/app/`** deste repositório — código do Laravel (`app/`, `bootstrap/`, `config/`, `database/`, `routes/`, `resources/` se houver), **exceto**:
   - `.env` — não subir o de desenvolvimento; criar um novo diretamente no servidor (ver seção "ENV produção" abaixo).
   - `vendor/` do seu ambiente local não é obrigatório subir tal qual — rodar antes:
     ```bash
     cd equipe/app
     composer install --no-dev --optimize-autoloader
     ```
     e subir o `vendor/` resultante junto (o servidor HostGator não precisa ter Composer nem rodá-lo).
2. **As pastas vazias** `equipe/data/`, `equipe/backups/`, `equipe/exports/`, `equipe/logs/` — criar no servidor se não vierem no upload (o `.gitkeep` de cada uma já garante que existem no repositório).

## Passo a passo completo

1. Gerar o build do frontend com `VITE_BASE_PATH=/visitas/` (ver acima) e copiar para `public_html/visitas/build/`.
2. Rodar `composer install --no-dev --optimize-autoloader` dentro de `equipe/app` localmente.
3. Upload de `equipe/app` (sem o `.env` de desenvolvimento) para `~/equipe/app`, e de `public_html/visitas/index.php` + `.htaccess` + `build/` para `~/public_html/visitas/`.
4. Criar `.env` de produção diretamente no servidor (nunca via Git) — ver seção "ENV produção" abaixo.
5. Gerar `APP_KEY` (`php artisan key:generate`) se o cPanel oferecer terminal PHP; caso contrário gerar localmente e colar no `.env` do servidor.
6. Criar `~/equipe/data/neurologia.sqlite3` (arquivo vazio) e rodar `php artisan migrate --force` + `php artisan db:seed --force` no terminal do cPanel (`DatabaseSeeder` cria o admin inicial `admin`/`senha@1234` com troca obrigatória, além de especialidades/planos/CID-10 de exemplo). Depois, importar a tabela completa de CID-10 com `php artisan cid10:import {arquivo.csv}` (o seeder padrão só tem ~24 códigos de exemplo para desenvolvimento).
7. Rodar `php artisan neurologia:preflight` e conferir que tudo aparece `[OK]`.
8. Confirmar permissões de escrita em `~/equipe/data`, `~/equipe/backups`, `~/equipe/exports`, `~/equipe/app/storage`.
9. `php artisan config:cache && php artisan route:cache && php artisan view:cache` — somente depois que `.env` estiver 100% definitivo.
10. Configurar Cron Job do cPanel chamando `php artisan schedule:run` a cada minuto (ver seção "Agendamento" abaixo) — opcional, mas recomendado.
11. Testar: acessar `https://drfernandofreua.com.br/visitas/`, confirmar que o site principal do domínio (fora de `/visitas`) continua intocado, fazer login, e testar refresh direto numa subrota (ex.: `/visitas/admin/dashboard`) para confirmar que o fallback SPA está servindo o arquivo certo.

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
- Nunca `.env`, SQLite, backups, exports, logs clínicos dentro de `public_html` (nem em `public_html/visitas`).
- Nunca depender de Node/Python/Docker/Redis/Postgres/MySQL rodando no servidor.
- Build do React sempre local; servidor só recebe estático.
- `public_html/visitas/.htaccess` serve arquivos estáticos existentes diretamente (rápido, sem PHP) e só cai no `index.php` do Laravel para `/api/*` e para o shell do SPA.
- Não tocar em nada fora de `public_html/visitas/` dentro de `public_html` — é o site principal do domínio.

## Validado localmente (não em produção real)

- Build com `VITE_BASE_PATH=/visitas/` gera caminhos de asset corretos (`/visitas/assets/...`, `/visitas/manifest.webmanifest`, etc.).
- `public_path()` do Laravel resolve corretamente para `public_html/visitas` (via `Application::usePublicPath()` em `bootstrap/app.php`).
- Servindo o build copiado localmente via `php artisan serve`: `/` e uma rota profunda (`/admin/dashboard`) servem o shell do SPA corretamente; `/api/*` continua respondendo JSON normalmente.

## Ainda pendente antes do deploy real
- Revisão de segurança independente (seção 132 do PRD) — já concluída, ver `SECURITY_CHECKLIST.md` e `IMPLEMENTATION_LOG.md`.
- Substituir os ícones placeholder (`frontend/public/icons/`, gerados via GD) por arte de marca real, se desejado.
- Testar o fluxo completo (E2E, seção 130 do PRD) direto em produção após o primeiro deploy real no cPanel.

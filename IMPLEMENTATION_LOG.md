# IMPLEMENTATION LOG

Registro cronológico de decisões técnicas e etapas concluídas. Ordem: mais recente no topo.

## 2026-08-15 — FASE 0 + início FASE 1

- Ambiente local Windows sem PHP/Composer no PATH. Localizado PHP 8.2.12 via instalação XAMPP existente em `C:\xampp\php`.
- Habilitadas extensões necessárias no `C:\xampp\php\php.ini`: `intl`, `sqlite3`, `fileinfo` (já ativas: pdo_sqlite, mbstring, openssl, session, json, curl, dom, tokenizer, ctype, hash, filter, xml).
- Removida diretiva duplicada `extension=openssl` (openssl já é compilado estaticamente nesse build) para eliminar warning.
- Composer 2.10.2 instalado localmente como `C:\xampp\php\composer.phar` (sem instalação global no sistema).
- Estrutura de pastas HostGator criada localmente em espelho: `equipe/app`, `equipe/data`, `equipe/backups`, `equipe/exports`, `equipe/logs`, `public_html`.
- Git inicializado no diretório raiz do projeto; branch renomeada para `main`; remote `origin` configurado para `https://github.com/ffreua/visitas.git` (repositório estava vazio).
- Frontend: `npm create vite@latest frontend -- --template react`, seguido de `npm install` + `react-router-dom` + `axios` + `vite-plugin-pwa` (dev dependency). 0 vulnerabilidades reportadas pelo npm audit.
- Backend: `composer create-project laravel/laravel equipe/app` — nota: a primeira tentativa fixando `"^11.0"` falhou porque o Composer 2.10 bloqueia por padrão instalação de pacotes com advisories de segurança conhecidas nas versões antigas do range 11.31–11.55; resolvido usando a versão mais recente do laravel/laravel sem pin de versão (Composer resolve automaticamente para uma versão não afetada).

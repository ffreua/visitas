# Neurologia Hospitalar — Sistema de Gestão

Aplicação web/PWA para gestão dos pacientes acompanhados pela equipe de Neurologia hospitalar (São Paulo). Produção em `drfernandofreua.com.br/visitas`, hospedagem HostGator/cPanel.

## Stack

- **Backend**: PHP 8.2+/Laravel 11, SQLite (PDO), sessões em arquivo.
- **Frontend**: React + Vite, PWA (manifest + service worker, shell-only cache).
- **Deploy**: build estático enviado ao servidor; sem Node/Python/Docker/Redis/MySQL/Postgres em produção.

## Estrutura do repositório

```text
equipe/app/       Laravel (backend) — nunca fica em public_html em produção
equipe/data/       neurologia.sqlite3 (fora de public_html)
equipe/backups/    backups do banco
equipe/exports/    exportações temporárias (XLSX)
equipe/logs/       logs de aplicação
public_html/       arquivos públicos de produção (index.php, build do React, PWA)
frontend/          código-fonte React/Vite (build local, nunca roda npm no servidor)
```

## Documentação viva

- [STATUS.md](STATUS.md) — etapa atual, testes, próximo passo.
- [IMPLEMENTATION_LOG.md](IMPLEMENTATION_LOG.md) — decisões técnicas, em ordem cronológica.
- [SECURITY_CHECKLIST.md](SECURITY_CHECKLIST.md) — checklist de segurança validado por teste real.
- [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md) — modelo de dados canônico (Patient × Admission).
- [DEPLOYMENT_HOSTGATOR.md](DEPLOYMENT_HOSTGATOR.md) — passo a passo de deploy em produção.
- [CHANGELOG.md](CHANGELOG.md) — histórico de mudanças.

## Desenvolvimento local

Backend (PHP local via XAMPP, sem instalação global):

```bash
cd equipe/app
C:\xampp\php\php.exe C:\xampp\php\composer.phar install
C:\xampp\php\php.exe artisan migrate
C:\xampp\php\php.exe artisan serve
```

Frontend:

```bash
cd frontend
npm install
npm run dev
```

## Conceito central

`Patient` (paciente, identidade longitudinal pelo número de prontuário) é distinto de `Admission` (episódio/internação/acompanhamento). Reinternação sempre cria um novo `Admission` — nunca sobrescreve o anterior. Ver [DATABASE_SCHEMA.md](DATABASE_SCHEMA.md).

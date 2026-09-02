# Pacotes de atualização — HostGator

Dois arquivos para subir pelo Gerenciador de Arquivos do cPanel. O passo a passo completo
(com o backup preventivo e a atualização da estrutura do banco) está em
[`../DEPLOYMENT_HOSTGATOR.md`](../DEPLOYMENT_HOSTGATOR.md), seção
**"Atualizar uma instalação existente"**.

| Arquivo | Extrair dentro de | Conteúdo |
|---|---|---|
| `1-backend-equipe-app.zip` | `equipe/app/` (fora de `public_html`) | 29 arquivos de código: `app/`, `bootstrap/`, `database/migrations/`, `routes/` |
| `2-frontend-public_html-visitas.zip` | `public_html/visitas/` | Site compilado (`index.html`, `assets/`, `icons/`, `sw.js`, `index.php`, `.htaccess`) |

## O que estes pacotes NÃO contêm (de propósito)

- `equipe/data/neurologia.sqlite3` — **o banco de dados com seus pacientes**
- `equipe/app/.env` — a configuração de produção
- `equipe/app/vendor/` — as bibliotecas (não mudaram nesta versão)
- `equipe/backups/`, `equipe/exports/`, `equipe/app/storage/`

Extrair estes zips não pode apagar cadastro nenhum: os arquivos que eles trazem estão listados
acima, um a um, e nenhum deles é de dados.

## Ordem

1. Backup do banco (copiar `equipe/data/neurologia.sqlite3` pelo cPanel).
2. Extrair o pacote 1 em `equipe/app/`.
3. Apagar a pasta `public_html/visitas/assets/` e extrair o pacote 2 em `public_html/visitas/`.
   Depois **confira que `assets/` voltou com 2 arquivos dentro** — se o extrator pular a pasta,
   o site abre em branco.
4. Entrar no site como admin → **Administração → Sistema & Backups → Estrutura do banco →
   Atualizar estrutura do banco** (senha + frase `ATUALIZAR BANCO`).

> ⚠️ **Esta versão MUDA a estrutura do banco** (a data de nascimento passa a poder ficar em
> branco no cadastro). O bloco "Estrutura do banco" vai acusar **1 atualização pendente** — o
> passo 4 é obrigatório desta vez. O sistema cria e verifica um backup antes; se o backup falhar,
> nada é executado. Nenhum paciente já cadastrado é alterado.
>
> Entre o passo 2 e o passo 4 o cadastro de paciente novo fica indisponível. São poucos minutos;
> prefira fazer fora do horário de visita.

## Antes de extrair: permissões

Se o extrator do cPanel devolver `Permission denied`, veja
[a seção de correção no DEPLOYMENT](../DEPLOYMENT_HOSTGATOR.md#permission-denied-ao-extrair).
Uma extração parcial deixa o app inconsistente — só siga adiante quando a saída não tiver
nenhuma linha `error:`.

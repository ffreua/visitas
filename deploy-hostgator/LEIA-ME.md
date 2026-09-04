# Pacotes de atualização — HostGator

Versão: **gestor observador + impressão da lista do dia + correção dos indicadores e novo dashboard**.

> Reempacotado em **04/09/2026** com **“Minhas Visitas”** (tabela das visitas do próprio médico, com impressão e o selo “Cobrar via AMHS”), com a **conferência obrigatória do convênio no encerramento** e com a correção do aviso de “cadastro incompleto” no encerramento: salvar a data de nascimento ali devolvia o erro “Diagnóstico final e desfecho são obrigatórios” e parecia não salvar, obrigando a editar o paciente por fora antes de conseguir dar alta. “Minhas Visitas” e a conferência do convênio mexem nos **dois** pacotes; a correção do nascimento, só no pacote 2. **Nenhuma delas muda o banco.** Se você ainda não subiu esta versão, siga os 4 passos normalmente. Se já subiu, refaça os passos 2 e 3 — o passo 4 (estrutura do banco) não precisa ser repetido.
>
> ⚠️ Os dois pacotes precisam subir **juntos**: o servidor passa a recusar encerramento sem a conferência do convênio, e só a tela nova envia esse campo. Subir apenas o pacote 1 deixaria o encerramento travado até o pacote 2 entrar.

Dois arquivos para subir pelo Gerenciador de Arquivos do cPanel. O passo a passo completo
(com o backup preventivo e a atualização da estrutura do banco) está em
[`../DEPLOYMENT_HOSTGATOR.md`](../DEPLOYMENT_HOSTGATOR.md), seção
**"Atualizar uma instalação existente"**.

| Arquivo | Extrair dentro de | Conteúdo |
|---|---|---|
| `1-backend-equipe-app.zip` | `equipe/app/` (fora de `public_html`) | 43 arquivos de código: `app/`, `bootstrap/`, `database/`, `routes/` |
| `2-frontend-public_html-visitas.zip` | `public_html/visitas/` | Site compilado (`index.html`, `assets/`, `icons/`, `sw.js`, `index.php`, `.htaccess`) |

Dos 43 arquivos do backend, **22 mudaram nesta versão**; os outros 21 são da versão anterior e
vão junto só por segurança — são idênticos aos que já estão no servidor, então reextrair não
muda nada, e o pacote fica completo caso alguma extração anterior tenha pulado um arquivo em
silêncio.

## O que estes pacotes NÃO contêm (de propósito)

- `equipe/data/neurologia.sqlite3` — **o banco de dados com seus pacientes**
- `equipe/app/.env` — a configuração de produção
- `equipe/app/vendor/` — as bibliotecas (não mudaram nesta versão)
- `equipe/backups/`, `equipe/exports/`, `equipe/app/storage/`

Extrair estes zips não pode apagar cadastro nenhum: os arquivos que eles trazem estão listados
um a um dentro do próprio empacotador, e nenhum deles é de dados.

## Ordem

1. Backup do banco (copiar `equipe/data/neurologia.sqlite3` pelo cPanel).
2. Extrair o pacote 1 em `equipe/app/`.
3. Apagar a pasta `public_html/visitas/assets/` e extrair o pacote 2 em `public_html/visitas/`.
   Depois **confira que `assets/` voltou com 2 arquivos dentro** — se o extrator pular a pasta,
   o site abre em branco.
4. Entrar no site como admin → **Administração → Sistema & Backups → Estrutura do banco →
   Atualizar estrutura do banco** (senha + frase `ATUALIZAR BANCO`).

> ⚠️ **Esta versão MUDA a estrutura do banco** — o bloco "Estrutura do banco" vai acusar
> **2 atualizações pendentes**, e o passo 4 é obrigatório desta vez:
>
> 1. `users.role` passa a aceitar um terceiro valor, `OBSERVER`. Nenhum usuário existente é
>    alterado; a migração só amplia a lista de perfis aceitos.
> 2. Preenchimento de `first_neurology_evaluation_at` nos episódios antigos, a partir da visita
>    mais antiga já registrada de cada um. Esse campo quase nunca era gravado, e é dele que sai o
>    indicador "tempo solicitação → 1ª avaliação". A migração **só preenche coluna vazia** —
>    nenhum valor existente é sobrescrito, e onde não há visita registrada o campo permanece em
>    branco (inventar data faria o indicador mentir com aparência de dado).
>
> O sistema cria e verifica um backup antes; se o backup falhar, nada é executado.
>
> Entre o passo 2 e o passo 4, o atendimento segue normal. A única coisa que não funciona antes
> do passo 4 é criar usuário com o perfil "Gestor observador" (o banco recusa o valor).

## Depois de subir: criar o gestor observador

**Administração → Gestão da Equipe → + Novo médico** → perfil **"Gestor observador (somente
leitura)"**. A senha inicial é a padrão (`senha@1234`) e o sistema obriga a troca no primeiro
acesso, como para qualquer usuário.

O que esse perfil vê: Casos Ativos, Altas/Histórico, a ficha completa de cada atendimento,
Imprimir Lista de Hoje, Indicadores & Dashboard e Dashboard por Prontuário. O que ele **não**
pode: criar, editar, encerrar ou excluir qualquer coisa; assinar visita; assumir paciente;
exportar XLSX; entrar em Gestão da Equipe, Planos, Especialidades, Excluídos ou Sistema.

## Os números do dashboard vão mudar — e é essa a correção

Depois desta atualização, **a cobertura de visita cai muito**. Não é regressão: o cálculo antigo
contava no denominador só os dias em que alguém já tinha registrado alguma coisa, então dia sem
visita simplesmente não existia na conta e o indicador tendia a 100%. O denominador passa a ser
todo dia de calendário em que o paciente esteve sob acompanhamento — que é o número de
oportunidades de visita que a equipe realmente teve.

Também mudam, pelo mesmo motivo: o total de episódios do período (agora inclui quem entrou antes
da janela e seguiu internado nela), o contador de altas (agora conta encerramentos ocorridos no
período, e separa alta hospitalar de encerramento da Neurologia) e as reinternações (agora só as
reentradas do período). Comparações com relatórios tirados antes desta versão não são válidas.

## Antes de extrair: permissões

Se o extrator do cPanel devolver `Permission denied`, veja
[a seção de correção no DEPLOYMENT](../DEPLOYMENT_HOSTGATOR.md#permission-denied-ao-extrair).
Uma extração parcial deixa o app inconsistente — só siga adiante quando a saída não tiver
nenhuma linha `error:`.

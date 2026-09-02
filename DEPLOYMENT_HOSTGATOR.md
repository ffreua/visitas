# DEPLOYMENT — HostGator / cPanel (sem terminal)

Domínio: `drfernandofreua.com.br/visitas`. `public_html` já hospeda outro site — este app fica na subpasta `visitas`.

> **Já tem o sistema no ar com pacientes cadastrados?** Vá direto para
> [Atualizar uma instalação existente](#atualizar-uma-instalacao-existente-sem-perder-os-dados).
> A instalação do zero está mais abaixo e **não** deve ser seguida numa instalação que já tem dados.

---

## Atualizar uma instalação existente (sem perder os dados)

A regra que protege seus cadastros é uma só:

> **Nunca envie nada para dentro de `equipe/data/`, `equipe/backups/`, `equipe/exports/`
> nem o arquivo `equipe/app/.env`.** É aí que vivem o banco de dados e a configuração do
> servidor. Todo o resto pode ser substituído à vontade.

Os arquivos prontos estão em `deploy-hostgator/` no seu computador:

| Arquivo | Vai para | O que é |
|---|---|---|
| `1-backend-equipe-app.zip` | `equipe/app/` (fora de `public_html`) | Só os arquivos de código que mudaram |
| `2-frontend-public_html-visitas.zip` | `public_html/visitas/` | O site compilado |

### Passo 1 — Backup (30 segundos, faça mesmo assim)

No Gerenciador de Arquivos do cPanel, entre em `equipe/data/`, clique com o botão direito em
`neurologia.sqlite3` → **Copy** (ou **Download**). Guarde uma cópia. É a sua rede de segurança:
se qualquer coisa der errado, basta colocar esse arquivo de volta.

### Passo 2 — Subir o backend

1. Vá até `equipe/app/` (fora de `public_html`).
2. Faça upload de `1-backend-equipe-app.zip`.
3. Clique com o botão direito no zip → **Extract**, extraindo **dentro de `equipe/app/`**.
   Ele contém só estas pastas: `app/`, `bootstrap/`, `database/migrations/`, `routes/` — todas
   já existem, e os arquivos de mesmo nome são substituídos. Confirme se aparecer o aviso de
   sobrescrever.
4. Apague o zip depois de extrair.

Nada nesse pacote toca em `.env`, `vendor/`, `storage/` ou no banco.

### Passo 3 — Subir o frontend

1. Vá até `public_html/visitas/`.
2. **Apague a pasta `assets/` inteira** (os arquivos antigos têm nomes diferentes e ficariam
   sobrando; é o que causa "erro 404 ao entrar" depois de uma atualização).
3. Faça upload de `2-frontend-public_html-visitas.zip` e extraia ali dentro, sobrescrevendo.
4. **Confira se `assets/` foi recriada e tem 2 arquivos dentro** (`index-*.css` e `index-*.js`).
   O extrator do cPanel já pulou arquivos em silêncio quando a pasta de destino não existia —
   por isso os pacotes agora carregam entradas de pasta explícitas. Se `assets/` não aparecer,
   crie-a pelo botão **+ Folder** e suba os dois arquivos manualmente.
5. Apague o zip.

### Passo 4 — Atualizar a estrutura do banco (pelo próprio site)

> ⏱️ **Entre o passo 2 e o passo 4 o app fica parcialmente indisponível** (dá para entrar e ver a
> lista, mas não para criar atendimento novo): o código já espera as colunas novas, que só
> passam a existir no passo 4. São poucos minutos — prefira fazer fora do horário de visita.

Esta versão acrescenta colunas novas (número de atendimento, prontuário pendente). Como não há
terminal na hospedagem, isso é feito por uma tela:

1. Acesse `https://drfernandofreua.com.br/visitas/` e entre como administrador.
2. Menu → **Administração → Sistema & Backups**.
3. No bloco **Estrutura do banco** vai aparecer o aviso de atualizações pendentes.
4. Clique em **Atualizar estrutura do banco**, digite sua senha e a frase `ATUALIZAR BANCO`.

O sistema cria e **verifica** um backup antes de mexer em qualquer coisa — se o backup falhar,
ele aborta e não altera nada. As migrations desta versão só **adicionam** colunas e índices:
nenhum paciente, episódio, visita ou pendência é apagado ou reescrito.

Depois de aplicar, o mesmo bloco passa a mostrar **"✓ Banco atualizado"**.

### Passo 5 — Conferir

- Abra o app no celular e **atualize a página** (o app é um PWA; se a tela parecer antiga,
  puxe para recarregar ou feche e abra de novo — o service worker troca sozinho a versão).
- A lista de casos ativos deve continuar com os pacientes de antes.
- Menu → **Administração → Dashboard por Prontuário** → busque um prontuário existente:
  deve listar os atendimentos daquele paciente.

### Se algo der errado

| Sintoma | O que fazer |
|---|---|
| Site em branco / sem estilo depois de subir | A pasta `assets/` não foi criada na extração. Confira: `public_html/visitas/assets/` tem que existir e conter **2 arquivos** (`index-*.css` e `index-*.js`). Se estiver vazia ou ausente, crie a pasta pelo botão **+ Folder** e suba os dois arquivos dentro dela. |
| `Permission denied` ao extrair o zip | Pastas do servidor sem permissão de escrita. Veja [Permission denied ao extrair](#permission-denied-ao-extrair) logo abaixo — **é preciso terminar a extração**, um pacote extraído pela metade deixa o app inconsistente. |
| Erro 500 / tela branca | Restaure o backup do Passo 1 em `equipe/data/` e me avise. Nenhum dado se perde. |
| 404 ao entrar / tela antiga | A pasta `assets/` antiga ficou no servidor. Refaça o Passo 3 apagando `assets/` primeiro. |
| "Estrutura do banco" continua com pendências | Confira se `equipe/backups/` existe e tem permissão de escrita (botão direito → Change Permissions). O backup obrigatório é criado lá. |
| "database is locked" | Botão direito em `equipe/data` → Change Permissions → leitura/escrita para o dono. |

### `Permission denied` ao extrair

O extrator do cPanel falha em algumas pastas e funciona em outras, com mensagens como
`cannot create ...` ou `cannot delete old ... Permission denied`. Isso é permissão de **pasta**
no servidor (herdada da instalação original), não do pacote — os zips não contêm entrada de
diretório nenhuma, então não têm como alterar permissão de pasta.

> ⚠️ **Uma extração que falhou pela metade deixa o app quebrado**: parte do código novo convive
> com parte do código antigo. Enquanto não terminar, evite criar atendimentos novos. Consultar a
> lista continua funcionando, e **o banco de dados não é afetado** — nenhum arquivo de dados está
> nos pacotes e nenhuma migration roda sozinha.

Correção, no Gerenciador de Arquivos, para **cada pasta que apareceu no erro** (botão direito →
*Change Permissions* → marcar leitura/escrita/execução para o dono, ou digitar `0755`):

```text
private/equipe/app/app/Exceptions
private/equipe/app/app/Models
private/equipe/app/app/Policies
private/equipe/app/app/Services
private/equipe/app/database/migrations
private/equipe/app/routes/api
```

Depois é só **extrair o mesmo zip de novo**, sobrescrevendo. Extrair duas vezes não causa
problema: os arquivos são idênticos. A extração só está completa quando **não sobra nenhuma
linha de `error:`** — confira a saída inteira antes de seguir para o passo 3.

Se a mensagem persistir mesmo após ajustar a permissão, a pasta pode estar com dono errado; nesse
caso abra um chamado na HostGator pedindo para corrigir a propriedade dos arquivos em
`private/equipe`.

### Por que o banco não corre risco

- O banco (`equipe/data/neurologia.sqlite3`) **não faz parte de nenhum dos dois pacotes** — os
  zips foram montados com a lista exata de arquivos, e essa lista está impressa acima.
- O `.env` do servidor também não está nos pacotes: as suas configurações de produção continuam
  intactas.
- A atualização de estrutura roda `migrate`, que aplica só as migrations ainda não registradas
  na tabela `migrations` do próprio banco — rodar duas vezes não repete nada.

---

## Instalação do zero (primeira vez)

Tudo já está pronto no seu computador para upload. Você **não precisa abrir terminal nem rodar nenhum comando** — só usar o Gerenciador de Arquivos do cPanel para subir duas pastas.

### Onde estão os arquivos prontos, no seu computador

```text
visitas/                          (pasta do projeto)
├── equipe/
│   ├── app/                       ← sobe pra ~/equipe/app no servidor
│   │   ├── .env.production.ready  ← sobe e RENOMEIA pra ".env" no servidor
│   │   └── (todo o resto: app/, bootstrap/, config/, vendor/, etc.)
│   ├── data/
│   │   └── neurologia.sqlite3     ← já migrado e populado, pronto pra usar
│   ├── backups/                   (vazia, ok)
│   ├── exports/                   (vazia, ok)
│   └── logs/                      (vazia, ok)
└── public_html/
    └── visitas/                   ← sobe pra ~/public_html/visitas no servidor
        ├── index.php
        ├── .htaccess
        └── (build do site: index.html, assets/, icons/, etc.)
```

### Passo a passo (só cPanel, sem terminal)

### 1. Entre no cPanel → Gerenciador de Arquivos (File Manager)

### 2. Suba a pasta `equipe`

1. Navegue até a **raiz da sua conta** (não dentro de `public_html` — um nível acima, geralmente é onde o Gerenciador de Arquivos abre por padrão, ou clique em "Home"/"‎🏠").
   - Também vale colocar dentro da pasta `private` (ficando `private/equipe`) — o app aceita as duas posições. O que **não** pode é ficar dentro de `public_html`.
2. Se ainda não existir uma pasta `equipe` lá, crie uma.
3. Dentro dela, faça upload das 5 subpastas do seu computador (`app`, `data`, `backups`, `exports`, `logs`) — pode selecionar tudo de uma vez e usar "Upload" ou arrastar.
   - Se o Gerenciador de Arquivos permitir upload de `.zip`, é mais rápido: compacte a pasta `equipe` inteira num `.zip` no seu computador, suba o `.zip`, e use "Extract" (extrair) no próprio cPanel.
4. **Dentro de `equipe/app/`**, depois do upload, **apague o arquivo `.env`** se ele tiver subido (não deveria — veja o passo 3), e **renomeie `.env.production.ready` para `.env`** (clique com botão direito no arquivo → Rename).

> ⚠️ **O único passo manual que exige atenção**: o arquivo que vira `.env` no servidor tem que ser o `.env.production.ready`, nunca o `.env` comum da pasta (esse é só para uso no seu computador, com senha simples e sem HTTPS — não pode ir para o servidor).

### 3. Suba a pasta `visitas` para dentro de `public_html`

1. Navegue até `public_html` (o site atual do domínio já está lá — não mexa em mais nada além da subpasta `visitas`).
2. Se a subpasta `visitas` ainda não existir dentro de `public_html`, crie-a.
3. Suba todo o conteúdo de `public_html/visitas/` do seu computador para dentro dela (`index.php`, `.htaccess`, `index.html`, `assets/`, `icons/`, `manifest.webmanifest`, `sw.js`, etc.).

### 4. Teste

Acesse **`https://drfernandofreua.com.br/visitas/`**. Deve aparecer a tela de login.

- **Usuário**: `admin`
- **Senha**: `senha@1234`

O sistema vai pedir para trocar essa senha assim que você entrar — é o comportamento esperado (ninguém deve continuar usando a senha padrão).

### Se der erro

- **Erro ao entrar, com 404 na aba Network do navegador**: o build que está no servidor é antigo. Suba de novo o conteúdo de `public_html/visitas/` (principalmente a pasta `assets/` e o `index.html`), apagando os arquivos antigos antes.
- **Tela em branco ou erro 500**: confira se o arquivo `.env` (renomeado de `.env.production.ready`) está mesmo dentro de `equipe/app/`, e se a pasta `equipe` ficou fora de `public_html` (na raiz da conta ou dentro de `private`). Se o Laravel não for encontrado, a página mostra uma mensagem explicando isso em vez de uma tela branca.
- **"Erro de permissão" ou "database is locked"**: no Gerenciador de Arquivos, clique com botão direito nas pastas `equipe/data`, `equipe/backups`, `equipe/exports`, `equipe/logs` e em `equipe/app/storage` → "Change Permissions" → marcar leitura/escrita para o dono (geralmente já vem certo, só mexa se aparecer esse erro).
- **Página principal do domínio sumiu ou mudou**: significa que algo foi parar no lugar errado dentro de `public_html` — confira se você só mexeu dentro da subpasta `visitas`.

### O que este pacote já resolve sozinho (nada disso precisa de terminal)

- Banco de dados (`neurologia.sqlite3`) já criado, com as tabelas certas e um usuário administrador (`admin`/`senha@1234`), além de uma lista inicial de especialidades médicas e planos de saúde comuns.
- Chave de segurança da aplicação (`APP_KEY`) já gerada dentro do `.env.production.ready`.
- Endereço do site (`APP_URL`) já configurado para `https://drfernandofreua.com.br/visitas`.

### O que fica pendente (precisa de mais atenção depois, não é urgente)

- **Tabela completa de CID-10**: o banco já vem com ~24 códigos comuns em Neurologia (suficiente para usar o sistema desde já), mas não a tabela oficial completa (milhares de códigos). Importar a tabela completa exige rodar um comando (`cid10:import`) — isso precisa de acesso a terminal (ou eu posso gerar um banco já com a tabela completa depois, se você me arranjar o arquivo CID-10 em CSV).
- **Backup automático diário**: também depende de um Cron Job do cPanel (`Cron Jobs` no painel, não é bem um "terminal" — é só preencher um formulário com um comando; se quiser, eu te aviso exatamente o que colar lá quando chegarmos nessa etapa. Não é obrigatório para o site funcionar).
- **Trocar os ícones do app** (hoje são um placeholder simples, "N" azul) por uma arte de marca de verdade, se você quiser.

---

## Referência técnica (para quando você tiver acesso a terminal, se um dia precisar)

<details>
<summary>Clique para expandir — comandos Artisan úteis, agendamento, backup/restore</summary>

### Comandos úteis via terminal (se disponível)

```bash
php artisan neurologia:preflight        # checa se o ambiente está OK
php artisan neurologia:backup           # cria backup verificado do banco
php artisan neurologia:restore {arquivo}  # restaura um backup (exclusivo CLI, nunca web)
php artisan cid10:import {arquivo.csv}  # importa a tabela CID-10 completa
```

### Agendamento (Cron Job do cPanel, uma linha, a cada minuto)

```bash
* * * * * cd /home/USUARIO/equipe/app && php artisan schedule:run >> /dev/null 2>&1
```

Isso aciona backup diário (03:00) e limpeza de exportações órfãs (a cada hora).

### Zona de perigo / Restore

- **Restore de backup**: exclusivamente via terminal (`php artisan neurologia:restore`) — nunca uma tela do site, porque trocar o arquivo do banco com o site no ar é arriscado.
- **Zerar dados clínicos** (preservando usuários/planos/especialidades): disponível na própria interface, em `Administração → Sistema`, com senha + frase de confirmação.

### Regras críticas
- Nunca `.env`, banco de dados, backups, exportações dentro de `public_html`.
- Build do React sempre feito no computador local; o servidor só recebe arquivos já prontos.

</details>

# DEPLOYMENT — HostGator / cPanel (sem terminal)

Domínio: `drfernandofreua.com.br/visitas`. `public_html` já hospeda outro site — este app fica na subpasta `visitas`.

Tudo já está pronto no seu computador para upload. Você **não precisa abrir terminal nem rodar nenhum comando** — só usar o Gerenciador de Arquivos do cPanel para subir duas pastas.

## Onde estão os arquivos prontos, no seu computador

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

## Passo a passo (só cPanel, sem terminal)

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

## Se der erro

- **Erro ao entrar, com 404 na aba Network do navegador**: o build que está no servidor é antigo. Suba de novo o conteúdo de `public_html/visitas/` (principalmente a pasta `assets/` e o `index.html`), apagando os arquivos antigos antes.
- **Tela em branco ou erro 500**: confira se o arquivo `.env` (renomeado de `.env.production.ready`) está mesmo dentro de `equipe/app/`, e se a pasta `equipe` ficou fora de `public_html` (na raiz da conta ou dentro de `private`). Se o Laravel não for encontrado, a página mostra uma mensagem explicando isso em vez de uma tela branca.
- **"Erro de permissão" ou "database is locked"**: no Gerenciador de Arquivos, clique com botão direito nas pastas `equipe/data`, `equipe/backups`, `equipe/exports`, `equipe/logs` e em `equipe/app/storage` → "Change Permissions" → marcar leitura/escrita para o dono (geralmente já vem certo, só mexa se aparecer esse erro).
- **Página principal do domínio sumiu ou mudou**: significa que algo foi parar no lugar errado dentro de `public_html` — confira se você só mexeu dentro da subpasta `visitas`.

## O que este pacote já resolve sozinho (nada disso precisa de terminal)

- Banco de dados (`neurologia.sqlite3`) já criado, com as tabelas certas e um usuário administrador (`admin`/`senha@1234`), além de uma lista inicial de especialidades médicas e planos de saúde comuns.
- Chave de segurança da aplicação (`APP_KEY`) já gerada dentro do `.env.production.ready`.
- Endereço do site (`APP_URL`) já configurado para `https://drfernandofreua.com.br/visitas`.

## O que fica pendente (precisa de mais atenção depois, não é urgente)

- **Tabela completa de CID-10**: o banco já vem com ~24 códigos comuns em Neurologia (suficiente para usar o sistema desde já), mas não a tabela oficial completa (milhares de códigos). Importar a tabela completa exige rodar um comando (`cid10:import`) — isso precisa de acesso a terminal (ou eu posso gerar um banco já com a tabela completa depois, se você me arranjar o arquivo CID-10 em CSV).
- **Backup automático diário**: também depende de um Cron Job do cPanel (`Cron Jobs` no painel, não é bem um "terminal" — é só preencher um formulário com um comando; se quiser, eu te aviso exatamente o que colar lá quando chegarmos nessa etapa. Não é obrigatório para o site funcionar).
- **Trocar os ícones do app** (hoje são um placeholder simples, "N" azul) por uma arte de marca de verdade, se você quiser.

## Para atualizações futuras (nova versão do sistema)

Sempre que eu fizer mudanças no sistema depois de hoje, o processo de novo upload será parecido: eu preparo os arquivos prontos (incluindo um banco de dados atualizado, se precisar de mudança na estrutura), e você só substitui os arquivos correspondentes pelo Gerenciador de Arquivos — nunca vai precisar rodar comando.

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

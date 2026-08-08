# Deploy no Railway — passo a passo

Roteiro para publicar o ambiente de validação. Tempo estimado: ~10 minutos.

## 1. Criar o projeto

1. Acesse [railway.app](https://railway.app) e faça login (pode usar a conta GitHub).
2. **New Project → Deploy from GitHub repo** → autorize o Railway no GitHub e
   selecione **`Trmartello/Controladoria`**.
3. Em **Settings → Source** do serviço criado, confira a branch de deploy:
   selecione **`claude/git-repo-overview-d17774`** (ou `main` após o merge).
   O Railway detecta o `Dockerfile` automaticamente.

## 2. Adicionar o MySQL

1. No projeto, clique em **+ Create → Database → MySQL**.
2. Aguarde o provisionamento (o Railway cria as credenciais sozinho).

## 3. Variáveis do serviço web

No serviço web (Controladoria), abra **Variables** e adicione **referências**
ao MySQL (botão *Add Reference* ou *Variable Reference*), uma a uma:

| Variável | Referência |
|---|---|
| `MYSQLHOST` | MySQL → `MYSQLHOST` |
| `MYSQLPORT` | MySQL → `MYSQLPORT` |
| `MYSQLDATABASE` | MySQL → `MYSQLDATABASE` |
| `MYSQLUSER` | MySQL → `MYSQLUSER` |
| `MYSQLPASSWORD` | MySQL → `MYSQLPASSWORD` |

> Importante: use o host/porta **privados** (`mysql.railway.internal`) que a
> referência já traz — tráfego interno não conta no egress.

E estas variáveis simples (valores seus):

| Variável | Valor | Observação |
|---|---|---|
| `ADMIN_EMAIL` | `trm.martello@gmail.com` | login inicial (opcional; padrão `admin@coperdia.com.br`) |
| `ADMIN_SENHA` | *(senha forte)* | senha inicial do admin — troque no 1º acesso |
| `APP_URL` | `https://<nome>.up.railway.app` | usada no botão dos e-mails de aviso |
| `QLIK_API_KEY` | *(opcional)* | só quando formos ativar a conectividade Qlik |

### Avisos por e-mail (opcional)

Sem as variáveis `SMTP_*` o sistema funciona normalmente, apenas não envia
e-mail. Elas e o serviço que dispara os avisos todo dia estão na **seção 6**,
com o passo a passo — inclusive o bloqueio do Microsoft 365, que é onde a
configuração costuma travar. Uma lista só, para as duas não divergirem.

## 4. Gerar a URL pública

No serviço web: **Settings → Networking → Generate Domain**. O Railway cria a
URL `https://<nome>.up.railway.app`.

## 5. Primeiro acesso

1. O deploy roda `database/migrate.php` na subida: cria as 20 tabelas, os
   seeds (6 drivers, 6 eixos, ciclo 2027–2035 com H1/H2/H3) e o usuário admin.
2. Acesse a URL → login com `ADMIN_EMAIL` / `ADMIN_SENHA`.
3. **Troque a senha** (botão no menu lateral).
4. Em **Cadastros → Negócios**, clique **Sincronizar Comercial Global** para
   importar os negócios, e ajuste os códigos do ERP.
5. Em **Cadastros → Usuários**, crie os gestores (perfil GESTOR + vínculo ao
   negócio) e os perfis de Controladoria/Direção.

## 6. Avisos por e-mail — passo a passo

O sistema sabe quem tem ação atrasada e quem tem prazo hoje. O que falta é ele
poder **avisar sem que alguém precise abrir a tela**: um relatório da semana na
segunda e as pendências do dia, todo dia. Quem faz isso é `cli/notificar.php`,
e ele precisa de duas coisas — uma conta de e-mail para enviar, e alguém que o
execute uma vez por dia.

Esta seção é para ser seguida clicando junto, como a do backup. A diferença é
que aqui **o primeiro passo não é no Railway**: é conseguir a conta de envio, e
é nele que quase todo mundo trava.

---

### Passo 0 — Conseguir uma conta que possa enviar (o passo que trava)

Enviar e-mail em nome da cooperativa exige uma caixa de e-mail que aceite envio
por programa (o chamado **SMTP**). Você vai precisar de cinco informações, e
quem as tem é o TI:

| O que pedir | Exemplo |
|---|---|
| Servidor de saída | `smtp.office365.com` |
| Porta | `587` |
| Tipo de segurança | `tls` |
| Usuário | `planejamento@coperdia.com.br` |
| Senha | *(a da caixa, ou uma "senha de aplicativo")* |

**O aviso que economiza uma tarde.** Se a Copérdia usa **Microsoft 365**, o
envio por programa vem **bloqueado de fábrica** desde 2022 — a Microsoft
desligou isso para todo mundo por segurança. Não adianta acertar host, porta e
senha: a resposta será sempre de autenticação recusada. O TI precisa **habilitar
o SMTP AUTH para essa caixa específica** (é uma opção no painel do Microsoft
365, por caixa de correio). Peça isso explicitamente, junto com os dados acima.

Se for **Gmail / Google Workspace**, o caminho é outro: a conta precisa de
verificação em duas etapas ligada e uma **senha de aplicativo** gerada só para
isto — a senha normal da conta não funciona.

Vale pedir uma caixa **própria** para o sistema (algo como
`planejamento@coperdia.com.br`), não a caixa pessoal de alguém: quando essa
pessoa trocar de senha ou sair, os avisos param sem ninguém entender por quê.

---

### Passo 1 — Conferir o endereço do sistema

Todo e-mail de aviso leva um botão que abre a ação no sistema. Esse link vem de
uma variável, e sem ela o e-mail chega sem link nenhum.

1. Abra o serviço **Controladoria** (o do sistema) → aba **Variables**.
2. Procure **`APP_URL`**.

**Se existir**, confira que o valor é o endereço público do sistema
(`https://…up.railway.app`), sem barra no fim. **Se não existir**, crie com esse
valor.

---

### Passo 2 — Testar antes de automatizar

Este é o passo que a seção do backup não tinha, e aqui ele importa mais: e-mail
vai para **pessoas de verdade**. Antes de agendar qualquer coisa, prove que o
envio funciona — e veja o que sai.

1. No serviço **Controladoria** → **Variables**, acrescente as variáveis de
   e-mail (só `SMTP_HOST` e `SMTP_REMETENTE` são obrigatórias; as outras têm
   padrão):

   | Variável | Valor |
   |---|---|
   | `SMTP_HOST` | o servidor do passo 0 |
   | `SMTP_PORTA` | `587` |
   | `SMTP_SEGURANCA` | `tls` |
   | `SMTP_USUARIO` | a caixa do passo 0 |
   | `SMTP_SENHA` | a senha do passo 0 |
   | `SMTP_REMETENTE` | a caixa do passo 0 |

2. Espere o serviço reiniciar e entre no sistema como **Admin**.
3. Vá em **Relatório de Status** e clique em **Enviar avisos por e-mail**.

**Você deve ver** uma resposta dizendo quantos foram enviados. E, na sua caixa,
o e-mail — confira se o botão dele abre o sistema.

> **Quem recebe.** Só usuários **ativos**, **com e-mail preenchido** e **com ação
> em aberto atribuída a eles**. Ninguém mais. Hoje a base tem pouquíssimo
> conteúdo, então o mais provável é que o teste envie zero e-mails — o que
> prova a conexão, mas não o conteúdo. Para ver um de verdade, atribua uma ação
> a você mesmo, com prazo para hoje, e clique de novo.

Se a resposta trouxer erro de autenticação, volte ao passo 0: é o bloqueio do
Microsoft 365 em nove de cada dez casos.

---

### Passo 3 — Criar o serviço que envia todo dia

Agora sim, o Railway. É o mesmo caminho do backup, **sem volume** — este
serviço não grava arquivo nenhum.

1. No projeto, **+ Create → Empty Service**.
2. Em **Settings → Source → Connect Repo**: `Trmartello/Controladoria`, e a
   **mesma branch** do serviço web.
3. Renomeie o serviço para **`avisos`**.

---

### Passo 4 — As variáveis

No serviço **`avisos`** → aba **Variables** → **Raw Editor**, cole (trocando os
valores de e-mail pelos seus):

```
MYSQLHOST=${{MySQL.MYSQLHOST}}
MYSQLPORT=${{MySQL.MYSQLPORT}}
MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}
MYSQLUSER=${{MySQL.MYSQLUSER}}
MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}
APP_URL=https://<o endereço do sistema>
SMTP_HOST=smtp.office365.com
SMTP_PORTA=587
SMTP_SEGURANCA=tls
SMTP_USUARIO=planejamento@coperdia.com.br
SMTP_SENHA=<a senha>
SMTP_REMETENTE=planejamento@coperdia.com.br
```

---

### Passo 5 — O comando e a hora

Ainda em **Settings**:

- **Custom Start Command**: `php cli/notificar.php`
- **Cron Schedule**: `0 11 * * *`

`0 11` em Greenwich é **8h da manhã em Brasília** — o aviso chega antes do
expediente começar, que é quando ele serve para alguma coisa.

O comando decide sozinho o que mandar: **na segunda** o relatório da semana e,
**todo dia**, as pendências do dia. Rodar duas vezes no mesmo dia não duplica
nada: cada envio fica registrado na tabela `envio_email`, e só conta como
enviado o que saiu sem erro — uma queda do servidor de e-mail não bloqueia a
tentativa seguinte.

---

### Passo 6 — Provar que rodou

Como no backup, force uma execução em vez de esperar amanhã: troque o
agendamento para `*/5 * * * *`, mande **Deploy**, e leia a aba **Cron Runs**.

**Você deve ver** uma linha por destinatário, assim:

```
notificar[diario]: 3 enviado(s), 0 falha(s), 0 já enviado(s) hoje, 12 sem pendência.
```

**Depois volte o agendamento para `0 11 * * *`.**

Se aparecer `SMTP não configurado`, alguma das duas obrigatórias
(`SMTP_HOST`, `SMTP_REMETENTE`) não chegou até aqui.

---

### Para forçar ou testar pela linha de comando

```bash
php cli/notificar.php            # decide pelo dia
php cli/notificar.php semanal    # só o relatório da semana
php cli/notificar.php diario     # só as pendências do dia
php cli/notificar.php auto 2027-03-01   # simula outra data, para conferir a regra
```

---

### Vale a pena mesmo sem e-mail configurado?

Vale, e é um efeito colateral que convém conhecer: **antes** de olhar o SMTP,
este comando faz a faxina das três tabelas que só crescem (sessões vencidas e as
contagens de tentativa de login e de PIN). O migrate faz o mesmo a cada deploy,
mas um sistema que fica semanas sem deploy nenhum não teria quem limpasse.

Ou seja: mesmo que os e-mails demorem a ser liberados pelo TI, o serviço `avisos`
agendado já paga o próprio aluguel. Ele vai registrar falha todo dia enquanto o
SMTP não existir — o que é honesto, e some sozinho quando as variáveis chegarem.

## 7. Backup do banco — passo a passo

O banco **é** o sistema. Além do planejamento inteiro, ele guarda os anexos dos
comentários dentro das próprias tabelas, porque o disco do contêiner do Railway
é apagado a cada deploy. Não existe pasta de arquivos para copiar: fazer backup
aqui é tirar uma cópia do MySQL, e quem faz isso é `cli/backup.sh`.

Esta seção é para ser seguida **clicando junto**. Cada passo tem um "você deve
ver" no fim — se o que aparecer for diferente, pare ali e vá para *Se der errado*.

---

### Passo 0 — De qual branch o Railway faz deploy?

Isso decide se você precisa fazer alguma coisa antes de tudo. O backup só
funciona se a imagem publicada tiver o cliente do MySQL instalado, e ele entrou
no `Dockerfile` na branch de trabalho — **não está na `main`**.

1. Abra o projeto no [railway.app](https://railway.app).
2. Clique no serviço **web** (o do sistema, não o do banco).
3. Vá em **Settings** e procure a área **Source** (ou *Service Source*).
4. Leia o nome da **branch**.

**Você deve ver** uma destas duas situações:

| O que aparece | O que fazer |
|---|---|
| `claude/git-repo-overview-d17774` | Nada. Cada envio de código já virou deploy; a imagem já tem o cliente do MySQL. Siga para o passo 1. |
| `main` | O sistema publicado está **muito atrás** do código atual (a `main` não tem sequer o arquivo de backup). Antes de continuar, é preciso juntar a branch de trabalho na `main` — peça isso, é uma operação de código, não de configuração. |

---

### Passo 1 — O Railway já não faz backup sozinho?

Vale checar antes de montar qualquer coisa: dependendo do plano, o próprio
Railway oferece cópia automática do banco, e ligar um botão é mais simples do
que tudo o que vem abaixo.

1. Clique no serviço do **MySQL** (não no do sistema).
2. Procure uma aba ou seção chamada **Backups**.

São três respostas possíveis:

| O que aparece | O que fazer |
|---|---|
| A aba não existe | Siga para o passo 2 — é o caminho completo. |
| *"Backups and point-in-time recovery (PITR) are only available for customers on the **Pro plan**"* | É o caso deste projeto. O recurso existe, mas está fora do plano atual: **não há atalho**, siga para o passo 2. |
| A aba deixa você ligar | Ligue — e **ainda assim faça o passo 8**. |

O passo 8 continua valendo mesmo com o backup do provedor ligado, porque são
coisas diferentes: o do Railway protege contra erro *dentro* do Railway; o
`cli/backup.sh` te dá um **arquivo na sua mão**, que abre em qualquer MySQL,
inclusive fora do Railway.

> Aviso de leitura: a mensagem *"No Backups — This service's volume does not have
> any backups"* fala do **volume do MySQL**, não do nosso arquivo. Depois de
> montar os passos 2 a 6, essa tela continuará dizendo a mesma coisa — o backup
> do `cli/backup.sh` mora no volume do serviço `backup`, e o Railway não o
> enxerga aqui. Quem mostra que ele rodou são os **Logs** do passo 6.

---

### Passo 2 — Criar o serviço que vai rodar o backup

Um "serviço" no Railway é uma caixa que roda um comando. O do sistema roda o
site; este vai rodar só o backup, uma vez por dia, e desligar.

1. Dentro do projeto, clique em **+ Create** (ou *New*).
2. Escolha **Empty Service** (serviço vazio).
3. Quando ele pedir a origem do código, aponte para o mesmo repositório
   **`Trmartello/Controladoria`** e a **mesma branch** que você leu no passo 0.
4. Renomeie o serviço para **`backup`** (clique no nome, em Settings) — só para
   você reconhecer depois.

**Você deve ver** um terceiro quadrado no projeto, ao lado do serviço web e do
MySQL, chamado `backup`.

> Ele vai tentar subir e falhar nas primeiras vezes, porque ainda não tem
> configuração. É esperado; os próximos passos resolvem.

---

### Passo 3 — Dar um lugar ao arquivo (o volume)

Este é **o passo que decide se o backup é de verdade**. O disco do contêiner é
apagado a cada deploy: sem um volume, o arquivo é gerado, some no dia seguinte,
e você fica com a impressão de ter backup sem ter.

Um "volume" é um disco que sobrevive aos deploys.

1. Com o serviço **`backup`** aberto, vá em **Settings**.
2. Procure **Volumes** → **Add Volume** (ou *Attach Volume*).
3. No campo de caminho (*Mount Path*), digite exatamente:

   ```
   /backups
   ```

**Você deve ver** o volume listado, com o caminho `/backups`.

> Um serviço só pode ter um volume, e o volume pertence a esse serviço. Por isso
> ele fica no serviço de backup, não no do sistema.

**No plano Hobby o teto é 5 GB por volume**, e esse teto é a única coisa que
separa "backup rodando" de "backup parado sem avisar": cheio o disco, o dump sai
cortado, o script recusa o arquivo e o dia fica sem cópia. Quem gasta esse
espaço são os **anexos dos comentários** — cada arquivo vale até 5 MB, vai
inteiro para dentro do banco e **não encolhe no `gzip`** (PDF e JPEG já estão
comprimidos). Não é preciso adivinhar: o passo 6 imprime o tamanho do arquivo, e
o passo 4 usa esse número para escolher quantos guardar.

---

### Passo 4 — Dizer ao backup qual é o banco (as variáveis)

O serviço de backup precisa das mesmas credenciais do MySQL que o serviço web
usa. No Railway isso se faz por **referência**: em vez de copiar a senha, você
aponta para o serviço do banco, e ele preenche sozinho.

1. No serviço **`backup`**, abra a aba **Variables**.
2. Clique em **Add Reference** (ou *Variable Reference*) e adicione, **uma a
   uma**, estas cinco:

   | Variável | Aponte para |
   |---|---|
   | `MYSQLHOST` | MySQL → `MYSQLHOST` |
   | `MYSQLPORT` | MySQL → `MYSQLPORT` |
   | `MYSQLDATABASE` | MySQL → `MYSQLDATABASE` |
   | `MYSQLUSER` | MySQL → `MYSQLUSER` |
   | `MYSQLPASSWORD` | MySQL → `MYSQLPASSWORD` |

3. Agora clique em **New Variable** (variável comum, digitada) e acrescente:

   | Variável | Valor | Para que serve |
   |---|---|---|
   | `BACKUP_DIR` | `/backups` | onde gravar — o mesmo caminho do volume do passo 3 |
   | `BACKUP_MANTER` | `14` | quantos arquivos guardar; os mais antigos são apagados sozinhos |

**Você deve ver** sete variáveis na lista: cinco com ícone de referência ao
MySQL e duas digitadas por você.

**Por que 14 e não 30.** No plano Hobby o volume vai até 5 GB, e o script apaga
os antigos *depois* de gravar o novo — ou seja, o pico é `BACKUP_MANTER + 1`
arquivos ao mesmo tempo. A conta é `tamanho do arquivo × 15` e precisa caber
com folga nos 5 GB. Rode o passo 6 primeiro, veja o tamanho impresso no log e
volte aqui:

| Tamanho de um arquivo | Guarde |
|---|---|
| até 50 MB | `30` — um mês inteiro cabe |
| 50 a 150 MB | `14` (duas semanas) |
| 150 a 300 MB | `7` (uma semana) |
| acima de 300 MB | `7`, **e me avise** — nesse tamanho o caminho é tirar os anexos do banco, não encolher a retenção |

Comece em `14`: é o valor que funciona sem saber o tamanho ainda. Com `0` nada é
apagado nunca — não use aqui, o disco enche e o backup para.

---

### Passo 5 — Dizer o que rodar e quando

1. Ainda no serviço **`backup`**, vá em **Settings**.
2. Em **Custom Start Command** (comando de início), digite exatamente:

   ```
   ./cli/backup.sh
   ```

3. Procure **Cron Schedule** (agendamento) e digite:

   ```
   0 7 * * *
   ```

**O que esses números querem dizer:** minuto 0, hora 7, todo dia. O Railway usa
o relógio de Greenwich (UTC), que está 3 horas à frente do nosso — então
**7h em UTC é 4h da manhã aqui**. É de propósito: a essa hora ninguém está
usando o sistema.

**Você deve ver**, na tela do serviço, o comando e o agendamento salvos.

> **O Cron Schedule não é opcional — é o que impede a conta de crescer.** O
> plano Hobby inclui US$ 5 de crédito por mês, e um serviço *sem* agendamento o
> Railway trata como servidor: ele roda o comando, o comando termina em poucos
> segundos, o Railway reinicia, e assim a noite inteira. Não quebra nada, mas
> consome crédito de graça e enche o log de reinícios. **Com** o agendamento,
> ele acorda uma vez por dia, gasta menos de um minuto e desliga — custo
> desprezível.

---

### Passo 6 — Testar agora, sem esperar amanhã

Não espere as 4h da manhã para descobrir se funcionou.

1. No serviço **`backup`**, force uma execução: procure **Deploy** /
   **Redeploy** (ou *Run now*, se o Railway oferecer).
2. Abra a aba **Logs** (ou *Deploy Logs*) e leia até o fim.

**Você deve ver** três linhas parecidas com estas:

```
Banco:   railway em mysql.railway.internal:3306 (cliente: mysqldump)
Gravando /backups/railway-2026-08-08-040000.sql.gz …
✓ /backups/railway-2026-08-08-040000.sql.gz (1,2M, 36 tabelas)
```

O ✓ na última linha é a confirmação de que o arquivo foi gerado **e conferido**
— o programa só o batiza depois de checar que não veio truncado. Se aparecer um
✗, vá para *Se der errado*, no fim desta seção.

> O nome do arquivo começa com o nome do banco. No Railway o banco costuma
> chamar-se `railway`, então o arquivo sai como `railway-<data>.sql.gz`.

---

### Passo 7 — Conferir no dia seguinte

Um dia depois, abra os **Logs** do serviço `backup` e confirme que rodou
sozinho às 4h. Aproveite e anote o **tamanho do arquivo** que aparece no ✓ —
é com ele que você fecha a escolha do `BACKUP_MANTER` no passo 4.

> **O log do plano Hobby guarda 7 dias.** Depois disso não há como olhar para
> trás e saber se o backup de três semanas atrás rodou. Por isso a conferência
> não pode ser "vou olhar quando lembrar": faça o passo 8 uma vez por mês — o
> arquivo que chega no seu computador é a prova que não expira.

---

### Passo 8 — A cópia que fica com você

O volume protege contra deploy e contra apagão acidental de tabela. Ele **não**
protege contra perder a conta do Railway, e não dá para baixar um arquivo de
dentro dele com facilidade. Por isso existe a segunda cópia: rodada do seu
computador, ela deixa o arquivo no seu disco.

Isso exige ter o projeto baixado e o cliente do MySQL instalado na sua máquina.
Uma vez por mês já muda o jogo.

1. No Railway, clique no serviço do **MySQL** → aba **Variables**.
2. Procure os valores **públicos** (nomes como `MYSQL_PUBLIC_URL` ou
   `RAILWAY_TCP_PROXY_DOMAIN` e `RAILWAY_TCP_PROXY_PORT`). Anote o **host** e a
   **porta** públicos, e a **senha** (`MYSQLPASSWORD`).
3. No seu computador, dentro da pasta do projeto, rode:

```bash
MYSQLHOST=<host público> MYSQLPORT=<porta pública> MYSQLDATABASE=railway \
MYSQLUSER=root MYSQLPASSWORD=<senha> ./cli/backup.sh
```

O arquivo aparece em `./backups`. **Guarde-o fora do computador também** — nuvem
pessoal, HD externo, o que preferir.

> O arquivo contém e-mail de todo mundo, senhas cifradas e os anexos dos
> comentários. Ele nasce legível só para o seu usuário; trate-o como documento
> confidencial e não o coloque em pasta compartilhada aberta.

---

### Passo 9 — Provar que dá para restaurar

Backup que ninguém testou é esperança, não backup. O teste barato:

```bash
./cli/backup.sh verificar backups/railway-2026-08-08-040000.sql.gz
```

**Você deve ver** o cabeçalho do arquivo e a linha `✓ íntegro: 36 tabela(s)`.

O teste completo — restaurar num banco descartável — está em `testes/README.md`.
Vale fazer uma vez, com calma, antes de precisar.

---

### Restaurar de verdade (o dia ruim)

```bash
./cli/backup.sh restaurar backups/railway-2026-08-08-040000.sql.gz
```

Ele **recusa** arquivo corrompido ou cortado antes de tocar no banco, mostra
para qual banco vai escrever e **pede que você digite o nome do banco** para
confirmar — restaurar apaga o trabalho feito desde a data do arquivo, e um "sim"
distraído é barato demais para o tamanho do estrago.

Se o arquivo for mais antigo que o código publicado, rode depois
`php database/migrate.php` para o schema alcançar a versão atual.

---

### Os outros comandos

```bash
./cli/backup.sh                       # gera o arquivo
./cli/backup.sh listar                # o que existe, do mais novo ao mais velho
./cli/backup.sh verificar <arquivo>   # confere sem restaurar nada
./cli/backup.sh restaurar <arquivo>   # DESTRUTIVO — pede confirmação
./cli/backup.sh --help                # a ajuda inteira
```

O que ele guarda: **tudo**, menos as linhas de três tabelas descartáveis
(sessões abertas e as contagens de tentativa de login). A estrutura delas vai
junto; só o conteúdo fica de fora, porque restaurar sessões devolveria acesso a
quem estava logado no dia do backup.

---

### Se der errado

| O que aparece no log | O que está acontecendo | O que fazer |
|---|---|---|
| `nem mysqldump nem mariadb-dump no PATH` | A imagem publicada é antiga e não tem o cliente do MySQL | Volte ao passo 0: a branch do deploy está errada, ou falta um deploy novo |
| `php não encontrado no PATH` | O serviço não está usando a imagem do projeto | Confira se o serviço `backup` aponta para o repositório certo (passo 2) |
| `Access denied for user` | Credenciais erradas | Refaça o passo 4: as cinco variáveis precisam ser **referências** ao MySQL, não valores digitados |
| `Can't connect to MySQL server` | Endereço errado ou banco fora do ar | Confira `MYSQLHOST`; ele deve ser o interno (`mysql.railway.internal`) |
| `TLS/SSL error: self-signed certificate` (erro 2026) | O cliente exigia certificado de autoridade conhecida, e banco gerenciado assina o próprio | **Já corrigido** — a conexão segue cifrada, só não confere quem assinou. Se voltar, confira se `BACKUP_SSL_VERIFICAR` não ficou em `1` |
| `caching_sha2_password` | Incompatibilidade de autenticação | Avise — é ajuste de imagem, não de configuração |
| `dump truncado` ou `não passa no teste do gzip` | Espaço acabou ou a execução foi interrompida | Aumente o volume ou reduza `BACKUP_MANTER`; **nenhum arquivo ruim é salvo** |
| O arquivo é gerado mas some no dia seguinte | O volume não está montado | Refaça o passo 3 e confira que `BACKUP_DIR` é `/backups` |
| O serviço fica reiniciando sem parar | Está configurado como serviço comum, não agendado | Confira o **Cron Schedule** do passo 5 |

## 8. Iterando

Cada `git push` na branch configurada dispara um novo deploy automaticamente.
A migração é idempotente — os dados existentes são preservados.

## Diagnóstico rápido

- **Deploy falhou no build**: veja a aba *Build Logs* do serviço.
- **App no ar mas erro de banco**: confira as 5 referências `MYSQL*` na aba
  *Variables* e veja os *Deploy Logs* (a migração loga "aguardando banco" /
  "migrate: ok").
- **502 logo após o deploy**: aguarde ~60s; a migração espera o MySQL
  responder (até 30 tentativas) e, se o banco não vier, o container **aborta o
  start** — o Railway reinicia/mantém o deploy anterior. Veja nos *Deploy
  Logs* a linha "migrate: conectando em ..." para conferir o endpoint usado.

> **Nota de operação**: o serviço roda no servidor embutido do PHP
> (`php -S`, com `PHP_CLI_SERVER_WORKERS=8`) — adequado para
> homologação/validação com poucas dezenas de usuários. Para produção
> definitiva, migrar para php-fpm + nginx (ou Apache) é o próximo passo.

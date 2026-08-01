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

Sem estas variáveis o sistema funciona normalmente, apenas não envia e-mail.

| Variável | Exemplo | Observação |
|---|---|---|
| `SMTP_HOST` | `smtp.office365.com` | servidor de saída |
| `SMTP_PORTA` | `587` | 587 com `tls`, 465 com `ssl` |
| `SMTP_SEGURANCA` | `tls` | `tls`, `ssl` ou `nenhuma` |
| `SMTP_USUARIO` | `planejamento@coperdia.com.br` | deixe vazio se o servidor não pede autenticação |
| `SMTP_SENHA` | *(senha do e-mail)* | |
| `SMTP_REMETENTE` | `planejamento@coperdia.com.br` | endereço que aparece como remetente |
| `SMTP_NOME_REMETENTE` | `Planejamento Estratégico Copérdia` | opcional |

Depois de configurar, teste pelo próprio sistema: **Relatório de Status →
Enviar avisos por e-mail** (botão visível para o perfil Admin).

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

## 6. Agendar os avisos por e-mail

Os avisos saem de um comando de linha; o Railway precisa executá-lo uma vez
por dia. No projeto: **+ Create → Empty Service**, aponte para o mesmo
repositório e, em **Settings**:

- **Cron Schedule**: `0 11 * * *` (o Railway usa UTC — 11h UTC = 8h de Brasília);
- **Custom Start Command**: `php cli/notificar.php`;
- em **Variables**, replique as referências `MYSQL*` e as variáveis `SMTP_*`.

O comando decide sozinho o que enviar: na **segunda-feira** manda o relatório
da semana e, **todo dia**, as pendências do dia. Rodar duas vezes no mesmo dia
não duplica nada — cada envio fica registrado na tabela `envio_email`.

Para forçar manualmente (ou testar):

```bash
php cli/notificar.php            # decide pelo dia
php cli/notificar.php semanal    # só o relatório da semana
php cli/notificar.php diario     # só as pendências do dia
```

## 7. Iterando

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

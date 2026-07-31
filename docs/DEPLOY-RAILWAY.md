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
| `QLIK_API_KEY` | *(opcional)* | só quando formos ativar a conectividade Qlik |

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

## 6. Iterando

Cada `git push` na branch configurada dispara um novo deploy automaticamente.
A migração é idempotente — os dados existentes são preservados.

## Diagnóstico rápido

- **Deploy falhou no build**: veja a aba *Build Logs* do serviço.
- **App no ar mas erro de banco**: confira as 5 referências `MYSQL*` na aba
  *Variables* e veja os *Deploy Logs* (a migração loga "aguardando banco" /
  "migrate: ok").
- **502 logo após o deploy**: aguarde ~30s; o container espera o MySQL
  responder (até 30 tentativas) antes de subir o Apache.

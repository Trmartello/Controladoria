# Controladoria — Sistema de Planejamento Estratégico Copérdia

Sistema one-page em **PHP 8.2+ / MySQL 8** para gestão do planejamento
estratégico por **drivers e horizontes** (ciclo 2027–2035). Arquitetura completa
em [`docs/PLANEJAMENTO-SISTEMA.md`](docs/PLANEJAMENTO-SISTEMA.md).

## Estado atual — Fase 1 (Fundação)

- Login com sessão, CSRF e perfis (Admin, Controladoria, Direção, Gestor, Leitura)
- Autorização por **usuário × negócio** — Controladoria e Direção veem tudo
- Cadastros em **formulário modal**: negócios (cód. + nome, seleção
  "8 - Agropecuária", sincronização com o Comercial Global/Qlik), ciclos com ano
  do planejamento, horizontes com tema e objetivo, drivers, eixos e usuários
- Planejamento por **negócio** e **corporativo** (criado automaticamente por ciclo)
- Hub do Planejamento com checklist das etapas do método
- Deploy pronto para **Railway** (Dockerfile + migração automática com seeds)

Próximas fases: diagnóstico (PESTEL, Porter, SWOT, GUT), cascata de escolhas,
projetos 5W2H, governança de investimentos, painéis e relatório de status.

## Rodar localmente

Requisitos: PHP 8.2+ com `pdo_mysql` e um MySQL 8.

```bash
# variáveis de conexão (padrões: 127.0.0.1:3306, banco "planejamento", root sem senha)
export DB_HOST=127.0.0.1 DB_NAME=planejamento DB_USER=root DB_PASS=

php database/migrate.php          # cria schema + seeds + usuário admin
php -S localhost:8080 -t public   # servidor de desenvolvimento
```

Acesse `http://localhost:8080` — login inicial `admin@coperdia.com.br` com a
senha da variável `ADMIN_SENHA` (padrão `trocar123`; **troque no primeiro
acesso**, botão "Trocar senha" no menu).

## Deploy no Railway (validação)

1. Crie um projeto no Railway com **2 serviços**: este repositório (o Dockerfile
   é detectado automaticamente) e um **MySQL**.
2. No serviço web, adicione as variáveis referenciando o MySQL:
   `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`
   (no Railway: *Variables → Add Reference*). Opcionais: `ADMIN_EMAIL`,
   `ADMIN_SENHA`, `QLIK_API_KEY`.
3. O deploy roda a migração na subida (schema + seeds idempotentes) e publica a
   URL `*.up.railway.app` para distribuir às equipes.

## Estrutura

```
public/          # docroot: front controller + assets (js/css)
app/             # Core (Router/PDO/Auth/Json), Controllers (API JSON), Services
views/           # shell.php (página única) e login.php
database/        # schema.sql, seeds.sql, migrate.php
config/          # config.php — tudo via variáveis de ambiente
Dockerfile       # php:8.3-apache para o Railway
```

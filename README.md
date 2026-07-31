# Controladoria — Sistema de Planejamento Estratégico Copérdia

Sistema one-page em **PHP 8.2+ / MySQL 8** para gestão do planejamento
estratégico por **drivers e horizontes** (ciclo 2027–2035). Arquitetura completa
em [`docs/PLANEJAMENTO-SISTEMA.md`](docs/PLANEJAMENTO-SISTEMA.md).

## Estado atual — Fases 1 a 6 entregues

- **Fase 1 — Fundação**: login com sessão, CSRF e perfis (Admin, Controladoria,
  Direção, Gestor, Leitura); autorização por **usuário × negócio**; cadastros em
  formulário modal (negócios com sincronização Qlik, ciclos, horizontes,
  drivers, eixos, usuários); Hub do Planejamento; deploy no Railway
- **Fase 2 — Diagnóstico**: análise de cenário, PESTEL, Porter, SWOT e Matriz GUT
- **Fase 3 — Cascata de Escolhas**: matriz drivers × horizontes com aberturas
  por eixo, renúncias e vínculo com os fatores da GUT
- **Fase 4 — Execução**: projetos, desdobramentos 5W2H e diário de bordo
- **Fase 5 — Capital**: envelope → papel → ranking por taxa de retorno →
  decisão com critério registrado → auditoria +12M
- **Fase 6 — Gestão**: métricas-âncora e metas plurianuais (meta × real com
  versões de revisão), painel consolidado (negócio, corporativo e geral),
  relatório de status da reunião (tela, impressão/PDF e Excel)
- Interface **responsiva para mobile**: menu recolhe e expande automaticamente
  (off-canvas com ☰ em telas pequenas) e tabelas rolam no próprio contêiner

## Rodar localmente

Requisitos: PHP 8.2+ com `pdo_mysql` e um MySQL 8.

```bash
# variáveis de conexão (padrões: 127.0.0.1:3306, banco "planejamento", root sem senha)
export DB_HOST=127.0.0.1 DB_NAME=planejamento DB_USER=root DB_PASS=

php database/migrate.php          # cria schema + seeds + usuário admin
php -S localhost:8080 -t public   # servidor de desenvolvimento
```

Acesse `http://localhost:8080` — login inicial `admin@coperdia.com.br` com a
senha da variável `ADMIN_SENHA`. Se a variável não estiver definida, a migração
**gera uma senha aleatória e a mostra no log** uma única vez — anote-a e
**troque no primeiro acesso** (botão "Trocar senha" no menu).

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
Dockerfile       # php:8.3-cli + servidor embutido (homologação no Railway)
```

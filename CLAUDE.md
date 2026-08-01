# CLAUDE.md — Guia do projeto Controladoria

Sistema de planejamento estratégico da Copérdia (one-page app em PHP 8 + MySQL,
deploy no Railway). Idioma do código, commits e UI: **português**.

## Arquitetura

- **Front controller**: `public/index.php` — tabela de rotas em `switch (true)`.
  Todo método de controller termina em `Json::ok()`/`Json::erro()` (ambos
  encerram a execução), mas cada `case` ainda leva `break;` defensivo.
- **Core** (`app/Core/`): `Auth` (sessão, perfis ADMIN/CONTROLADORIA/DIRECAO/
  GESTOR/LEITURA, escopo usuário×negócio, CSRF via header `X-CSRF-Token`),
  `Database` (PDO, sempre prepared statements), `Json`, `SessaoBanco`
  (sessões em MySQL na tabela `sessao` — sobrevivem a deploys; cookie 30 dias).
- **Serviços** (`app/Services/`): `QlikSync`, `Recorrencia` (repetição das
  ações — usada pelo cadastro **e** pelo diário), `Avisos` (e-mails do plano
  de ação). Autoload PSR-4 caseiro em `public/index.php` (`App\` → `app/`);
  **não há Composer nem `vendor/`** — nada de dependência externa em PHP.
- **Frontend**: JS vanilla, sem build. Seções em `public/assets/js/secoes/*.js`
  registradas em `App.recarregarSecaoAtiva()` (`app.js`). Formulários via
  fábrica declarativa `Modal.abrir({campos, url, valores, transformar, extra,
  aoSalvar})` (`modal.js`).
  Bootstrap 5.3.3 **vendorado** em `public/assets/vendor/` (CDNs são
  bloqueados no ambiente de execução — nunca referencie CDN).
- **Tipos de campo do modal**: `text`, `textarea`, `select`, `multiselect`,
  `checkbox`, `password`, `number`, `date`, `hidden`, `periodo` (duas datas),
  `info` (bloco só de leitura, com barra colorida opcional), `botoes` (option
  buttons), `quadrantes` (matriz SWOT 2×2), `selecao_livre` (combobox com
  busca que aceita nome novo), `faixa` (slider) e `lista_marcavel` (itens
  marcáveis com selos coloridos, descrição cortada em 3 linhas com “ver mais”,
  pesquisa acima de 5 itens e contador — usar sempre que o usuário precise ler
  o item antes de marcar; `multiselect` só serve para listas curtas e não
  funciona no celular, onde não existe tecla Ctrl). Opções auxiliares:
  `obrigatorio`, `visivelSe: {campo, valores}`, `exemplo`, `ajuda`, `nota`,
  `sufixo`, `passo`. Datas aparecem sempre como dd/mm/aaaa (`ligarDatasBr`).
  Textareas crescem com o texto até 60% da altura da tela e depois rolam por
  dentro (`crescerTextarea`). Medida que depende de layout (o que transborda,
  quanto o texto ocupa) vai em `Modal.aoAparecer`, disparada no
  `shown.bs.modal` — com o modal escondido toda altura vale zero.
- **Cache busting**: todo asset é referenciado nas views com
  `versao_asset('/assets/...')` (acrescenta `?v=filemtime`). Assets novos em
  views devem usar esse helper, senão o cache de 24h serve versão velha.
- **Segurança**: CSP sem script inline (JS sempre em arquivo externo), headers
  em `public/index.php`. Não introduzir `onclick=` ou `<script>` inline.

## Regras de negócio importantes

- **Horizontes (H1/H2/H3) valem para o ciclo (plurianual); análises de
  diagnóstico (cenário, PESTEL, Porter, SWOT, GUT) são anuais** — coluna `ano`
  em `fator` e `cenario_item`, seletor de ano compartilhado em
  `diagnostico.js` (`Diag`), limitado a [ano_base, ano_fim] do ciclo.
- Promoção PESTEL/Porter → SWOT copia o `ano`; o botão do fator promovido
  mostra a categoria SWOT na cor do quadrante e reabre a edição.
- Metas plurianuais versionadas: `indicador_valor` única por
  (indicador, ano, tipo, versão); leitura usa a MAIOR versão de cada ano.
- Investimentos decididos nunca voltam a PROPOSTO; APROVADO só avança para
  EXECUTADO.
- Negócios vêm do Qlik (`FlagFilialNegocio`, códigos oficiais em
  `App\Services\QlikSync::NEGOCIOS_FONTE`); linhas manuais nunca são
  sobrescritas pela sincronização.
- Excluir um fator de PESTEL/Porter/SWOT remove também o promovido para a SWOT
  e a linha correspondente na matriz GUT (`FatorController::excluir`).

### Plano de ação (três níveis)

- **projeto → iniciativa → ação**, espelhando o projeto BSC. O cadastro do
  projeto tem só ano, título, descrição e responsável; **início e fim são
  consequência das ações** (menor `data_inicio`, maior `data_fim`) e o status
  agrega o das ações — tudo recalculado na leitura por `consolidarProjetos()`.
- O **ano** define o horizonte: `horizonte.ano_inicio/ano_fim` cobrem o ciclo e
  o projeto herda o H1/H2/H3 pelo ano. Não existe mais ação plurianual × anual.
- Status `NAO_INICIADO` e `ATRASADO` são **automáticos** (derivados da
  data-limite, reconciliados em `sincronizarAtrasos()`); os demais são manuais.
- **Recorrência** (`recorrencia` NENHUMA/SEMANAL/MENSAL + `recorrencia_dia` +
  `recorrencia_ate`): concluir uma ocorrência não encerra a ação — ela reabre
  na próxima data prevista e a conclusão fica no diário. A regra está em
  `App\Services\Recorrencia` e vale para os **dois** caminhos que concluem uma
  ação (cadastro e diário de bordo); o reagendamento avança ocorrência a
  ocorrência até passar de hoje.
- **Avisos por e-mail** (`App\Services\Avisos` + `App\Core\Email`, SMTP na
  mão): relatório semanal na segunda e pendências do dia. `envio_email` é a
  trava contra duplicidade — só conta como enviado o registro com
  `erro IS NULL`, para uma queda do SMTP não bloquear o aviso.
  Disparo por cron (`php cli/notificar.php`) ou pelo botão do Relatório.
- Cartões de projeto/iniciativa/ação mostram só título e situação; o resto vai
  atrás de **“mostrar mais”**. As barras de progresso usam sempre o mesmo
  estilo (`.faixa-progresso` para leitura, `input[type=range].faixa-verde`
  para ajuste) com **passo 1** — passo maior faria o valor divergir do
  servidor.

## Migrações e seeds

- `database/migrate.php` é **idempotente** e roda a cada deploy
  (`entrypoint.sh` aborta o start se falhar). Estatements no `schema.sql`
  separados por `;` em fim de linha; comentários só com `--` no início.
- `ALTER TABLE` novo: usar `garantirColuna()` (checa information_schema).
- Seeds (`database/seeds.sql`) só inserem quando a tabela/contexto está vazio
  (`WHERE NOT EXISTS (SELECT 1 FROM tabela)`) — renomear algo pela UI não pode
  recriar linhas.
- Compatibilidade MySQL 8 **e** MariaDB (por isso `ON DUPLICATE KEY UPDATE
  VALUES()` e nada de sintaxe exclusiva do MySQL 8).

## Rodando localmente

```bash
# Banco (MariaDB local; socket precisa de caminho curto)
mariadbd --user=root --datadir=<dir> --socket=/tmp/ccm.sock --port=33061 &

php database/migrate.php   # com as env DB_* abaixo

# Servidor — o argumento router (public/index.php) é OBRIGATÓRIO
DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
  php -S 127.0.0.1:8099 -t public public/index.php
```

- Login local de teste: `admin@coperdia.com.br` / `trocar123` (em produção a
  senha inicial vem de `ADMIN_SENHA`; sem ela o migrate gera uma aleatória e
  imprime uma única vez no log).
- Validação visual: Playwright com Chromium pré-instalado
  (`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`,
  `require('/opt/node22/lib/node_modules/playwright')`), testar desktop
  (1500×800) e mobile (390×844). Web Speech API não existe no headless —
  simule com `window.webkitSpeechRecognition = class {...}` para o microfone
  aparecer. Para preparar massa de teste, chame a própria API pelo
  `page.evaluate` (`App.api(...)`) e apague o que criou ao final.
- Dados inseridos pelo cliente `mysql` sem `--default-character-set=utf8mb4`
  saem com acentuação quebrada; o caminho do PDO da aplicação está correto.

## Deploy

- Railway, servidor embutido do PHP (`php -S` no `entrypoint.sh`) — adequado a
  homologação; para produção o recomendado é php-fpm + nginx
  (ver `docs/DEPLOY-RAILWAY.md`).
- Após deploy com mudança de CSS/JS, um refresh normal já pega a versão nova
  (graças ao `versao_asset`).
- E-mail: variáveis `SMTP_*` e `EMAIL_REMETENTE` (tabela em
  `docs/DEPLOY-RAILWAY.md`); sem elas os avisos ficam desligados. O envio
  diário depende de um cron do Railway chamando `php cli/notificar.php`.

## Convenções de entrega

- Branch de trabalho: `claude/git-repo-overview-d17774` — desenvolver,
  commitar e fazer `git push -u origin` sempre nessa branch.
- Mensagens de commit em português, primeira linha descritiva.
- Ao concluir trabalho grande: rodar o time de agentes de revisão
  (segurança, corretude, infra) e aplicar os achados confirmados; manter a
  responsividade mobile; validar com Playwright antes de commitar.
- Roadmap e especificações: `docs/PLANEJAMENTO-SISTEMA.md` (fases 1–6 já
  entregues) e `docs/BACKLOG-EVOLUCAO.md` (matriz de impacto por negócio,
  triagem pós-brainstorm, mapa BSC, plano de contingência e ritual de
  acompanhamento — com o veredito de o que vale ou não construir).

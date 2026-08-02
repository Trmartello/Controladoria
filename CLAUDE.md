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
- Bibliotecas vendoradas do front ficam em `public/assets/vendor/` e **vão
  para o repositório**. O `.gitignore` usa `/vendor/` (só a raiz, do Composer);
  um `vendor/` solto engoliria essa pasta e o `git add -A` deixaria o arquivo
  para trás em silêncio — o deploy serviria 404.
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
- **Orientações do diagnóstico**: cada categoria (PESTEL, Porter, SWOT, Cenário)
  tem uma dica curta num ícone **ⓘ** ao lado do título, não em texto de topo —
  `Diag.ORIENTACOES_CATEGORIA` (mapa por código) + `iconeOrientacao` /
  `painelOrientacao` / `ligarOrientacoes`. Os títulos da SWOT trazem o eixo entre
  parênteses (“Forças (Interno · Ajuda)”). Não reintroduzir parágrafos de
  introdução acima das listas — a orientação mora no ⓘ.
- **Matriz de prioridade** (condução da tempestade): gráfico de **quatro
  quadrantes**, Impacto no eixo horizontal e Esforço no vertical (rótulos
  “pouco/muito” na vertical). Não há filtro de situação nessa tela.
- **Matriz GUT** (`Gravidade × Urgência × Tendência`, 1–125): cada dimensão tem
  **pergunta-guia** no modal de avaliação; score ≥64 vermelho (Alta), ≥27 dourado
  (Média), senão verde (Baixa), com a legenda distribuída na barra da matriz
  (`.gut-legenda-barra`). O botão **Redefinir** (`extra.manterAberto`) zera os
  valores **sem fechar** o modal, para continuar editando.
- Metas plurianuais versionadas: `indicador_valor` única por
  (indicador, ano, tipo, versão); leitura usa a MAIOR versão de cada ano.
- Investimentos decididos nunca voltam a PROPOSTO; APROVADO só avança para
  EXECUTADO.
- Negócios vêm do Qlik (`FlagFilialNegocio`, códigos oficiais em
  `App\Services\QlikSync::NEGOCIOS_FONTE`); linhas manuais nunca são
  sobrescritas pela sincronização.
- Excluir um fator de PESTEL/Porter/SWOT remove também o promovido para a SWOT
  e a linha correspondente na matriz GUT (`FatorController::excluir`).
- **Tempestade de ideias**: rodada com PIN de 6 dígitos (`coleta_rodada`), tela
  do participante em `/entrar/{pin}` — **as únicas rotas de escrita sem
  autenticação do sistema**. Regras que não podem ser afrouxadas: o token do
  participante é registrado em `coleta_participante` (sem isso seria
  auto-emitido); o nome vem do registro, nunca do corpo; tetos de ideias e
  votos vão **dentro do INSERT** (`INSERT ... SELECT ... WHERE (SELECT COUNT
  ...) < max`), senão dois envios simultâneos furam a contagem; PIN errado
  conta contra a origem em `coleta_tentativa`; exige `Content-Type` JSON, o
  que obriga preflight e impede escrita cross-site; `/entrar` e
  `/api/publico/*` **não iniciam sessão**. A isenção de CSRF é lista
  explícita, nunca prefixo.
  A tela ao vivo usa **consulta periódica**, nunca SSE: `php -S` é
  single-threaded e uma conexão presa trava a oficina inteira. O polling
  **nunca redesenha com um campo em foco ou com texto digitado** — nas duas
  telas. Redesenhar tira o foco e, no celular, isso **fecha o teclado no meio
  da frase**: o participante não consegue escrever.
  Vozes iguais são agrupadas na nuvem e tratadas **em grupo**: N ideias
  apontam para UM destino. Por isso `FatorController`/`CenarioController`
  fazem o JOIN pelo `MIN(id)` — juntar direto multiplicaria o card.
  O grupo é `coleta_item.agrupado_em_id` (o líder), e serve tanto ao
  automático — texto equivalente já chega apontando para o líder, via
  `PublicoController::normalizar()`, que usa tabela e **não** `Normalizer`
  (a extensão `intl` não está na imagem) — quanto ao manual, arrastando uma
  ficha sobre a outra. O arraste usa eventos de ponteiro (a API de arrastar do
  HTML não existe no toque) com os listeners no `document`, porque a ficha se
  move no DOM durante o gesto. Expandir o grupo é sempre server-side: a lista
  nunca vem do cliente.
  Fluxo da condução: agrupar → descrever (`texto_tratado`) → matriz
  (impacto × esforço) → destino, ou rejeitar, ou `adiado = 1` (caixa
  "tratar depois"). O painel do QR recolhe depois que a sala entrou.
  Na nuvem, ideia sozinha é uma ficha; grupo vira uma **caixa** (`.grupo-caixa`)
  com todas as palavras juntadas à vista (`fichaOuCaixa()`). Tocar na caixa ou
  em qualquer palavra dela leva o grupo à bancada. A caixa arrasta e é alvo de
  arraste como a ficha (`touch-action: none` nela e nos filhos). Cada palavra
  tem um ✕ que a tira só dela do grupo (`removerDoGrupo`, rota
  `/api/coleta/{id}/remover-grupo`) — juntou por engano, tira uma sem desfazer
  o resto; se sai o líder, o próximo membro é promovido. O ✕ para propagação
  (não seleciona nem arrasta a caixa).
- **Coleta de Ideias** é o passo 0 do diagnóstico: ideia crua → triagem item a
  item → item de cenário, fator **ou plano de ação** (ou descarte com motivo,
  visível ao autor). `coleta_item.destino_tipo` é ENUM
  `CENARIO`/`FATOR`/`ACAO`; a triagem (`DESTINOS_TRIAGEM` em `coleta.js`) oferece
  os três destinos. O registro criado herda o `ano` da **ideia**, nunca o do
  seletor da tela.
  O vínculo vale nos dois sentidos (selo “Coleta · Fulano” no card do
  diagnóstico, “Virou fator ↗” na ideia); apagar o destino limpa
  `destino_tipo`/`destino_id` em vez de deixar link quebrado.
  Ideia cadastrada **manualmente** enquanto uma tempestade está aberta herda o
  `rodada_id` da rodada aberta (validado no back-end) e cai na nuvem, em vez de
  sumir. Listagens que juntam ideias da tempestade (autor_id NULL) usam
  `LEFT JOIN`/`COALESCE`, nunca `INNER JOIN` — senão o card some (ex.:
  `aguardandoAcao()`).
  O encaminhamento usa **reserva atômica** (`Database::afetadas()` num UPDATE
  com a condição no WHERE) em vez de transação — o repositório não usa
  `beginTransaction` e `Json::erro()` encerra a execução.
  Escrita passa por `Auth::exigirRespostaColeta()` / `exigirTriagemColeta()`:
  existem para a regra do brainstorm poder mudar sem afrouxar a autorização
  geral. Eles **autorizam** (via `exigirEdicaoPlanejamento`) e **devolvem o
  usuário logado** — o controller grava `autor_id`/`triado_por` com esse id.
  Cuidado: `exigirEdicaoPlanejamento`/`exigirAcessoPlanejamento` retornam a
  linha do **planejamento**, não o usuário; usar esse retorno como `$u` gravava
  o id do plano em `autor_id` e estourava a FK para `usuario` (coincidia só
  quando os ids batiam). Quem precisa do usuário chama `Auth::exigirLogin()`
  além da autorização (como `DiarioController`/`RelatorioController`).
  - **Destino “Plano de ação” (`ACAO`)**: a ideia não vira fator nem cenário; o
    `encaminhar()` grava `destino_tipo='ACAO'` com `destino_id` NULL e a ideia
    fica **aguardando alocação**. `GET /api/coleta/aguardando-acao` lista as
    ideias `destino_tipo='ACAO' AND destino_id IS NULL AND situacao='ACEITO'`,
    exibidas num card na seção **Projetos** (“Ideias aguardando plano de ação”).
    Converter (`ProjetoController::salvarDesdobramento`) exige um destino de três
    níveis — **projeto → iniciativa → ação** — e pode **criar projeto e/ou
    iniciativa na hora** (`criarProjetoRapido`/`criarIniciativaRapida`); ao criar
    a ação o `destino_id` recebe o id e a ideia sai da fila. **Validar a ação
    primeiro**: sem transação (e com `Json::erro()` encerrando a execução), criar
    projeto/iniciativa antes de validar os campos da ação deixa os dois órfãos a
    cada tentativa inválida — toda a validação/cálculo da ação roda antes de
    qualquer INSERT de projeto ou iniciativa.
  - **Reclassificar** (duplo clique num item já triado, na análise de origem):
    `Diag.reclassificar()` **não** apaga nada — só navega de volta à tempestade,
    que abre um **painel próprio** (`painelReclassificar`, independente da
    rodada) com a ideia e a classificação de origem. Só ao escolher o **novo**
    destino é que `POST /api/coleta/{id}/reabrir` desfaz o registro anterior
    (apaga o fator — com `promovido_de_id` e cascata GUT — ou o `cenario_item`,
    volta a ideia a `SELECIONADO`) e o novo encaminhamento é gravado. Não-
    destrutivo: desistir da reclassificação deixa o destino original intacto.

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

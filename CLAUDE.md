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
  de ação), `Consolidacao` (reconciliação do que é *consequência*: atraso da
  ação e período/status do projeto). `Consolidacao::reconciliar($planId)` roda
  no começo de **toda leitura** que exibe esses campos — Projetos, Painel e
  Relatório. Deixá-la só em Projetos fazia o painel da direção contar zero
  atraso até alguém abrir a seção, e os números mudavam sozinhos depois. Autoload PSR-4 caseiro em `public/index.php` (`App\` → `app/`);
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
  `sufixo`, `passo`. O gatilho do `visivelSe` pode ser de qualquer tipo, mas o
  valor precisa sair de `Modal.valorAtual()`: em `botoes` e `quadrantes` o id
  fica na **div** que agrupa os rádios, e ler `.value` dela devolvia `undefined`
  — o campo dependente ficava escondido para sempre.
  Datas aparecem sempre como dd/mm/aaaa (`ligarDatasBr`).
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
  em `public/index.php` — eles vêm **antes** do `session_start()`, porque o
  handler de sessão consulta o MySQL e uma queda do banco devolvia 500 sem
  cabeçalho nenhum. Não introduzir `onclick=` ou `<script>` inline.
  `Auth::exigirLogin()` relê perfil e `ativo` no banco a cada requisição (com
  cache por requisição): sem isso, desativar ou rebaixar alguém não revogava
  nada até a sessão expirar — e o cookie é de 30 dias, deslizante.
  A expiração de sessão é conferida no `SessaoBanco::read()`, não só no `gc()`:
  o coletor do PHP roda por probabilidade e há ambiente com ela em zero.
  Login tem trava de força bruta (`login_tentativa`, balde por e-mail e por
  origem). Nunca usar `usleep` como defesa: ele roda dentro do trabalhador do
  `php -S` e vira amplificador de DoS.
  Arquivo real de `public/` fora de `/assets/` responde **404**: devolver
  `false` fazia o cli-server incluir o `index.php` de novo na mesma requisição
  e a redeclaração de `versao_asset()` derrubava o pedido com fatal.

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
  quadrantes**, Impacto no eixo horizontal e Esforço no vertical. É a **única
  matriz do sistema** e fica **acima** da fila — a bancada não tem matriz
  nenhuma (refatoração GTD; ver `docs/REFATORACAO-GTD-COLETA.md`). No celular os
  rótulos das linhas vêm inteiros (“pouco esforço”/“muito esforço”) e a coluna do
  eixo some, para a largura ir toda para os quadrantes. Não há filtro de situação
  nessa tela.
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
  `App\Services\QlikSync::NEGOCIOS_FONTE` — a fonte da verdade, que o
  `seeds.sql` e o passo "negócios oficiais" do `migrate.php` espelham); linhas
  manuais nunca são sobrescritas pela sincronização. O **Corporativo** não é
  linha de `negocio`: é opção própria do seletor (`app.js`, valor `CORP`) e
  vira `planejamento.escopo = 'CORPORATIVO'` com `negocio_id` NULL.
  O código **5 (JUROS S. COTA CAPITAL) existe no ERP e fica fora da lista** de
  propósito: é resultado financeiro, não unidade que planeja.
  A identidade do negócio é o **código**, não o nome: `QlikSync` casa por
  `cod_negocio` e só depois por nome. Casar por nome primeiro fazia de toda
  renomeação na fonte uma troca de linha — a antiga era desativada e uma nova
  inserida, o negócio sumia do seletor e o planejamento dele ficava pendurado
  numa linha inativa. Como o `seeds.sql` só age com a tabela vazia e a rota
  `POST /api/negocios/sync` **não tem botão na interface**, quem aplica uma
  revisão da lista a uma instalação em uso é o migrate.
  **Desativar ≠ excluir.** Desativar tira das seleções e preserva tudo; excluir
  (`POST /api/negocios/{id}/excluir`, ✕ que só aparece em linha **inativa**)
  tira do cadastro. O servidor recusa duas vezes, e as duas recusas existem
  para não iludir quem clica: negócio **com planejamento** (a FK é RESTRICT — o
  DELETE morreria, e junto iria o diagnóstico do negócio) e **código da lista
  oficial** (a sincronização recriaria a linha no deploy seguinte). O migrate
  faz a faxina dos inativos que sobraram de carga antiga, com as mesmas guardas
  mais "sem vínculo de usuário" — quem ainda é escopo de alguém sai só pela
  tela, com confirmação.
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
  **Fluxo GTD da condução** (capturar → esclarecer → organizar): a bancada é só
  editor (texto, dividir, desagrupar, tratar depois, excluir); a prioridade é
  decidida **arrastando o cartão da fila até o quadrante**, que já define impacto
  e esforço — sem popup nem tela intermediária. Reclassificar é arrastar entre
  quadrantes. Regras do arraste: o **quadrante tem precedência** sobre a ficha na
  resolução do alvo (soltar sobre uma pílula dentro do quadrante classifica,
  nunca agrupa — agrupar é coisa da fila); soltar no mesmo quadrante **não**
  desfaz; e todo gesto iniciado numa ficha **engole o clique seguinte**
  (`gestoEmFicha`), senão um arraste curto vira toque no quadrante e reposiciona
  sozinho. **O quadrante onde a ideia já está nunca é alvo**: sai sem
  `data-quadrante`, sem `clicavel`, e o arraste também o ignora — tocar no
  quadrante realçado é o gesto de confirmar a posição, e enquanto ele
  desclassificava a ideia sumia da matriz sem ninguém pedir. Tirar da matriz é
  só o "Remover do quadrante" (`priorizar` com `limpar`), que pergunta antes.
  No celular há **auto-scroll** perto das bordas, com o alvo
  recalculado a cada quadro. O painel do QR recolhe depois que a sala entrou, e
  "Tratar depois" (`adiado = 1`) fica anexado à fila, recolhido.
  Na nuvem, ideia sozinha é uma ficha; grupo vira uma **caixa** (`.grupo-caixa`)
  **compacta**: só o título, e a contagem do rodapé é o botão que revela as
  palavras (`caixaAberta`, uma por vez). Tocar na caixa leva o grupo à bancada —
  a tratativa é sempre da caixa inteira, e o texto salvo é o **título** dela. A
  caixa arrasta e é alvo de arraste como a ficha (`touch-action: none` nela e nos
  filhos; `manipulation` nos botões). Cada palavra tem um ✕ que a tira só dela do
  grupo (`removerDoGrupo`, rota `/api/coleta/{id}/remover-grupo`) — juntou por
  engano, tira uma sem desfazer o resto; se sai o líder, o próximo é promovido, e
  quem sai **volta ao próprio texto** (o `texto_tratado` é o título da mãe). O ✕
  para propagação (não seleciona nem arrasta a caixa).
- **Coleta de Ideias** é o passo 0 do diagnóstico: ideia crua → triagem item a
  item → item de cenário, fator **ou plano de ação** (ou descarte com motivo,
  visível ao autor). `coleta_item.destino_tipo` é ENUM
  `CENARIO`/`FATOR`/`ACAO`. Com rodada aberta o destino é escolhido no **menu da
  pílula** na matriz (`MENU_DESTINOS`: Cenário · Framework SWOT/PESTEL/Porter ·
  Resultados Plano de ação); a fila antiga usa `DESTINOS_TRIAGEM`. O registro
  criado herda o `ano` da **ideia**, nunca o do seletor da tela.
  O vínculo vale nos dois sentidos (selo “Coleta · Fulano” no card do
  diagnóstico, “Virou fator ↗” na ideia); apagar o destino limpa
  `destino_tipo`/`destino_id` em vez de deixar link quebrado.
  **A ideia encaminhada não some**: continua na matriz com a etiqueta do destino
  (`rotuloDestino()`), e por isso `listar()` faz `LEFT JOIN fator` para trazer a
  **etapa** — `destino_tipo` só diz `FATOR`, não se virou SWOT, PESTEL ou Porter.
  Consequência que já causou três defeitos: **guardas escritas quando `ACEITO`
  significava “fora de vista” precisam ser revisitadas**. Hoje `priorizar()` e
  `complementar()` aceitam `ACEITO` (o complementar **propaga o texto** para o
  fator/item de cenário, senão os dois divergem), e `grupo()` inclui `ACEITO`;
  `dividir()` e `descartar()` recusam de propósito.
  Saídas da encaminhada, todas explícitas: mover de quadrante (só a posição),
  **desmarcar o destino** (`reabrir` apaga o registro no diagnóstico e a ideia
  fica no quadrante), **remover do quadrante** (volta à fila **com a etiqueta**;
  pergunta se deve sair também da análise) e **excluir**. `reabrir()` e
  `excluir()` valem para o **grupo inteiro** e recusam ideia que já virou ação em
  projeto — desfazer ali deixaria a ação órfã. O `excluir()` apaga junto o fator
  (com os promovidos a partir dele) ou o item de cenário, e solta
  `dividido_de_id`/`agrupado_em_id`, que **não têm chave estrangeira**.
  Ideia cadastrada **manualmente** enquanto uma tempestade está aberta herda o
  `rodada_id` da rodada aberta (validado no back-end) e cai na nuvem, em vez de
  sumir. Listagens que juntam ideias da tempestade (autor_id NULL) usam
  `LEFT JOIN`/`COALESCE`, nunca `INNER JOIN` — senão o card some (ex.:
  `aguardandoAcao()`).
  O encaminhamento usa **reserva atômica** (`Database::afetadas()` num UPDATE
  com a condição no WHERE) em vez de transação — o repositório não usa
  `beginTransaction` e `Json::erro()` encerra a execução.
  **A exclusividade da reserva vem de a SITUAÇÃO mudar** (`NOVO`/`SELECIONADO`
  → `ACEITO`), e mexer nisso já custou caro: aceitar `ACEITO` no `reservar()`
  abriu uma corrida real — `destino_id` só é gravado no FIM do `encaminhar()`,
  então na janela entre a reserva e essa gravação um segundo pedido casava com
  o mesmo WHERE, e um duplo clique do condutor criava DOIS fatores, um deles
  sem vínculo nenhum com a Coleta (nem "Desmarcar" nem excluir a ideia o
  alcançavam). Quem está aceito **sem registro criado** — parado em "Plano de
  ação", ou órfão de um fator excluído — é destravado antes, por `liberar()`,
  que é exclusivo de propósito: exige `destino_tipo` declarado **ou**
  `triado_em` de mais de um minuto, de modo que nunca alcança uma reserva em
  voo. Qualquer mudança aqui precisa de teste de concorrência de verdade.
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
    Na tela (`modalConverterAcao`), a escolha é uma **pergunta explícita** —
    “Onde esta ideia vira ação?”, com *Iniciativa que já existe · Nova
    iniciativa · Projeto novo* — e cada caminho abre só os campos dele. Um
    seletor único misturando os três (como era) escondia as duas decisões e
    fazia um mesmo campo “nome” servir ora ao projeto, ora à iniciativa, ora a
    nada. Só entram os caminhos possíveis (sem projeto cadastrado, sobra
    “Projeto novo”), a iniciativa existente viaja junto com o projeto dela na
    mesma opção (`pid:iid`) — o servidor recusa par de projetos diferentes — e o
    **ano do projeto novo é campo**: herdado calado da ideia, um ano fora dos
    horizontes matava o salvamento sem oferecer correção.
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
- **Duplo clique no cartão abre a edição** daquele nível (duplo toque no
  celular), atalho para o ✎ que mora atrás do “mostrar mais”. O listener vai em
  **cada cartão de projeto**, nunca na seção: `el` sobrevive aos
  recarregamentos e um listener nele empilharia uma cópia por `carregar()`. A
  resolução é do **mais interno para o mais externo** (ação → iniciativa →
  projeto), porque os três níveis são aninhados no DOM. Botão, link, a barra de
  progresso e o diário dentro do cartão não viram atalho; a seleção de texto do
  duplo clique é limpa antes de abrir o modal. Os cartões levam
  `touch-action: manipulation`, senão o iOS trata o segundo toque como zoom e o
  `dblclick` não chega.
- **Campos da ação numa lista só** (`camposAcao` + `valoresNovaAcao` +
  `transformarAcao`): os dois formulários que escrevem uma ação — o cadastro e o
  direcionamento de uma ideia da coleta — usam a MESMA lista. Escritos
  separados, divergiram: o direcionamento pedia só o quê/quem/prioridade e
  criava a ação sem como, prazo, repetição, custo nem status, obrigando a
  reabri-la no cadastro para completá-la.
- **Três níveis de recolhimento** (`nivelAtual` / `aplicarNivel` /
  `pintarNiveis`): **Ações · Frentes · Projetos**, no lugar do "Recolher tudo"
  que só tinha os extremos. "Frentes" é o nível que faltava — esconde as ações e
  mantém projetos e frentes com os seus percentuais. Recolher a iniciativa já
  era o que escondia as ações dela; o grupo só dá um toque para chegar lá. Com o
  usuário abrindo/fechando itens à mão, `nivelAtual` devolve vazio e **nenhum
  botão fica aceso** — melhor que um botão mentindo. Os acordeões chamam
  `pintarNiveis`: eles mexem no DOM sem recarregar a seção, e sem isso o grupo
  seguiria marcando "Ações" com as ações já escondidas.
- **Panorama de execução** (`panorama()`): barra da média + percentual +
  “N atrasada(s)”. É o **mesmo bloco** no projeto e na iniciativa — escritos
  separados, os dois níveis divergiriam na primeira mudança de regra.
  `atualizarMedias()` recalcula os **dois** níveis ao arrastar a barra de uma
  ação, lendo os valores que estão na tela (a ação recém-arrastada precisa
  entrar na conta).

## Migrações e seeds

- `database/migrate.php` é **idempotente** e roda a cada deploy
  (`entrypoint.sh` aborta o start se falhar). Estatements no `schema.sql`
  separados por `;` em fim de linha; comentários só com `--` no início.
- `ALTER TABLE` novo: usar `garantirColuna()` (checa information_schema).
- Seeds (`database/seeds.sql`) só inserem quando a tabela/contexto está vazio
  (`WHERE NOT EXISTS (SELECT 1 FROM tabela)`) — renomear algo pela UI não pode
  recriar linhas. Consequência: revisão de lista oficial (os negócios) não chega
  a quem já tem cadastro pelo seeds; vai num passo próprio do migrate, que lê a
  lista de `QlikSync::NEGOCIOS_FONTE` por reflexão para não virar terceira cópia
  dos códigos.
- **Carga de conteúdo** (texto que o usuário edita depois: o cenário
  macroeconômico, os fatores de PESTEL, Porter e SWOT, e a cascata de um
  horizonte): passo do
  migrate marcado em `carga_conteudo` pela `chave` do arquivo de conteúdo. A
  marca é o que impede o deploy seguinte de recriar o item que alguém apagou e
  de repor a redação que alguém reescreveu — guarda que o `NOT EXISTS` dos
  seeds não dá, porque aqui o contexto nunca está vazio. Revisar os textos
  exige **chave nova**. Cada carga mora num arquivo só
  (`database/conteudo_*.php`, com `destino` CENARIO ou FATOR), e a regra de
  aplicar é de `App\Services\CargaConteudo` — usada pelo migrate **e** pela CLI
  (`cli/carga_diagnostico.php <cenario|pestel> <plano> [ano] [--aplicar]`, que
  alcança um negócio ou ano fora do corporativo). Carga nova é uma entrada na
  lista do migrate e outra em `CARGAS` da CLI; escrever a lógica de novo faria
  as cópias divergirem na primeira revisão. Só o planejamento **CORPORATIVO**
  recebe do migrate: análise macro replicada nos doze negócios enterraria a
  análise própria de cada um. A carga de SWOT entra **solta**, sem
  `promovido_de_id`: promover é o gesto de quem conduz a análise (escolher qual
  fator do PESTEL/Porter merece o quadrante), e promover pela carga decidiria
  isso pelo usuário — o botão “→ SWOT” some depois que o fator foi promovido.
  A carga de **cascata** (`destino: CASCATA`) tem guarda diferente: o que
  protege não é o texto e sim a **célula** (plano × horizonte × driver × eixo),
  porque cada uma guarda uma decisão tomada. Ela casa driver, eixo e horizonte
  pelo **nome do cadastro**, e os três são editáveis na tela: nome que não
  resolve faz a carga lançar `RuntimeException`, o migrate **adia** (não marca)
  e o deploy segue — quando o nome voltar, ela entra sozinha. A validação roda
  inteira **antes** do primeiro INSERT, senão um nome errado no meio da lista
  deixaria metade da cascata gravada.
- Compatibilidade MySQL 8 **e** MariaDB (por isso `ON DUPLICATE KEY UPDATE
  VALUES()` e nada de sintaxe exclusiva do MySQL 8). Toda tabela declara
  `COLLATE=utf8mb4_unicode_ci`: sem isso cada motor escolhe a sua (MariaDB
  `general_ci`, MySQL 8 `0900_ai_ci`) e homologação discorda de produção na
  ordenação e na comparação de acentos.
- Índice novo em tabela que já existe: `garantirIndice()`; chave estrangeira
  nova: `garantirFk()`. `CREATE TABLE IF NOT EXISTS` não alcança tabela criada
  antes, então índice declarado só no `schema.sql` nunca chega em produção.
- FK cujo alvo é criado **depois** no `schema.sql` (ex.: `coleta_item` →
  `coleta_rodada`) fica no migrate, nunca no `CREATE TABLE`: ali ela quebra a
  instalação nova.
- O migrate serializa por `GET_LOCK` (duas réplicas subindo juntas passariam as
  duas no *check-then-act* de `garantirColuna`) e aborta na hora em erro
  permanente (1045/1049), em vez de insistir 30 vezes dizendo "aguardando
  banco".
- Faxina determinística das tabelas que só crescem (`sessao`,
  `coleta_tentativa`, `login_tentativa`) no migrate **e** em
  `cli/notificar.php` — nunca confiar no `gc` do PHP.

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
  aparecer, **e sobrescreva também `window.SpeechRecognition`**: o headless
  define o nativo (que não fala) e o código prefere ele. Para preparar massa de
  teste, chame a própria API pelo `page.evaluate` (`App.api(...)`) e apague o que
  criou ao final.
  Três armadilhas do ambiente, todas já custaram depuração:
  o Chromium novo **removeu o headless antigo**, então `chromium.launch()` só
  sobe apontando para `/opt/pw-browsers/chromium_headless_shell-*/chrome-linux/
  headless_shell`; a **CSP bloqueia `page.waitForFunction`** (avalia string como
  JS) — use laço com `page.evaluate`; e o modal do Bootstrap deixa a classe
  `.show` pendurada sem o `transitionend`, então teste fechamento com
  `reducedMotion: 'reduce'` no contexto, senão o "modal fechou" dá falso-negativo.
  Para gestos de arraste, ande em passos (`mouse.move` várias vezes): abaixo de
  8px o código trata como toque, não arraste.
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
  (segurança, corretude, infra, frontend) e aplicar os achados confirmados;
  manter a responsividade mobile; validar com Playwright antes de commitar.
- Acessibilidade que já custou defeito: as seções **não são destruídas** ao
  navegar (só ganham `d-none`), então id repetido entre telas coexiste no
  documento e o `for` do label casa sempre com o primeiro — ids de tela levam
  sufixo (`sel-ano-swot`) ou viram atributo (`data-novo-fator`). Botão de cor
  própria precisa de `--bs-btn-focus-shadow-rgb`, senão fica sem indicador de
  foco. Alvo de toque no celular cresce por **dimensão real**, nunca por
  `::after` sobreposto — áreas invisíveis de botões vizinhos se cobrem e o
  toque na fronteira vai para o errado.
- Roadmap e especificações: `docs/PLANEJAMENTO-SISTEMA.md` (fases 1–6 já
  entregues) e `docs/BACKLOG-EVOLUCAO.md` (matriz de impacto por negócio,
  triagem pós-brainstorm, mapa BSC, plano de contingência e ritual de
  acompanhamento — com o veredito de o que vale ou não construir).
- `docs/REFATORACAO-GTD-COLETA.md`: o fluxo GTD da Coleta como ficou (matriz
  única, arraste, menu da pílula, saídas da ideia encaminhada), as decisões do
  cliente e os defeitos que a validação pegou. Leia antes de mexer na condução da
  tempestade.

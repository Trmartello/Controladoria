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
  ações — usada pelo cadastro), `Avisos` (e-mails do plano
  de ação), `Consolidacao` (reconciliação do que é *consequência*: atraso da
  ação e período/status do projeto). `Consolidacao::reconciliar($planId)` roda
  no começo de **toda leitura** que exibe esses campos — Projetos, Painel e
  Relatório. Deixá-la só em Projetos fazia o painel da direção contar zero
  atraso até alguém abrir a seção, e os números mudavam sozinhos depois.
  `Fatores::exigirSemAcao` é a guarda **compartilhada** por `FatorController` e
  `ColetaController`: excluir um fator (ou a ideia que virou fator) apaga junto
  o promovido à SWOT, e é ele que pode carregar o `desdobramento_id` — a guarda
  confere `f.id IN (…) OR f.promovido_de_id IN (…)`, senão a ação ficava no
  plano sem origem nenhuma. Autoload PSR-4 caseiro em `public/index.php`
  (`App\` → `app/`);
  **não há Composer nem `vendor/`** — nada de dependência externa em PHP.
- **Contexto: ciclo × negócio.** O **negócio** é seletor do menu lateral — troca
  o dia inteiro. O **ciclo** não: ele é escolhido em **Cadastros › Ciclos &
  Horizontes** (`#sel-ciclo-uso`), e o menu apenas MOSTRA qual está em uso
  (`#ciclo-atual`, com atalho para a aba). Lado a lado no menu, os dois pediam o
  mesmo gesto para decisões de escalas muito diferentes — a do ciclo se toma uma
  vez por ano. Quem guarda a escolha continua sendo o núcleo
  (`App.trocarCiclo`), não a seção: o ciclo alimenta `contextoParams()` de todas
  as telas, e estado desses em seção que se repinta some no primeiro redesenho.
  O rótulo tem uma fonte só (`App.rotuloCiclo`), usada pelo menu e pela topbar.
  O cabeçalho do menu é **espaço tirado da navegação** — ele empurra as
  dezesseis seções para baixo. Por isso rótulo, valor e o ⚙ dividem UMA linha,
  o subtítulo "Planejamento Estratégico" só aparece onde a topbar o esconde
  (`d-sm-none`, abaixo de 576px) e no menu vai só o NOME do ciclo, com o
  ano-base no `title`: numa linha só, "2027–2035 (base 2026)" era cortado
  justamente no ano-base, e meia informação engana mais que informação nenhuma.
  Medido: 238px → **144px** antes do primeiro item (desktop), 173px no celular.
  A engrenagem é a MESMA da topbar, por `<use href="#i-engrenagem">` — o
  símbolo é desenhado uma vez no `shell.php`, senão as duas cópias divergiriam
  na primeira revisão do ícone e o leitor lê "mesmo símbolo" como "mesmo
  destino".
- **Atalho ⚙ na topbar** (`#btn-cadastros`, ao lado do ☰): abre os Cadastros —
  a tela de AJUSTAR o sistema, que não faz parte do percurso do planejamento e
  se procurava no meio de dezesseis seções. É um `<a data-secao>`, o mesmo
  contrato dos itens do menu, e por isso o ouvinte de navegação casa por
  `[data-secao]` sem prefixo de container (ele não alcança o
  `data-secao-pergunta` do quiz: seletor de atributo casa por nome exato). O
  estado "esta é a tela dele" é `aria-current`, não `.active` — ele não é item
  de lista, e dois atributos para o mesmo estado divergiriam. Botão novo na
  topbar é espaço tirado de alguém: com ele, "Planejamento Estratégico" passou a
  quebrar em duas linhas numa barra de 52px no celular, e o subtítulo virou
  `d-none d-sm-inline`. A bateria confere isso contando **linhas de texto** com
  um `Range` — a altura da topbar é fixa (continua 52px com o texto quebrado) e
  `elemento.getClientRects()` devolve um retângulo só, porque item de flex é
  blocificado; as duas medidas óbvias passavam com o defeito de pé.
- **Frontend**: JS vanilla, sem build. Seções em `public/assets/js/secoes/*.js`
  registradas em `App.recarregarSecaoAtiva()` (`app.js`).
  Seção com **abas que buscam dados** serializa as pinturas
  (`SecaoCadastros.carregar` enfileira `pintar`): cada renderizador escreve no
  container e liga os botões DEPOIS do `await`, então trocar de aba com a
  anterior ainda carregando fazia a antiga escrever no conteúdo que já era de
  outra e procurar um botão inexistente — `null.addEventListener`, e a tela
  inteira virava alerta vermelho. Formulários via
  fábrica declarativa `Modal.abrir({campos, url, valores, transformar, extra,
  aoSalvar, enviar})` (`modal.js`) — `enviar` substitui o POST padrão quando
  salvar exige mais de uma chamada (o 409 de sala aberta virando confirmação).
  Componentes usados por VÁRIAS seções ficam soltos em `public/assets/js/`
  (`quiz.js`) e carregam antes das seções no `shell.php`.
  `App.api` põe `codigo` e `status` no `Error` que lança: erro que a tela
  precisa DECIDIR (e não só mostrar) vem por código, nunca por texto.
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
  `sufixo`, `passo`, `unico` (no `lista_marcavel`: escolhe UM item, os quadrados
  viram redondos e o campo devolve o valor em vez da lista) e `linha` (campos
  **consecutivos** com a mesma `linha` dividem uma fileira — `.grade-campos`,
  montada por `Modal.renderCampos`). Campo curto ocupando a largura inteira
  custa uma faixa de tela cada, e o Salvar mora no rodapé fixo: o que sobra de
  altura é rolagem. Duas cautelas: o agrupamento é **por vizinhança** (juntar
  campos distantes reordenaria o formulário por baixo do pano, e a ordem é
  decisão de quem escreveu a lista) e a grade é `auto-fit`, nunca coluna fixa —
  na fileira da repetição há campo que some com o `visivelSe`, e coluna fixa
  deixaria buraco no lugar do escondido. **`obrigatorio` desenha o asterisco,
  não recusa o envio**: quem valida é o servidor, e marcar um campo na tela sem
  a guarda lá deixa o formulário mentindo.
  `Modal.abrir` aceita ainda `aoMudar(dados, raiz)`, chamado ao abrir e a cada
  mudança de campo, para formulário cujo TEXTO depende do que já foi escolhido —
  o bloco do cruzamento da SWOT, que depende de DOIS campos e por isso não cabe
  no `visivelSe`. O ouvinte fica no formulário (o `change` sobe por
  borbulhamento) e o `selecao_livre` dispara o evento à mão, porque o valor dele
  mora num `input[type=hidden]`, que não emite nada quando escrito por código. O gatilho do `visivelSe` pode ser de qualquer tipo, mas o
  valor precisa sair de `Modal.valorAtual()`: em `botoes` e `quadrantes` o id
  fica na **div** que agrupa os rádios, e ler `.value` dela devolvia `undefined`
  — o campo dependente ficava escondido para sempre.
  Datas aparecem sempre como dd/mm/aaaa (`ligarDatasBr`).
  Textareas crescem com o texto até 60% da altura da tela e depois rolam por
  dentro (`crescerTextarea`). Medida que depende de layout (o que transborda,
  quanto o texto ocupa) vai em `Modal.aoAparecer`, disparada no
  `shown.bs.modal` — com o modal escondido toda altura vale zero.
  **Campo abaixo da dobra é anunciado** (`Modal.ligarAvisoRolagem`, o botão
  `#modal-mais` em `shell.php`): o corpo do modal sempre rolou, mas nada dizia
  isso, e o Salvar mora no rodapé FIXO, sempre visível. Numa janela de
  notebook, o formulário de quatro perguntas da matriz GUT mostrava três e o
  botão — quem respondia o que via e salvava deixava o esforço "não estimado"
  sem nunca ter escolhido isso. O aviso é `position: sticky; bottom: 0` (e não
  `absolute`: a altura do rodapé muda com o botão extra, e um deslocamento fixo
  erraria o alvo em metade dos formulários) e some quando a rolagem acaba. O
  ouvinte é ligado UMA vez — o corpo é o mesmo elemento em todos os
  formulários, e religá-lo a cada abertura empilharia uma cópia por modal.
  Pelo mesmo motivo, a recusa do servidor passa por `Modal.mostrarErro`, que dá
  `scrollIntoView` no aviso (sem mexer no foco): com o corpo rolado até o fim —
  o estado de quem acabou de preencher e clicou em Salvar — a mensagem nascia
  fora da vista e o formulário parecia ter ignorado o clique.
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
- **Impressão das análises** (o "PDF" do ⤓ Relatório): o **canvas é uma tabela de
  verdade** (`<table class="canvas-analise">`, cabeçalho no `<thead>`) —
  neutralizada na tela (todas as partes viram bloco, o layout é o de sempre) e
  ativa no papel, onde o `<thead>` vira o cabeçalho **repetido em toda página**.
  Foi medido no Chromium: `display: table-header-group` num `<div>` sai **só na
  primeira** folha, e `position: fixed` deslocado para a margem some **na
  última** — o `<thead>` real é o único que repete em todas. Na tela quem gruda
  é o **`<thead>`**, não o bloco de dentro: `sticky` só se move dentro do bloco
  container, e o container virou a célula, que tem a altura exata do cabeçalho.
  No papel o corpo é **uma coluna**: título da categoria, traço e os cartões
  dela em largura cheia, um bloco depois do outro — em coluna estreita o mesmo
  texto gasta três vezes mais linhas. E `break-inside: avoid` fica **só no
  cartão**: na coluna inteira, ela era empurrada para a folha seguinte e a
  primeira saía em branco.
- **Relatório da análise** (PESTEL, Porter, SWOT, Cenário): botão **⤓ Relatório**
  no cabeçalho, com dois caminhos — **Word**, um `.doc` de HTML baixado por
  `Blob` (o mesmo caminho do `.xls` do Relatório de Status: sem Composer não há
  `.docx` de verdade), e **PDF**, que é `window.print()` — quem gera o arquivo é
  o navegador, em "Salvar como PDF". O documento é montado **no front**
  (`public/assets/js/relatorio-analise.js`, `RelatorioAnalise`) porque é lá que
  moram os rótulos e as cores das categorias; no servidor seria uma segunda
  cópia do catálogo, divergindo na primeira revisão. A folha `@media print`
  desliga menu, topbar, botões, `position: sticky` e **o corte de três linhas
  dos cartões** — conforto de tela que no papel viraria relatório truncado.
  `montar()` só roda no clique: montado a cada pintura, o relatório seria
  refeito a cada batida do polling.
- **Cabeçalho fixo das análises** (PESTEL, Porter, SWOT, Cenário): título, ano,
  "+ Novo" e o selo da sala moram num bloco só (`.cabecalho-analise`,
  `data-cabecalho-analise`) que fica **fixo abaixo da topbar** — o condutor
  percorre os cartões sem perder de vista que análise, que ano e onde a sala
  está. O degrau é feito de duas variáveis: `--topo-app` (a altura da `.topbar`,
  em `:root`) e `--altura-cabecalho`, **medida no JS** por
  `Diag.ligarCabecalhoFixo` a cada pintura, com um `ResizeObserver` — o bloco
  quebra em duas ou três linhas conforme a largura, e um palpite em `rem` curto
  demais deixaria o cabeçalho da coluna por cima do da análise. As margens
  negativas do bloco cobrem a sarjeta do `.row`, senão o cartão aparecia pelas
  beiradas ao passar por baixo. O selo da sala fica **na mesma linha do
  título**, logo depois dele: numa linha própria custava uma faixa inteira do
  cabeçalho fixo, que é altura roubada de todas as telas o tempo todo. A largura
  das colunas vem do **`row-cols-*` da fila**, calculado com o número de
  categorias da análise — com um `col-*` fixo o Porter (cinco forças num
  `col-xl-2`) deixava um sexto da tela vazio e espremia os cartões à toa.
  O painel de vozes fica **fora** do fixo: ele
  cresce com a oficina e engoliria a tela.
- **Colunas das análises** (as mesmas quatro): o cabeçalho de cada
  categoria é `position: sticky` logo abaixo do cabeçalho da análise
  (`.cabecalho-coluna`) e, no computador, o corpo rola **por dentro** da caixa
  (`.corpo-coluna` dentro de `.caixa-coluna`, teto de `100dvh - 5rem`). Rolando
  a página, o título saía de vista e a pilha de cartões virava coluna anônima —
  ninguém sabia mais o que era de quê. Quatro detalhes que não podem ser
  desfeitos: o flex e o teto vão na **caixa**, nunca na coluna do grid (o filtro
  de categoria do celular pendura `d-md-block` nela, e o `display: block
  !important` desmontaria a rolagem); o **fundo é declarado no cabeçalho
  também**, porque sob ele passam cartões e transparente eles apareceriam atrás
  do título (a tinta do quadrante da SWOT chega por `--tinta-coluna`, empilhada
  sobre branco — translúcida sozinha, não esconderia nada); **nada de
  `overscroll-behavior: contain`** no corpo, senão o cursor parado sobre a
  coluna mais longa trava a rolagem da página; e no **celular não há rolagem
  interna** — só o cabeçalho grudado, que ali já resolve, e rolagem aninhada no
  dedo é o que não se quer.
- **Matriz de prioridade** (condução da tempestade): gráfico de **quatro
  quadrantes**, Impacto no eixo horizontal e Esforço no vertical. É a **única
  matriz do sistema** e fica **acima** da fila — a bancada não tem matriz
  nenhuma (refatoração GTD; ver `docs/REFATORACAO-GTD-COLETA.md`). No celular os
  rótulos das linhas vêm inteiros (“pouco esforço”/“muito esforço”) e a coluna do
  eixo some, para a largura ir toda para os quadrantes. Não há filtro de situação
  nessa tela.
- **Matriz GUT** (`Gravidade × Urgência × Tendência`, 1–125): cada dimensão tem
  **pergunta-guia** no modal de avaliação; a prioridade é **Pequeno** (<27,
  verde), **Médio** (27–63, dourado) e **Grande** (≥64, vermelho — tratar
  agora), com a legenda distribuída na barra da matriz (`.gut-legenda-barra`).
  O botão **Redefinir** (`extra.manterAberto`) zera os valores **sem fechar** o
  modal, para continuar editando.
  Cada ameaça também recebe um **esforço** (`gut.esforco`, ENUM
  PEQUENO/MEDIO/GRANDE, nulo = não estimado). Ele fica **fora** do produto
  G×U×T de propósito: o score mede o tamanho do problema e o esforço o da
  resposta — multiplicá-los faria uma ameaça gravíssima e cara despencar na
  fila só por ser cara. Ele entra apenas como **desempate** na ordenação
  (`pesoEsforco`, sem estimativa vai para o fim), e a estimativa é **opcional**:
  chutar "médio" ao reabrir o modal gravaria uma medida que ninguém fez.
  Na tela ele é **uma letra** (P/M/G), nunca a palavra — e as faixas do score
  aparecem como letra também, na barra da legenda: escrever "Pequeno/Médio/
  Grande" ali deixava a mesma linha dizendo "grande" para *prioridade alta* e
  para *caro de resolver*, que são coisas opostas. Quem separa as duas leituras
  na barra é o **número entre parênteses** (`< 27`, `27–63`, `≥ 64`), que fica.
  **A cor é uma paleta só** (`SecaoGut.FAIXAS` → `corScore` e `corLetra`): P
  verde, M dourado, G vermelho, no score e no esforço. Foi decisão do cliente,
  pedida duas vezes — o padrão de cor vale mais que a ambiguidade —, e o que a
  cor junta é a **forma** que separa: o selo do esforço é **redondo**
  (`.esforco-selo`, `border-radius: 50%`) e o do score é retangular
  (`.gut-score`). Duas listas de cor divergiriam na primeira revisão de paleta,
  e a legenda passaria a explicar uma cor que a tabela não usa. **Sem estimativa
  não se pinta nada**: fica o traço (`.esforco-selo.vazio`), porque cor ali
  afirmaria uma medida que ninguém fez.
  O que cada letra significa mora no **ⓘ** da legenda (chave `PMG`,
  `Diag.ligarOrientacoes`, o mesmo padrão das análises) e no `title` de cada
  selo — não num parágrafo acima da tabela, que custava uma faixa inteira da
  tela. O ⓘ explica **as duas leituras**, uma embaixo da outra, e é o único
  lugar que diz que o esforço mede a resposta e não o problema. A antiga
  etiqueta `Esforço P · M · G` saiu da barra por ser a terceira repetição da
  mesma informação.
  **Ponto aberto medido, não resolvido**: sobre branco, o verde `#007a45` dá
  5,43:1 e o vermelho `#8f3b3b` 7,35:1, mas o dourado `#b08d4f` dá **3,10:1** —
  abaixo do 4,5:1 da WCAG AA para texto pequeno. Hoje a letra e a faixa
  numérica carregam a informação sem depender da cor, então nada fica
  ilegível; numa revisão de paleta o dourado deve subir (`#8a6a2a` dá 5,03:1 e
  mantém o tom).
  O cabeçalho da seção (`.cabecalho-gut`) e o `<thead>` da tabela **grudam**
  abaixo da topbar, em degraus: `--topo-app` e `--altura-cabecalho`, medida por
  `Diag.ligarCabecalhoFixo` (o mesmo helper das análises). Rolando o ranking,
  G, U, T, Score e Esforço saíam de vista e viravam cinco números anônimos.
  A tabela **não** usa `.table-responsive`: `overflow-x: auto` faria da própria
  caixa o scrollport do `sticky`, e o cabeçalho grudaria numa caixa que nunca
  rola na vertical — ou seja, nunca. Ela só aparece a partir de `md`, onde
  cabe; no celular são cartões, cada um com os próprios rótulos, e ali nada
  gruda (o título quebra em duas linhas e custaria um quinto da tela).
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
- **Cruzamentos da SWOT (TOWS)** (`swot_cruzamento`, `CruzamentoController`,
  `secoes/cruzamentos.js`): o par de um fator INTERNO com um EXTERNO e a
  estratégia que nasce dele — a quinta análise, entre a GUT e a Cascata. O
  **bloco não é escolhido, é consequência do par** (força+oportunidade só pode
  ser ATACAR) e por isso o `tipo` é calculado no SERVIDOR e nunca lido do corpo;
  aceitá-lo do cliente gravaria a linha no quadro errado, que é o mesmo defeito
  que a etapa/ano do fator já custou. O par é **único por ano** (sem a chave, o
  mesmo cruzamento entraria duas vezes com redações diferentes e o bloco viraria
  discussão em vez de decisão) e o **`ano` sai dos FATORES**, não do corpo — par
  de anos diferentes não é leitura de ano nenhum. Na edição o **par sai da
  LINHA**: ele é a identidade do registro; para outro par, outro cruzamento.
  As FKs dos dois fatores são `ON DELETE CASCADE` — não existe cruzamento de um
  lado só —, e quem avisa é a tela: `FatorController::listar` devolve
  `cruzamentos` (a contagem dos DOIS lados) e a confirmação da SWOT diz o que vai
  junto. **Não existe grade 4×4 clicável**, e é decisão: com seis fatores por
  quadrante seriam 36 células por bloco, e na prática se escolhem três — a grade
  convidaria a preencher tudo.
  **O cartão tem UM "ver mais" para o cartão inteiro**
  (`SecaoCruzamentos.ligarVerMaisCartao`), não um por texto como o
  `Diag.ligarVerMais` do resto do diagnóstico: são três caixas cortadas (os dois
  selos do par e a estratégia) e um rodapé só — com o genérico seriam três
  botões empilhados no mesmo lugar, nenhum dizendo a qual texto pertence. Os
  textos saem marcados com `data-ver-mais="1"` para o helper genérico não
  encostar neles. O rodapé é uma linha: **expandir à esquerda** (é leitura) e
  **agir à direita** (selo do plano, ✎, ×). O botão só existe quando alguma
  caixa foi mesmo cortada, medido com o cartão **recolhido** — aberto, nada está
  cortado e ele sumiria de quem acabou de usá-lo —, e o filtro de categoria do
  celular obriga a religá-lo, porque caixa escondida por `d-none` mede zero. O
  estado mora em `expandidos` (um `Set` na seção), nunca no DOM: a tela se
  repinta com o relógio da sala e o texto voltaria a ser cortado no meio da
  leitura. A altura é animada com o destino **medido** (`animarAltura`), com o
  `max-height` limpo no fim — mantê-lo prenderia o cartão numa altura fixa e
  esconderia o que crescesse depois — e respeitando `prefers-reduced-motion`.
  O selo do par é cortado em UMA linha por
  `line-clamp`, **nunca por `nowrap`**: com `nowrap` a largura mínima do selo
  vira o parágrafo inteiro, e essa mínima sobe pela coluna e pela fila até o
  `<main>` (item de flex) — a página inteira passava a rolar na horizontal no
  computador. O clamp mora num `<span>` de dentro porque o Chrome não aplica
  `-webkit-box` a um `<button>`, e o `white-space: normal` precisa ser declarado
  para desfazer o `.badge` do Bootstrap. Plano, decisões e o que falta:
  `docs/CRUZAMENTOS-SWOT.md`.
  O cruzamento **vira ação** pelo mesmo caminho do fator da SWOT (`acao_em`,
  `acao_por`, `desdobramento_id`; `POST /api/cruzamentos/{id}/plano-acao`), e a
  guarda contra ação órfã precisou de um caminho novo: como as FKs do par são
  CASCATA, apagar um fator leva junto o cruzamento que o cita — e se ele já
  virou ação, ela ficaria no plano sem origem. Por isso `Fatores::exigirSemAcao`
  olha também os cruzamentos dos fatores pedidos **e dos promovidos a partir
  deles**.
- **Quiz — a sala do PROJETO** (`coleta_rodada.modo = 'QUIZ'`): a MESMA sala da
  tempestade — PIN, token, tetos, trava de força bruta — servindo a TODAS as
  análises. **Um PIN para o encontro inteiro**: o participante escaneia uma vez
  e o celular acompanha a tela que o condutor abre. Uma pergunta ativa por vez
  (`quiz_pergunta.situacao` é a única fonte da verdade; não existe coluna
  "pergunta ativa" na rodada).
  A pergunta tem **alvo polimórfico** (`quiz_pergunta.alvo_tipo`):
  `CASCATA` (célula driver×horizonte×eixo), `CENARIO` (ano), `FATOR` (ano +
  etapa + categoria) e `LIVRE` (a tempestade dentro do roteiro). Colunas nulas
  por tipo — e por isso a unicidade é a coluna gerada `alvo_chave`, que junta
  todas elas: NULL nunca colide com NULL num UNIQUE comum, e a mesma célula
  entraria duas vezes no roteiro.
  O que cada alvo SIGNIFICA (o lado da resposta, o limite de texto, o rótulo, o
  contexto que o celular lê) mora em **`App\Services\Quiz`** — cinco telas
  reescrevendo isso divergiriam na primeira análise nova.
  Regras que não podem ser afrouxadas, além das da tempestade: o teto de envios
  conta por **(pergunta, tipo)** dentro do INSERT, com `<=>` e não `=` (alvo sem
  lado grava `tipo_resposta` NULL, e o `=` devolveria NULL — o teto virava
  decoração justamente nas telas sem lado); o corpo declara o `pergunta_id` que
  o participante VIA e o servidor recusa com 409 se divergir da ativa (nunca
  grava "na ativa" às cegas); o lado é lista branca **derivada do alvo**, nunca
  um ENUM fixo; o limite de texto vem do alvo, **no servidor**.
  **A fase da ESTRELA** abre sozinha quando o condutor fecha o 🎤: sem pergunta
  ativa, o celular passa a votar nas respostas da **última pergunta fechada**
  (`Quiz::encerradaRecente`, ordenada por `aberta_em` como a `ativa()` — reabrir
  atualiza essa data). Com pergunta ativa não há estrela: responder é o
  trabalho, e a estrela dividiria a atenção de quem escreve. O teto é **por
  PERGUNTA** (`coleta_rodada.max_votos`, escolhido ao abrir a sessão, padrão 3)
  — o da tempestade conta por rodada e, num encontro de dez perguntas, acabaria
  na segunda. As rotas são `GET /api/publico/estrelas` e
  `POST /api/publico/estrela/{id}`, com as mesmas guardas das outras públicas
  (token registrado, `Content-Type` JSON, isenção de CSRF na lista **explícita**)
  e o teto **dentro do INSERT**. Quem decide qual pergunta está em votação é
  `perguntaComEstrela()`, uma fonte só para a leitura e a escrita: separadas, o
  celular mostraria uma pergunta e o toque gravaria em outra. O condutor vê a
  fase pelo selo **★ a sala está pontuando**, que vem de `estrelas_em` no
  `estado()` — sem ele, fechar o 🎤 pareceria "sala parada" justamente no
  momento de ler a sala.
  Com a pergunta tendo LADOS (a cascata, o cenário), o celular mostra as
  respostas em **blocos por lado** — todas as escolhas juntas, todas as
  renúncias juntas, com o selo virando título do bloco. Misturadas, obrigavam a
  ler o selo de cada ficha para saber de que lado ela era.
  O isolamento entre os ritos é **`coleta_item.origem`** (`TEMPESTADE`/`QUIZ`),
  nunca `pergunta_id` (FK SET NULL) e **nunca mais `tipo_resposta IS NULL`**:
  alvo sem lado responde com `tipo_resposta` nulo e vazaria para a fila da
  Coleta. `origem` filtra o `listar()` e o `exigirItem()` da Coleta, o
  `liderEquivalente()` do agrupamento automático e o vínculo de sugestões. As
  rotas públicas guardam o modo nos DOIS sentidos — `resposta()` recusa
  TEMPESTADE, `ideia()`/`votar()`/`paraVotar()` recusam QUIZ (sem o espelho,
  participante do quiz plantava ideia de 400 chars direto na fila de triagem).
  O `estado()` do quiz **omite o PIN para perfil LEITURA**, como
  `RodadaController::listar` — o PIN é credencial de escrita.
  **A sala TROCA DE RITO sem trocar de PIN**: com a tempestade aberta, o 🎤 de
  uma análise não a encerra — `Quiz::assumirTempestade` vira o `modo` da MESMA
  rodada para QUIZ (409/`ASSUMIR_TEMPESTADE`, código próprio porque essa tela
  não pede nome de encontro: a sala já tem um). Encerrar e abrir outra rodada
  criava um PIN novo e deixava todo mundo preso em "Esta rodada foi encerrada",
  sem aviso e sem caminho — o celular está amarrado ao PIN que escaneou. É
  seguro porque quem separa os dois ritos é `coleta_item.origem`, não a rodada:
  as ideias já enviadas continuam TEMPESTADE e seguem na Coleta, e os
  participantes continuam registrados com o mesmo token.
  **Uma sala aberta por planejamento**, e a colisão é PERGUNTA, não recusa:
  `Quiz::liberarSala` devolve 409 com o código **`SALA_ABERTA`** e o nome da
  tela em que ela ficou; com `confirmar_encerrar` no corpo, encerra a anterior e
  abre a nova **num pedido só** (dois deixariam uma janela sem sala nenhuma, e o
  segundo pode falhar depois de o primeiro ter encerrado). Sem a confirmação a
  recusa continua — encerrar calado derrubaria a discussão de outra pessoa por
  um clique distraído. `Json::erro` ganhou o terceiro parâmetro `codigo` para
  isso: mensagem é para ler, código é para decidir (casar por texto seria refém
  da redação).
  **Unificar respostas** é o gesto de CONSOLIDAR, e só com a pergunta **já
  fechada** (`POST /api/quiz/sugestao/{id}/unir`; `separar` desfaz): o condutor
  arrasta uma ficha sobre a outra e as duas viram um cartão só — o texto de
  cada uma vira uma LINHA dele, com o ↩ que a devolve ao lugar. Nada é apagado:
  o vínculo é o mesmo `coleta_item.agrupado_em_id` da tempestade, e cada linha
  guarda o próprio texto, autor, data e votos. O que a unificação acrescenta é
  a rastreabilidade — `unido_por`, `unido_em` e, principalmente, `unido_de_id`,
  o líder de ONDE a ficha veio, que é o que faz o desfazer devolver o grupo
  inteiro ao lugar de antes. Com a pergunta ATIVA o servidor recusa
  (409/`SALA_ABERTA`) e a tela nem desenha o arraste: unir enquanto a sala
  responde mexeria na lista embaixo de quem ainda está escrevendo. O contador
  do painel conta **cartões**, não linhas, e o "Usar" leva o texto de todas e
  amarra **todas** (`QuizSala.grupoDe`) — amarrar só o líder deixaria as
  absorvidas no painel, prometendo trabalho já feito.
  Sem pergunta ATIVA o `estado()` põe o foco na **última encerrada**: fechar o
  🎤 apagava o painel inteiro, e é exatamente aí que começa o trabalho de ler as
  estrelas, unir o parecido e transformar em registro.
  Vincular sugestão ao registro é **conjunto** (campo `sugestoes`, como
  `fatores`): muitas vozes com `destino_tipo` + situacao ACEITO (congela a
  edição do autor), UM texto redigido pelo condutor — **aceitar é ato de quem
  conduz**, em todos os alvos. A guarda do vínculo é o ALVO da pergunta (JOIN
  por `quiz_pergunta`), nunca a rodada: encontros diferentes podem ter
  perguntado o mesmo alvo e todas essas vozes valem.
  **O PIN e o QR moram numa aba só** — `Sala · PIN e QR code`, a ÚLTIMA do menu
  (`sala.js`): é a tela de PROJEÇÃO do encontro (QR e PIN grandes) e a casa do
  roteiro. **Os DOIS ritos projetam dali**: a tempestade (`modo` TEMPESTADE) e o
  encontro com roteiro (`modo` QUIZ) dividem o mesmo cartão de projeção
  (`cartaoProjecao`), porque a sala é uma só por planejamento e antes o PIN
  aparecia em telas diferentes conforme o rito — quem procurava "onde está o
  PIN" tinha de saber de antemão qual dos dois havia sido aberto. A Coleta ficou
  com uma linha de contexto e um atalho, sem PIN, sem QR e sem comando: comando
  em duas telas é sala em dois lugares. Junto do PIN vão **Copiar link** e
  **Compartilhar no WhatsApp** (`QuizSala.compartilhar`, um `wa.me` comum, sem
  SDK): nem toda sala tem telão nem todo participante consegue mirar o QR.
  A rodada da tempestade é buscada **sem filtro de ano** — ela pode ter nascido
  num ano diferente do seletor do diagnóstico, e filtrando por ano a aba dizia
  "nenhuma sessão" com a sala no ar. Ela tem **relógio próprio** (o de
  `QuizSala.armarRelogio` acompanha a sessão de roteiro e sai fora quando não há
  uma) e, com a tempestade no ar, o `aoBater` do quiz não repinta: o estado dele
  é "sem sessão" e apagaria o PIN do telão.
  **A pergunta da tempestade é da condução, não do cadastro**: o ✎ ao lado dela
  (`POST /api/rodadas/{id}/pergunta`, só com a rodada ABERTA e só para quem edita
  o planejamento) reescreve o `tema`, que chega ao celular na batida seguinte —
  ideias e votos já enviados ficam onde estão. Encerrar a rodada só para
  reformular jogaria fora PIN, participantes e o que já foi coletado. O campo é
  **`QuizSala.campoPergunta`**, um só para o formulário que abre e o que edita, e
  é `textarea` de propósito: é o campo de composição do sistema, o que traz o
  botão de ditado (`Modal.botaoDitar`) e cresce com o texto — numa linha só, a
  pergunta ditada saía da vista no meio da frase.
  As análises ficam com duas coisas: o **selo** (`QuizSala.selo`), uma
  linha dizendo onde a sala está — e ela fala mesmo quando está LONGE, com
  atalho ("a sala está em Porter · Rivalidade"), porque o silêncio seria lido
  como "não tem sala aberta", que é justamente quando alguém abre uma segunda —
  e o **painel de sugestões**, que não é sujeira: é onde a voz vira fator.
  **A sala muda quando o condutor ESCOLHE, nunca por navegar** (a regra
  "Navegar ≠ ativar" continua valendo, e pelo mesmo motivo: abrir a SWOT só para
  conferir um fator não pode interromper a discussão de Porter). O gesto é o
  **🎤 de cada categoria/lado/cartão** → `POST /api/quiz/tela`, um alvo por vez.
  Três guardas dessa rota: já sendo a ativa **não faz nada** (reativar zeraria o
  cronômetro da pergunta, e o 🎤 é tocado duas vezes sem querer o tempo todo);
  sem sala aberta responde 409/**`SEM_SALA`** para a tela perguntar antes (sessão
  que nasce sozinha é sessão sem nome, que ninguém sabe que abriu); e o 🎤 da
  categoria que já está na sala **é o interruptor dela**: tocar de novo
  **fecha** a pergunta (`POST /api/quiz/pergunta/{id}/encerrar`) e a sala para
  de receber respostas ali mesmo, sem passar pela aba Sala — tocar mais uma vez
  reabre, com as vozes que já tinha. Aceso ele fica vermelho ao encostar o mouse
  (verde = a sala está aqui; vermelho = vou fechar) e **fechar pede confirmação,
  sempre**: o 🎤 é tocado duas vezes sem querer o tempo todo, e a segunda tocada
  não pode calar a sala no meio da oficina. Quem fecha guarda a pergunta em
  `perguntaFoco`, senão o painel de vozes se esvazia junto com o fechamento.
  O painel de vozes tem o **mesmo interruptor em botão**: no lugar do "Reabrir
  para a sala" aparece **"Fechar para a sala"** quando a pergunta está ativa —
  mesmo `data-mic-fechar`, mesma confirmação, mesmo pedido.
  Lições da revisão adversarial desta fase: **o servidor nunca fabrica a
  confirmação do usuário** — o `abrir_sala` do 🎤 inventava `confirmar_encerrar`
  e derrubava a sala de outra pessoa entre o SELECT (fora da trava) e o
  `GET_LOCK`; **na edição, etapa e ano saem da LINHA, nunca do corpo** (são a
  identidade do registro, não campos do formulário: aceitá-los do corpo gravava
  categoria de PESTEL numa linha SWOT, e o fator sumia das duas telas);
  **vínculo é um UPDATE só, com teto e com a categoria na guarda**; **`ano` nulo
  recusa o vínculo** em vez de falhar calado (o "solta quem saiu" não depende do
  ano e desamarraria o que já estava); e **quem toca o 🎤 tem o foco devolvido
  ao padrão** — sem isso o painel ficava preso na pergunta EXAMINADA e o condutor
  lia vozes velhas enquanto a sala respondia outra coisa.
  O selo só diz "na sala" quando **a seção E o contexto** batem: o ano do
  diagnóstico é um seletor à parte, e "na sala" com a tela num ano e a sala em
  outro deixava o condutor sem painel, sem 🎤 aceso e sem atalho.
  A batida do polling **sai cedo quando a seção não é mais a ativa**: o relógio
  só confere `d-none` no começo, e `recarregarSecaoAtiva` recarregaria a tela de
  agora. E a assinatura é **semeada ao pintar**, senão a primeira batida repinta
  de graça — na aba Sala isso regenerava o SVG do QR, uma piscada no telão.
  A pergunta que a sala lê vem de `Quiz::PERGUNTA_CATEGORIA`, uma por categoria:
  montá-la do rótulo dava "Quais político você vê para 2026?" — as categorias do
  PESTEL são adjetivos, e pergunta torta é resposta torta.
  As vozes aparecem em **grade** (`.grade-sugestoes`), não em pilha: resposta de
  oficina tem duas ou três palavras, e uma ficha por linha na largura da página
  gastava metade da tela com cinco respostas. A grade nasce com **duas fileiras
  de fichas** e o resto rola por dentro — ou o condutor **arrasta o canto**
  (`resize: vertical`) até a altura que quiser; o painel ainda **recolhe**
  (`quizUi.painelRecolhido`), porque numa oficina cheia ele empurraria as
  colunas da análise para fora da tela. Fileira é medida de layout: quem calcula
  as duas é `QuizSala.ligarVozes`, **depois de pintar** (com o elemento
  escondido toda altura vale zero), e a altura escolhida no arraste mora no DONO
  (`quizUi.alturaGrade`) — no DOM, a batida seguinte do polling a devolveria ao
  padrão no meio de uma leitura. O `pointerup` que a guarda compara com
  `alturaPadrao`: clique comum dentro da grade também solta o ponteiro ali e
  congelaria a altura sem ninguém ter pedido.
  A ficha mostra **três linhas** e rola por dentro; o **👁** do rodapé abre a
  resposta inteira e só existe na ficha que não coube (medida, não palpite) —
  numa resposta de duas palavras ele seria ruído. Ele vem antes do
  `podeEditar()`: ler a resposta é direito de quem só acompanha também.
  A ficha é uma só (`QuizSala.fichas`), usada pelas
  três telas: escrita em cada uma, este layout divergiria na primeira mudança.
  **O "Usar" tem duas leituras, conforme o que o rito faz com a voz**, e a
  ficha as distingue pela opção `marcar`:
  - onde cada voz vira **um registro próprio** (um fator, um item de cenário),
    ela **SAI do painel** — o lugar dela passa a ser o quadrante de destino, e
    mantê-la ali com um ✓ fazia a fila de trabalho crescer com o que já foi
    feito (o contador vira "abertas de total");
  - onde **muitas vozes viram um texto só** (a síntese da célula da cascata),
    ela **FICA onde está**, marcada de verde, e o "Usar" é um **interruptor**
    (`Usar` ↔ `Usado ✓`). Tirá-la da grade escondia justamente o que compõe a
    síntese, e desmarcar ficava sem onde ser clicado. O estado é do **cartão
    inteiro** — marcada uma resposta unida, o bloco está marcado — e o contador
    **não muda** ao marcar (`contarCartoes(…, {marcar:true})`), senão marcar
    prometia trabalho a menos do que a coluna mostra.
  Nesse segundo caso, marcar **não salva nada**: a intenção mora em
  `SecaoCascata.usoQuiz[perguntaId] = {mais, menos}` (o que foi marcado e o que
  foi desmarcado agora) sobre a verdade do servidor (`vinculada`), e `comUso()`
  é a **única** fonte do verde e do texto composto — duas leituras separadas
  divergiriam no primeiro clique. Guardar só uma lista pronta faria a marca de
  outro condutor, chegando pelo polling, sumir da tela. O compromisso acontece
  UMA vez, ao salvar a célula ("Redigir com os marcados"): o texto de cada lado
  chega **composto na ordem dos cartões da coluna** — nunca na ordem dos
  cliques, que embaralharia a frase a cada desmarcar/remarcar — e é editável,
  porque a voz da sala é matéria-prima e a redação final é do condutor. O texto
  guardado só é recomposto enquanto ainda for o composto automaticamente
  (comparação com a composição do que está **vinculado**); reescrito à mão, ele
  é de quem escreveu e trocá-lo exige confirmação. Só o lado da pergunta EM
  FOCO é composto: com o painel na síntese, editar o cartão de um eixo não pode
  reescrever o texto nem os vínculos dele.
  **A célula não mostra pílula de voz** (as antigas "E ×"/"R ×"): ela publica o
  TEXTO, e o rastro de uso é o cartão verde no painel. Duas siglas com um ✕ cada
  empilhavam-se debaixo da síntese sem dizer nada legível, e o ✕ escondia uma
  ação destrutiva (desvincular) num lugar de leitura. O texto composto vai um
  cartão por linha e por isso a célula usa `.texto-celula` (`white-space:
  pre-line`) — sem ele as contribuições se emendam numa frase só no HTML.
  Apagado o destino, a voz **volta sozinha e JÁ REDIGIDA**: `Quiz::guardarRedacao` guarda o
  texto do registro no vínculo (a cada salvamento, não só ao amarrar — senão a
  edição seguinte deixaria a redação velha; na cascata, por LADO, porque a
  célula tem dois textos) e `Quiz::soltarVozes` **promove** esse
  `texto_tratado` a `texto`, limpando-o. Deixá-lo por cima do original criaria
  duas verdades, e a correção do participante pelo celular escreve em `texto` e
  ficaria invisível.
  Selo de origem (`Coleta · Fulano`, `🎤 N`) mora **dentro da faixa dos botões**
  do cartão, nunca numa linha própria: cada um numa linha custava duas linhas
  por cartão, e um cartão de três palavras ficava com a altura de um parágrafo.
  No front, as peças (`selo`, `microfone`, `roteiro`, `perguntar`, `fichas`,
  `cabecalhoPainel`) são o componente compartilhado
  **`public/assets/js/quiz.js`** (`QuizSala`), que não
  guarda estado: quem guarda é a seção dona (`plan`, `quiz`, `perguntaFoco`,
  `quizUi`, `secaoId`, `aoNavegar`, `aoBater`). PESTEL/Porter/SWOT nascem de
  `secaoEtapa()` justamente por isso — as três coexistem no DOM (navegar só põe
  `d-none`) e um estado compartilhado faria o polling de uma repintar a outra. `Modal.abrir`
  ganhou `enviar` para o 409 virar confirmação em vez de erro morto no modal.
  Armadilhas já pagas: o rádio focado do par de lados NÃO conta como
  "digitando" (congelava o polling); o rascunho atravessa redesenhos por
  `rascunhoPendente`; o 409 de pergunta trocada rebusca e redesenha com o
  rascunho (senão vira beco); o ditado dispara `input` manualmente para o
  contador; o polling do condutor rebusca os dados da tela junto com o estado,
  senão o modal reenviava conjunto velho de vínculos; e `ladoAtual()` valida o
  lado contra a pergunta ATUAL — a sala troca de análise e o lado guardado pode
  não existir no alvo novo. Toda tela que resolve "a pergunta é minha?" precisa
  conferir o `alvo_tipo` **antes** dos ids: pergunta de cenário tem
  `driver_id`/`horizonte_id` nulos e casaria com qualquer célula.
  Mais três lições da revisão adversarial: **"qual é a pergunta ativa" tem uma
  fonte só** (`Quiz::ativa`) e ativar é **um UPDATE só** — dois critérios
  faziam o condutor ver uma pergunta e a sala responder outra; **lista vinda do
  corpo é medida antes de tocar o banco** (`alvos` com 50 mil elementos = um
  SELECT cada, e `php -S` é single-threaded: doze segundos de servidor travado
  por um pedido que ia falhar de qualquer jeito); e **apagar um item de cenário
  ou um fator solta as vozes** por `Quiz::soltarVozes` — a da tempestade volta a
  SELECIONADO, a do quiz volta a NOVO (a única situação em que o autor ainda
  consegue corrigi-la pelo celular). O encerra-e-abre é serializado por
  `GET_LOCK` por planejamento: era check-then-act, e dois condutores passavam
  os dois — o segundo encerrando a sala que o primeiro acabou de abrir.
  Plano e decisões: `docs/CASCATA-QUIZ-COLABORATIVO.md`.
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
  Em "suas sugestões", a ficha do participante **flutua** o selo do lado e o
  ✎ (`float-start`/`float-end`): a frase começa ao lado do selo e as linhas
  seguintes ocupam a largura toda. Ao lado do texto em coluna (o que era), o
  selo comia um terço da tela do celular e uma resposta de quatro linhas descia
  em sete. A **listra da borda segue o lado** — `.ideia-minha.lado-success`
  (verde, primeiro lado) e `.lado-danger` (vermelho, segundo) —, os mesmos do
  par de botões: tudo verde obrigava a ler o selo para saber de que lado era
  cada uma, e o selo é justamente o que se quer parar de ler.
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
  Na fila de "Aguardando plano de ação", o **selo de origem e o botão são um
  bloco só**, encostado à direita (`.acoes-pendencia`, com `ms-auto`): soltos,
  eles quebravam em lugares diferentes conforme o tamanho de cada pendência. A
  linha é `flex-wrap flex-sm-nowrap` — num flex que quebra, **o navegador quebra
  a linha antes de encolher o item**, então com `flex-wrap` no computador um
  texto longo empurrava o grupo para baixo mesmo sobrando espaço depois de ele
  se acomodar em duas linhas. No celular a quebra continua ligada (os dois não
  cabem em 390px) e o `ms-auto` mantém o grupo à direita, porque margem
  automática vale por LINHA.
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

- **Fila de "Aguardando plano de ação"**: o card de Projetos junta TRÊS origens
  — ideia da Coleta (`coleta_item.destino_tipo='ACAO'` com `destino_id` NULL),
  **fator da SWOT** (`fator.acao_em` preenchido com `desdobramento_id` NULL) e
  **cruzamento (TOWS)**, com as mesmas três colunas do fator. O cruzamento vai
  **direto ao plano, sem passar pela cascata**: ele já é a estratégia que nasce
  do par, e a cascata decide outra coisa (em que horizonte cada driver aposta).
  Uma fila só de propósito: a origem muda o selo e o campo que fecha o vínculo,
  não a pergunta "o que ainda não virou ação?". O `modalConverterAcao` manda
  `coleta_item_id` **ou** `fator_id`, nunca os dois, e o
  `salvarDesdobramento` fecha o vínculo com a mesma guarda no WHERE (só o que
  ainda está na fila), para pedido repetido não sequestrar vínculo alheio.
  Só a **SWOT** vai direto ao plano: PESTEL e Porter descrevem o ambiente e
  passam antes pela promoção a um quadrante — sem isso pulariam a síntese que a
  SWOT existe para fazer. `fator.desdobramento_id` tem FK **ON DELETE SET
  NULL**: apagada a ação, o fator volta sozinho para a fila. A ideia da Coleta
  não tem FK (o destino é polimórfico) e por isso `excluirDesdobramento` limpa
  o `destino_id` dela à mão — sem essa linha a ideia sumia da fila para sempre,
  apontando para um desdobramento que não existe mais. Excluir um fator que já
  virou ação é **recusado**: deixaria a ação no plano sem origem nenhuma.

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
  na próxima data prevista e a conclusão vira um comentário automático — a
  ação volta a NAO_INICIADO e, sem esse registro, não sobraria rastro nenhum de
  que ela chegou a ser concluída. A regra está em `App\Services\Recorrencia`, o
  reagendamento avança ocorrência a ocorrência até passar de hoje, e o caminho
  que conclui uma ação é **um só**: o cadastro (era dois — o diário de bordo era
  o outro, e sumiu com ele).
- **Comentários com anexos** (`comentario` + `comentario_anexo`): sucederam o
  Diário de Bordo, que saiu do código. O registro continua datado e nunca
  sobrescrito; o que mudou é que agora carrega arquivo. O que o diário fazia
  além de listar texto — mexer em status e progresso — voltou para onde já
  existia: o formulário do cartão e a barra de progresso.
  O arquivo mora no **banco** (`LONGBLOB`), não em disco: o contêiner do Railway
  é efêmero e pasta de upload some no deploy seguinte, levando o anexo de todo
  mundo; sem Composer, SDK de armazenamento externo também está fora. O
  `conteudo` fica numa tabela à parte para o SELECT da lista não arrastar
  megabytes que a tela não usa — a miniatura busca cada arquivo por
  `GET /api/anexos/{id}`.
  O envio é **multipart** (`POST /api/comentarios`), não JSON com base64: base64
  infla 33% e carrega o arquivo duas vezes na memória. O CSRF continua valendo —
  ele é o header, não o tipo do corpo — mas `App.api` só fala JSON, então este é
  o único `fetch` na mão do sistema. E o Dockerfile precisa de
  `upload_max_filesize`/`post_max_size`: o `php.ini-production` corta em 2M/8M,
  abaixo do teto de 5 MB por arquivo, e o PHP descartaria o upload antes do
  controller.
  Guardas que não podem ser afrouxadas na rota que **serve** o anexo: o
  `Content-Type` sai da lista branca por EXTENSÃO (nunca do que o navegador
  declarou), `nosniff` impede o navegador de adivinhar outro, só imagem desce
  `inline` (o resto vai como `attachment`) e a CSP da resposta é
  `default-src 'none'; sandbox`. Imagem é conferida com `getimagesize` no
  recebimento: sem isso, um script renomeado para `.png` seria servido como
  imagem do MESMO domínio da sessão. O cache é `private` — em cache
  compartilhado o anexo vazaria para outra sessão.
  Apagar comentário é do **autor ou de um ADMIN**: quem edita o planejamento não
  herda o direito de apagar registro dos outros. Apagar projeto, ação,
  investimento ou escolha da cascata leva os comentários junto à mão (`ref_tipo`/
  `ref_id` é polimórfico e não tem FK); os anexos descem por ON DELETE CASCADE.
  A seção 5 do Relatório de Status e a aba do Excel passaram a ler daqui, e os
  registros antigos do diário **atravessaram na migração** (passo marcado em
  `carga_conteudo`, com `data_reg` virando `criado_em` ao meio-dia e status/
  progresso virando texto). A tabela `diario_bordo` fica no banco como arquivo
  da migração, sem código nenhum lendo dela.
- **Avisos por e-mail** (`App\Services\Avisos` + `App\Core\Email`): relatório
  semanal na segunda e pendências do dia. `envio_email` é a trava contra
  duplicidade — só conta como enviado o registro com `erro IS NULL`, para uma
  queda do serviço não bloquear o aviso.
  **A trava vale para o agendamento, não para o botão.** `Avisos::despachar`
  tem `$forcar`, ligado só por `RelatorioController::despacharAvisos`: quem
  clica é um ADMIN que quer o e-mail agora — para conferir o conteúdo, ou porque
  a pessoa apagou o de mais cedo — e um botão que responde "já enviado" e não faz
  nada deixa o sistema sem caminho nenhum para reenviar. O cron **nunca** força:
  ele roda sozinho e sem ninguém olhando, e sem a trava um agendamento a cada
  cinco minutos viraria doze e-mails por hora para gente de verdade. O reenvio
  atualiza `enviado_em` (a linha passa a valer pelo ÚLTIMO disparo) e é contado
  em `reenviados`, separado de `enviados` — senão o alerta da tela diria
  "1 enviado" para o primeiro clique e para o décimo. A assimetria é provada na
  bateria de e-mail (§5) com um dublê estático de `Database`, sem banco e sem
  massa: `Avisos` só fala com o banco por método estático, então a classe
  declarada antes do `require` substitui a tabela inteira.
  **Relatório do disparo** (`tipo = 'RESUMO'` em `envio_email`): depois de cada
  rodada de avisos, quem tem perfil **ADMIN** recebe um e-mail com o que acabou
  de sair (incluindo o motivo de cada falha) e a **carteira por responsável** —
  total, abertas, atrasadas, vencem hoje, concluídas — mais a quebra **por
  situação**, sempre com o percentual **ao lado do número absoluto**: "100%
  atrasadas" de uma ação e de quarenta pedem providências diferentes. Ele só sai
  quando **alguma coisa foi disparada**; um relatório diário de "nada aconteceu"
  ensina a ignorar o remetente, e aí o dia em que algo falha passa junto. Três
  decisões da consulta (`Avisos::carteira`): entra TODA ação, não só a do dia;
  "atrasada" é medida pela **data**, o mesmo critério da cobrança, e não pelo
  status `ATRASADO`, que é reconciliado na leitura das telas e no horário do
  cron pode estar velho; e ação **sem responsável** ganha linha própria, porque
  ela não recebe cobrança nenhuma e some de todos os outros relatórios.
  O `RESUMO` precisou de tipo próprio no ENUM: sem ele, colidiria com o aviso do
  próprio admin na chave (tipo, referência, usuário).
  Disparo por cron (`php cli/notificar.php`) ou pelo botão do Relatório.
  **Dois caminhos de envio**: SMTP na mão (EHLO/STARTTLS/AUTH LOGIN) e **API
  sobre HTTPS** (`EMAIL_API_CHAVE` + `EMAIL_API_URL`, formato da API
  transacional do Brevo). A API tem **precedência**: quem definiu a chave já
  descobriu que a porta não abre, e tentar o SMTP antes custaria 20s de espera
  por destinatário para terminar no mesmo tempo esgotado.
  O segundo caminho não é luxo — **no Railway as portas de e-mail são
  bloqueadas** (medido: 587, 465 e 2525 dão tempo esgotado, a 443 abre na hora),
  e ali *nenhum* servidor de e-mail é alcançável, nem o do próprio domínio.
  Quem separa as causas é `php cli/notificar.php diagnostico`: imprime a
  configuração (**a senha e a chave nunca**, só se estão definidas e o
  tamanho), resolve o DNS, tenta as portas com a 443 como referência e conclui.
  A referência é o que distingue "SMTP bloqueado" de "contêiner sem rede".
  Erro de envio vira exceção com a resposta do serviço, e a tela **mostra o
  motivo** agrupado por mensagem (não só a contagem): "2 falha(s)" mandava
  procurar em log do provedor uma informação que já vinha na resposta, e as
  causas prováveis pedem providências opostas.
- Cartões de projeto/iniciativa/ação mostram só título e situação; o resto vai
  atrás de uma **seta** (`botaoMais`, um chevron que gira com o `aria-expanded`;
  o significado fica no `aria-label`/`title`). Era o texto "mostrar mais", que
  na linha do cartão de ação disputava largura com o selo de situação e a barra
  de progresso — doze caracteres ali saíam da barra, que é a peça que se lê de
  relance. O mesmo botão serve aos três níveis: dois jeitos de expandir na mesma
  tela seriam duas coisas para aprender.
- **O cartão de ação tem cinco linhas**, nessa ordem: (1) situação, barra de
  progresso e a seta, alinhadas pelo centro; (2) o **quê**; (3) o **como**;
  (4) os metadados — **Prazo · Quem · Prioridade**, e depois o que houver de
  repetição, onde, por quê e quanto; (5) o rodapé, com **Comentários à
  esquerda** e **✎ ×  à direita**. Da linha 3 em diante fica atrás da seta.
  O "como" saiu do amontoado de metadados e ganhou linha própria porque ele e o
  "o quê" **são** a ação — o que se faz e por onde —, e no meio de sete campos
  separados por ponto o caminho virava rodapé. Na linha 1, quem cede largura é a
  **barra** (`flex-grow`), nunca o selo nem a seta: ela é a única peça que se lê
  por proporção, e as outras duas viram texto cortado. As barras de progresso usam sempre o mesmo
  estilo (`.faixa-progresso` para leitura, `input[type=range].faixa-verde`
  para ajuste) com **passo 1** — passo maior faria o valor divergir do
  servidor.
- **Duplo clique no cartão abre a edição** daquele nível (duplo toque no
  celular), atalho para o ✎ que mora atrás do “mostrar mais”. O listener vai em
  **cada cartão de projeto**, nunca na seção: `el` sobrevive aos
  recarregamentos e um listener nele empilharia uma cópia por `carregar()`. A
  resolução é do **mais interno para o mais externo** (ação → iniciativa →
  projeto), porque os três níveis são aninhados no DOM. Botão, link, a barra de
  progresso e os comentários dentro do cartão não viram atalho; a seleção de texto do
  duplo clique é limpa antes de abrir o modal. Os cartões levam
  `touch-action: manipulation`, senão o iOS trata o segundo toque como zoom e o
  `dblclick` não chega.
- **Campos da ação numa lista só** (`camposAcao` + `valoresNovaAcao` +
  `transformarAcao`): os dois formulários que escrevem uma ação — o cadastro e o
  direcionamento de uma ideia da coleta — usam a MESMA lista. Escritos
  separados, divergiram: o direcionamento pedia só o quê/quem/prioridade e
  criava a ação sem como, prazo, repetição, custo nem status, obrigando a
  reabri-la no cadastro para completá-la.
  Ordem (pedido do cliente): **o quê, como, quem, quando, prioridade**, a
  **linha** da repetição (repetição · repete toda · repetir até), a **linha** de
  status e custo, e o progresso. "Quem?" não estava na ordem pedida e ficou
  assim mesmo, logo depois do "Como?": é de `quem_usuario_id` que saem os avisos
  por e-mail e o filtro de "minhas ações" — sem ele a ação não tem dono.
  **Obrigatórios: o quê, como, quem e o período** — os quatro recusados em
  `ProjetoController::salvarDesdobramento`, não só marcados na tela. Ação sem
  caminho e sem prazo não é plano, e o prazo é o que alimenta o atraso
  automático, os avisos e o painel. Consequência para quem for mexer: ação
  antiga sem "como" ou sem datas passa a exigir os dois na próxima vez que
  alguém abrir e salvar.
- **O cabeçalho de Projetos gruda** abaixo da topbar (`.cabecalho-projetos`,
  `top: var(--topo-app)`, fundo sólido e `z-index: 3`, o mesmo mecanismo do
  cabeçalho da GUT): os três botões de nível são o controle que se usa LENDO a
  lista, e trocar de visão no quinto projeto obrigava a subir a página inteira.
  O parágrafo de instruções fica **fora** do bloco fixo de propósito — ele se lê
  uma vez, e grudado custaria uma faixa de tela em toda rolagem, para sempre.
  As margens negativas cobrem a sarjeta do container, senão a lista aparece
  pelas beiradas ao passar por baixo.
- **Três níveis de recolhimento** (`nivelAtual` / `aplicarNivel` /
  `pintarNiveis`): **Ações · Frentes · Projetos**, no lugar do "Recolher tudo"
  que só tinha os extremos. "Frentes" é o nível que faltava — esconde as ações e
  mantém projetos e frentes com os seus percentuais. Recolher a iniciativa já
  era o que escondia as ações dela; o grupo só dá um toque para chegar lá. Com o
  usuário abrindo/fechando itens à mão, `nivelAtual` devolve vazio e **nenhum
  botão fica aceso** — melhor que um botão mentindo. Os acordeões chamam
  `pintarNiveis`: eles mexem no DOM sem recarregar a seção, e sem isso o grupo
  seguiria marcando "Ações" com as ações já escondidas.
- **Resumo por situação** (`resumoStatus(acoes, apenas)`): no cabeçalho de cada
  frente, um selo por situação PRESENTE, com a contagem e o percentual. No
  **projeto** vai só o **atraso** (`apenas: ['ATRASADO']`): no nível de cima a
  pergunta é uma — o que está fora do prazo e quanto isso é do total criado —, e
  sete selos por projeto numa tela com vários viraria ruído. O denominador é
  sempre o TOTAL do nível, mesmo com `apenas`.
  A base do percentual é o nível em que ele está — no projeto, todas as ações
  (as frentes somadas); na frente, as dela. Trocar a base é defeito invisível:
  os selos continuam plausíveis dizendo outra coisa, e é o que a bateria guarda.
  No cabeçalho do projeto esse é o **único** selo: o de situação agregada saiu
  de lá, porque dizia "Atrasado" ao lado de "Atrasada: 1 (20%)" — a mesma
  notícia duas vezes, uma delas sem o tamanho. A média continua na barra logo
  abaixo.
  Situação **sem nenhuma ação não vira selo com zero** — numa fila de sete, seis
  zerados, o que importa se perde no meio. Vale para o projeto: **sem atraso,
  nenhum selo**; a ausência é a boa notícia, e um "Atrasada: 0 (0%)" em toda
  linha treinaria o olho a pular justamente o selo que importa. O percentual é arredondado e pode
  somar 99% ou 101%: a contagem é que manda, e o `title` traz "N de T ações".
  A cor sai de `CORES_STATUS`, que é a MESMA leitura de `STATUS_ACAO` em hexa —
  a regra da GUT (a cor junta, a forma separa): o selo do cartão é sólido, o do
  resumo é claro com um ponto, porque vários sólidos lado a lado viram parede.
  O `"N atrasada(s)"` saiu do panorama por isso: o resumo diz o mesmo com o
  percentual junto, e dizer duas vezes obrigava a conferir se batiam.
- **Panorama de execução** (`panorama()`): barra da média + percentual. É o **mesmo bloco** no projeto e na iniciativa — escritos
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

## Baterias de validação (`testes/`)

`./testes/rodar.sh` roda as seis em sequência e devolve 0 só se todas passarem
— dá para pendurar num hook ou num CI. Elas batem numa instância **local**
(nunca produção: a funcional cria e apaga registros) e o detalhe está em
`testes/README.md`, inclusive o que elas **não** cobrem.

| Bateria | Cobre | Falha quando |
|---|---|---|
| `funcional.sh` | Escrita de cada módulo, pela própria API | Uma regra de negócio parou de valer, ou passou a valer onde não devia |
| `sistema.js` | As 16 seções em 1500×700 e 390×844 | Uma tela parou de pintar, estourou erro de console ou passou a rolar na horizontal — **nas duas larguras** |
| `participante.js` | A tela pública da tempestade no celular | A única superfície de escrita sem login quebrou, ou o polling voltou a fechar o teclado |
| `backup.sh` | Gerar, verificar e restaurar de `cli/backup.sh` | O backup deixou de ser restaurável, o anexo binário parou de atravessar, ou arquivo pela metade voltou a passar por bom |
| `email.sh` | O envio por API de `App\Core\Email`, o relatório do disparo, e a assimetria botão×cron | O caminho da API parou de ser escolhido, a recusa do serviço deixou de chegar a quem clicou, a chave passou a vazar na mensagem de erro, ou o relatório do admin passou a sair (ou a não sair) na hora errada |
| `backup_remoto.sh` | A cópia fora do provedor, contra um B2 de mentira | O envio parou de subir o arquivo inteiro, o erro do serviço deixou de chegar, a chave vazou, ou a falta de configuração passou a derrubar o backup local |

A do backup é a única que **não** passa pela aplicação — fala com o banco
direto, lê o de trabalho sem escrever nele e faz o vaivém em dois bancos
descartáveis que ela mesma cria e derruba. Sem um usuário com `CREATE DATABASE`
ela é **pulada**, pelo mesmo motivo da do participante.

O que é comum às duas de navegador mora em `testes/comum.js` — resolver o
Chromium, fazer login, esperar sem `waitForFunction`. Escritas separadas,
divergiriam na primeira mudança da tela de login. `chromiumExec()` acha o
binário **por glob**: o número da build muda a cada atualização da imagem, e
fixá-lo faria a bateria parar de rodar sem ninguém entender por quê.
A do participante é **pulada** (não reprovada) sem `PIN_TEMPESTADE`: ela
depende de uma rodada aberta, que nem toda instância tem, e um vermelho por
falta de massa ensina a ignorar o vermelho.
Três cautelas ao escrever prova nova: a seção continua no DOM quando escondida,
então **esperar pelo seletor não prova que a tela está visível** (confira o
`d-none` e reemita `App.mostrarSecao` num laço — o `mostrarSecao('painel')` da
inicialização chega depois e reesconde a seção que a prova acabou de abrir); a
altura de janela é parte do teste — a bateria roda em 700px justamente porque é
a janela em que o modal da GUT não cabia inteiro; e **defeito de largura não é
só de celular**. A rolagem horizontal era conferida apenas em 390px, e a que os
Cruzamentos introduziram aparecia no **computador**: a mínima de conteúdo de um
selo com `nowrap` sobe pela coluna e pela fila até o `<main>`, que é item de
flex — quanto mais larga a tela, mais texto cabe numa linha e maior fica essa
mínima. A checagem passou a rodar nas duas larguras.

## Deploy

- Railway, servidor embutido do PHP (`php -S` no `entrypoint.sh`) — adequado a
  homologação; para produção o recomendado é php-fpm + nginx
  (ver `docs/DEPLOY-RAILWAY.md`).
- Após deploy com mudança de CSS/JS, um refresh normal já pega a versão nova
  (graças ao `versao_asset`).
- E-mail: variáveis `SMTP_*` e `EMAIL_REMETENTE` (tabela em
  `docs/DEPLOY-RAILWAY.md`); sem elas os avisos ficam desligados. O envio
  diário depende de um cron do Railway chamando `php cli/notificar.php`.
- **Backup** (`cli/backup.sh`, com `restaurar`/`verificar`/`listar`): é um dump
  do MySQL e só — o banco guarda TUDO, inclusive os anexos dos comentários, e
  não existe pasta de arquivos para copiar. A conexão sai de `config/config.php`
  (uma verdade só) e a senha vai num arquivo de opções temporário, nunca em `-p`
  na linha de comando, que o `ps` de qualquer usuário lê. Decisões que não podem
  ser desfeitas: são **dois passos de dump** no mesmo fluxo — estrutura de tudo,
  dados de tudo menos `sessao`/`login_tentativa`/`coleta_tentativa` (restaurar
  sessão devolve acesso a quem estava logado no dia do dump, com o cookie de 30
  dias ainda de pé) —, e por isso a marca de fim é **própria**
  (`-- FIM DO BACKUP`, escrita só se os dois passos voltarem zero): o
  `-- Dump completed` do mysqldump fecha o PRIMEIRO passo e cai no meio do
  arquivo, então um dump cortado durante os dados passava por íntegro. O arquivo
  só ganha o nome final depois de verificado (até lá é `.parcial`), nasce `0600`
  e leva o nome do BANCO no prefixo — com prefixo fixo, a faxina de retenção de
  um ambiente contava os arquivos do outro. No Railway o disco é efêmero: o cron
  diário só serve com um **Volume** montado e `BACKUP_DIR` apontando para ele
  (ver `docs/DEPLOY-RAILWAY.md`). O script **avisa** quando o destino está no
  disco do contêiner (`avisar_efemero`, ponto de montagem `/`), e só no
  provedor: na máquina de quem desenvolve, `/` é disco de verdade e o aviso
  apareceria sempre — aviso que aparece sempre é aviso que ninguém lê.
- **Cópia fora do provedor** (`cli/backup_remoto.php`, Backblaze B2): o backup
  local protege contra erro DENTRO do Railway; não protege contra perder o
  Railway. Sai de dentro do `backup.sh`, **depois** de o arquivo ter passado por
  íntegro, e falha no envio **não** derruba o backup — o arquivo local existe, e
  devolver erro faria o cron marcar como perdido um backup que está feito. Sem
  as variáveis `B2_*` não faz nada e não reclama.
  Três decisões: é **PHP**, não shell (a imagem tem PHP por definição; `curl` de
  linha de comando pode não estar lá); é **B2 e não S3** (a API nativa é
  token em três chamadas — a da Amazon e as compatíveis exigem assinar cada
  pedido com SigV4, que é criptografia escrita à mão para resolver um upload); e
  o envio usa `CURLOPT_UPLOAD` com o método trocado para POST — `POSTFIELDS`
  carregaria o dump inteiro na memória e um POST com `READFUNCTION` não tem como
  anunciar o tamanho (o PHP não expõe `CURLOPT_POSTFIELDSIZE`), virando
  `chunked` e pendurando o envio. `B2_API_URL` existe para a bateria apontar a
  um serviço de mentira.

## Convenções de entrega

- Branch de trabalho: `claude/git-repo-overview-d17774` — desenvolver,
  commitar e fazer `git push -u origin` sempre nessa branch.
- Mensagens de commit em português, primeira linha descritiva.
- Ao concluir trabalho grande: rodar o time de agentes de revisão
  (segurança, corretude, infra, frontend) e aplicar os achados confirmados;
  manter a responsividade mobile; rodar `./testes/rodar.sh` antes de commitar.
  Defeito corrigido vira **prova na bateria**, no mesmo commit: é o que impede
  que ele volte na refatoração seguinte sem ninguém notar.
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
  triagem pós-brainstorm, mapa BSC e ritual de
  acompanhamento — com o veredito de o que vale ou não construir).
- `docs/REFATORACAO-GTD-COLETA.md`: o fluxo GTD da Coleta como ficou (matriz
  única, arraste, menu da pílula, saídas da ideia encaminhada), as decisões do
  cliente e os defeitos que a validação pegou. Leia antes de mexer na condução da
  tempestade.
- `docs/CRUZAMENTOS-SWOT.md`: o plano dos **cruzamentos (TOWS)** — a quinta tela
  do diagnóstico, o modelo de dados (tipo derivado do par, par único por ano),
  a ponte para a cascata e as cinco fatias de entrega. As **fatias 1 e 2 estão
  entregues** (tabela, controller, tela, testes); o §9 registra o que foi
  decidido na execução — entre outras coisas, que o `destino_tipo`/`destino_id`
  polimórfico do plano **não** entrou, porque a fatia que o usaria ainda não
  existe e coluna sem leitor é convite a divergir — e o §10 lista o que a
  **fatia 3** ainda precisa resolver antes de ser escrita.

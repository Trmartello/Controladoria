# CLAUDE.md — Guia do projeto Controladoria

Sistema de planejamento estratégico da Copérdia (one-page app em PHP 8 + MySQL,
deploy no Railway). Idioma do código, commits e UI: **português**.

**Este é o sistema do PE.** Existe um segundo repositório,
`Trmartello/PE_PLAN_ESTRATEGICO` — um app React que põe em tela a planilha do
plano **2026–2030** (dashboard para o Conselho e simulador de cenários), servido
na Railway atrás de senha. Ele **não** é este sistema, não tem login nem banco,
e o backlog dele (gestão de metas, importação de Excel) é provavelmente
redundante com o que já existe aqui. Pergunta sobre "o backlog do PE" se
responde a partir de `docs/BACKLOG-EVOLUCAO.md` **deste** repositório — a
confusão já custou uma resposta errada. Fica em aberto com o cliente qual ciclo
vale: o 2026–2030 de lá ou o 2027–2035 daqui.

## Arquitetura

- **Onde fica o quê**: `app/` (Core, Controllers, Services), `views/` na RAIZ —
  não em `app/Views/` — com as três telas que existem (`shell.php`, o one-page
  atrás do login, com as 18 `<section class="secao d-none">`; `login.php`; e
  `participante.php`, a do celular sem login), `public/` (front controller e
  assets), `database/`, `cli/`, `config/`, `testes/`, `docs/`.
  Qual arquivo atende qual seção **não está escrito aqui de propósito**: o mapa
  vive em `App.recarregarSecaoAtiva()` (`app.js`), e uma segunda cópia num
  documento é a que ninguém atualiza. Este guia cita o arquivo quando explica
  uma regra, não como catálogo.
- **Front controller**: `public/index.php` — tabela de rotas em `switch (true)`.
  Todo método de controller termina em `Json::ok()`/`Json::erro()` (ambos
  encerram a execução), mas cada `case` ainda leva `break;` defensivo.
  Ele também **serve os assets** ele mesmo, por um mapa de extensão→MIME no
  topo do arquivo. Isso tem uma consequência prática: **tipo de asset novo que
  não esteja no mapa vira 404**, não arquivo entregue — é uma linha a
  acrescentar, mas quem não souber vai procurar o defeito no HTML. A forma
  antiga (devolver `false` e deixar o servidor embutido resolver) foi
  abandonada por duas medições: o arquivo saía sem `Content-Type`, sem cache e
  sem `nosniff`; e o `php -S` respondia a esse `false` **incluindo o
  `index.php` de novo na mesma requisição**, onde a redeclaração de
  `versao_asset()` derrubava o pedido com erro fatal — bastava um robô pedir
  `/index.php`. Por isso os cabeçalhos de segurança aparecem **duas vezes** no
  arquivo: este bloco sai antes do bloco geral, e sem repeti-los `/index.php` e
  `/.htaccess` respondiam sem CSP nenhuma.
- **Cabeçalhos de segurança**, no topo do front controller e **antes de abrir a
  sessão**: `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy: same-origin`, `Content-Security-Policy`, HSTS quando a
  requisição chegou por HTTPS, e `header_remove('X-Powered-By')`. A ordem é
  deliberada e custou um defeito: o handler de sessão consulta o MySQL, e uma
  queda do banco estourava a exceção **antes** dessas linhas — a resposta 500
  saía sem CSP, sem `X-Frame-Options` e com a versão do PHP à mostra,
  justamente nas rotas autenticadas.
  A CSP é `default-src 'self'` com `style-src` liberando `'unsafe-inline'` e
  mais nada. Ela não é decoração: é o que **proíbe**, na aplicação inteira,
  `<script>` embutido no HTML, `onclick=` no atributo e qualquer `eval` — hoje
  não existe uma única ocorrência dos três, e código novo que use qualquer um
  deles simplesmente não roda no navegador. É também a razão de fundo das
  bibliotecas vendoradas: sem `script-src` para um CDN, referenciar um CDN é
  código morto, não escolha de estilo. E é o que faz a bateria de sistema não
  poder usar `page.waitForFunction` (ele avalia string como JS) — está anotado
  em `testes/README.md`, mas a causa é esta linha.
- **Fuso horário — o servidor e o banco precisam concordar.** O contêiner roda
  em UTC e a cooperativa trabalha em UTC−3, então das 21h à meia-noite o
  servidor já estava no dia seguinte. Isso marcava como ATRASADA a ação que só
  vence amanhã, podia disparar o relatório semanal num domingo à noite e
  gravava `data_reg` do dia errado no diário. `config/config.php` resolve com
  `date_default_timezone_set(env('TZ_APP', 'America/Sao_Paulo'))`, e o arquivo é
  carregado por **todos** os pontos de entrada — front controller, migração e
  CLI de avisos —, então o fuso vale para os três.
  Só que metade das decisões de data é tomada no **SQL**, não no PHP:
  `Avisos::despachar` usa `date('Y-m-d')`, `Consolidacao::sincronizarAtrasos`
  usa `CURDATE()`, e os dois decidem a mesma coisa. Por isso o `Database`
  manda `SET time_zone` na abertura da conexão, com o **deslocamento**
  (`date('P')`, e não o nome da zona: nome exige as tabelas de fuso carregadas
  no MySQL, o que nem sempre acontece). Data nova, venha de `NOW()` ou de
  `date()`, já nasce concordando — mas só porque essa linha existe.
- **Core** (`app/Core/`): `Auth` (sessão, perfis ADMIN/CONTROLADORIA/DIRECAO/
  GESTOR/LEITURA, escopo usuário×negócio, CSRF via header `X-CSRF-Token`),
  `Database` (PDO, sempre prepared statements; é ele que acerta o fuso da
  conexão — ver o bullet do fuso horário), `Json`, `SessaoBanco`
  (sessões em MySQL na tabela `sessao` — sobrevivem a deploys; cookie 30 dias),
  `Versao` (o **pulso**: um contador por planejamento que sobe a cada escrita,
  marcado DENTRO de `Auth::exigirEdicaoPlanejamento()` para que endpoint novo
  não precise lembrar de marcá-lo — ver "Duas telas juntas") e `Email` (cliente
  SMTP/API escrito à mão: sem Composer, não há biblioteca para isso).
- **Serviços** (`app/Services/`): `QlikSync`, `Recorrencia` (repetição das
  ações — usada pelo cadastro), `Avisos` (e-mails do plano
  de ação), `Consolidacao` (reconciliação do que é *consequência*: atraso da
  ação e período/status do projeto). `Consolidacao::reconciliar($planId)` roda
  no começo de **toda leitura** que exibe esses campos — Projetos, Painel e
  Relatório. Deixá-la só em Projetos fazia o painel da direção contar zero
  atraso até alguém abrir a seção, e os números mudavam sozinhos depois.
  `Fatores::exigirSemAcao` é a guarda **compartilhada** por `FatorController` e
  `ColetaController`: excluir um fator (ou a ideia que virou fator) apaga junto
  o promovido à SWOT, e ele também pode carregar o `desdobramento_id` — a guarda
  confere `f.id IN (…) OR f.promovido_de_id IN (…)`, senão a ação ficava no
  plano sem origem nenhuma. Ela é uma casca sobre **`Fatores::acoesQuePrendem`**,
  que devolve `[fator => ação]` para uma lista inteira: é a MESMA consulta que
  alimenta o `acao_trava` da listagem, com que a tela desabilita o × antes do
  clique. Uma definição só de "está preso" — a tela e o servidor não podem
  discordar.
  Os quatro serviços mais novos existem pelo MESMO motivo do `Fatores`: uma
  regra que passou a ter **dois chamadores**. `Quiz` (o que cada alvo de
  pergunta significa — o lado da resposta, o limite de texto, o contexto que
  desce ao celular: nasceu repartido entre controller e tela), `Cruzamentos`
  (a regra do par da TOWS, pedida pela tela autenticada **e pela rota
  pública**), `Bloqueio` (o cadeado de edição, com as guardas que o servidor
  aplica) e `CargaConteudo` (texto redigido fora do sistema, aplicado pelo
  migrate e pela CLI). Serviço aqui não é camada por gosto: é onde a regra fica
  escrita **uma vez**, para que a segunda cópia — que na prática é a frouxa, e
  no caso do `Cruzamentos` seria a exposta sem login — não chegue a existir.
  Cada um está explicado na sua seção de regra de negócio, abaixo.
  Autoload PSR-4 caseiro em `public/index.php`
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
  dezoito seções para baixo. Por isso rótulo, valor e o ⚙ dividem UMA linha,
  o subtítulo "Planejamento Estratégico" só aparece onde a topbar o esconde
  (`d-sm-none`, abaixo de 576px) e no menu vai só o NOME do ciclo, com o
  ano-base no `title`: numa linha só, "2027–2035 (base 2026)" era cortado
  justamente no ano-base, e meia informação engana mais que informação nenhuma.
  Medido: 238px → **144px** antes do primeiro item (desktop), 173px no celular.
  A engrenagem é a MESMA da topbar, por `<use href="#i-engrenagem">` — o
  símbolo é desenhado uma vez no `shell.php`, senão as duas cópias divergiriam
  na primeira revisão do ícone e o leitor lê "mesmo símbolo" como "mesmo
  destino".
- **Menu lateral com tópicos recolhíveis** (2026-09-02, pedido do cliente, no
  padrão do CRM Agro): Painel, Hub e Cadastros soltos; Diagnóstico, Estratégia,
  Execução, Capital, Gestão e Encontro como tópicos com cabeçalho
  (`<button class="cabecalho-grupo">`, `aria-expanded`) e chevron. Nascem
  fechados e **só um fica aberto por vez** (abrir um fecha os outros, como uma
  sanfona — segundo pedido do cliente, no mesmo dia); `App.mostrarSecao` abre o
  tópico da tela ativa, e o aberto fica em `localStorage` (`menu.grupos`). Os links continuam sendo
  `#nav-secoes .nav-link` / `[data-secao]`: o contrato é o atributo, não a
  profundidade. Prova em `sistema.js` (`provasMenuRecolhido`).
- **Atalho ⚙ na topbar** (`#btn-cadastros`, ao lado do ☰): abre os Cadastros —
  a tela de AJUSTAR o sistema, que não faz parte do percurso do planejamento e
  se procurava no meio de dezoito seções. É um `<a data-secao>`, o mesmo
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
  aoSalvar, enviar, aoMudar, bloqueio})` (`modal.js`) — `bloqueio:
  {recurso, registro_id, planejamento_id}` é tudo o que um formulário precisa
  dizer para ganhar o cadeado de edição; `enviar` substitui o POST padrão quando
  salvar exige mais de uma chamada (o 409 de sala aberta virando confirmação).
  Componentes usados por VÁRIAS seções ficam soltos em `public/assets/js/` e
  carregam antes das seções no `shell.php`: `quiz.js` (`QuizSala`, a condução
  do 🎤 — cascata, coleta, cruzamentos, diagnóstico e sala), `vinculos.js`
  (`Vinculos.aviso()`, o que some junto ao excluir), `relatorio-analise.js`
  (`RelatorioAnalise`, o corpo comum do relatório de uma análise), `vivo.js`
  (`Vivo`, o relógio de 4s que lê o pulso) e `cadeado.js` (`Cadeado`, o
  contador do item travado).
  Os dois últimos ninguém liga na seção: `App.recarregarSecaoAtiva()` arma e
  para o `Vivo` (depois de a pintura terminar — armar antes tomaria a versão de
  referência com a tela ainda lendo, e uma escrita nesse intervalo passaria por
  "já vista"), e o `Modal.abrir` toma e solta o `Cadeado` sozinho, a partir do
  `bloqueio:` do formulário. É a mesma escolha do `Versao::alvo()` no servidor —
  seção nova que esquecesse de chamá-los não quebraria nada visível, e defeito
  que se contorna com F5 é defeito que fica.
  `App.api` põe `codigo` e `status` no `Error` que lança: erro que a tela
  precisa DECIDIR (e não só mostrar) vem por código, nunca por texto.
  Bootstrap 5.3.3 e o `qrcode.js` (o QR da sala) são as duas ÚNICAS
  bibliotecas de terceiros, **vendoradas** em `public/assets/vendor/` — CDNs
  são bloqueados no ambiente de execução E pela CSP da própria aplicação
  (`default-src 'self'`), então nunca referencie CDN: não é preferência, é
  código que não carrega.
- **Tipos de campo do modal**: `text`, `textarea`, `select`, `multiselect`,
  `checkbox`, `password`, `number`, `date`, `hidden`, `periodo` (duas datas),
  `info` (bloco só de leitura, com barra colorida opcional; com `itens:
  [{rotulo, texto, cor}]` mostra PEÇAS distintas, cada uma na sua caixa
  colorida, e o bloco perde o teto de ~10 linhas — dois registros emendados num
  parágrafo só, como era o par de um cruzamento da SWOT, liam-se como uma frase
  única. Transbordando, `Modal.marcarInfoRolavel` acende o esmaecido do rodapé
  (`.info-tem-mais`): sem ele o corte no meio da frase parecia defeito, e no
  celular é ali que um par longo é segurado dentro da tela), `botoes` (option
  buttons), `quadrantes` (matriz SWOT 2×2), `selecao_livre` (combobox com
  busca que aceita nome novo), `faixa` (slider), `lista_marcavel` (itens
  marcáveis com selos coloridos, descrição cortada em 3 linhas com “ver mais”,
  pesquisa acima de 5 itens e contador — usar sempre que o usuário precise ler
  o item antes de marcar; `multiselect` só serve para listas curtas e não
  funciona no celular, onde não existe tecla Ctrl), `dias` (fichas marcáveis
  para os dias de uma repetição: `grade: 'mes'` são 31 números em sete colunas,
  como um calendário, e `grade: 'semana'` são os sete nomes, que fluem e quebram
  a linha — a escolha é MÚLTIPLA nas duas), `moeda` (dinheiro; ver abaixo) e
  `arquivos` (input de arquivo para formulário cujo envio é multipart —
  `coletar()` o pula, arquivo não viaja em JSON, e o `enviar` do formulário lê
  os escolhidos por `Modal.arquivosDe(nome)`; opções `multiplo` e `aceita`).
  Opções auxiliares: `obrigatorio`, `visivelSe: {campo, valores}`, `exemplo`,
  `ajuda`, `nota`, `sufixo`, `passo`, `separador` (desenha um filete acima do
  campo, para separar duas perguntas dentro de uma caixa), `unico` (no
  `lista_marcavel`: escolhe UM item, os quadrados viram redondos e o campo
  devolve o valor em vez da lista), `linha` (campos **consecutivos** com a mesma
  `linha` dividem uma fileira — `.grade-campos`) e `caixa` (campos
  **consecutivos** com a mesma `caixa` ficam dentro de um painel —
  `.caixa-campos`). As duas são montadas por `Modal.renderCampos`, que agrupa em
  DUAS camadas: primeiro a caixa, depois as linhas de dentro. Campo curto
  ocupando a largura inteira custa uma faixa de tela cada, e o Salvar mora no
  rodapé fixo: o que sobra de altura é rolagem.
  A **caixa** é para uma decisão e tudo o que ela revela — a repetição da ação,
  que mostra a grade da semana, a do mês ou o prazo de execução conforme a
  escolha. Soltos, os campos revelados pareciam pertencer ao resto do
  formulário, e trocar "todo mês" por "não se repete" trocava blocos sem relação
  aparente. Ela não leva padding embaixo: o respiro é a margem do último campo,
  porque **qual é o último muda** com o `visivelSe` — um padding fixo sobraria
  sozinho quando o último estivesse escondido.
  Três cautelas: o agrupamento é **por vizinhança** nas duas camadas (juntar
  campos distantes reordenaria o formulário por baixo do pano, e a ordem é
  decisão de quem escreveu a lista) — por isso os `hidden` do formulário da ação
  vão todos juntos no topo, senão um deles no meio cortaria a vizinhança; a
  grade da linha é `auto-fit`, nunca coluna fixa, porque há campo que some com o
  `visivelSe` e coluna fixa deixaria buraco no lugar do escondido; e o que está
  FORA de caixa nenhuma também é um bloco **só** — um bloco por campo solto
  entregava um campo de cada vez ao agrupador de linhas, que nunca via dois
  vizinhos para juntar. **`obrigatorio` desenha o asterisco, não recusa o
  envio**: quem valida é o servidor, e marcar um campo na tela sem a guarda lá
  deixa o formulário mentindo.
- **Campo de dinheiro** (`moeda`, `Modal.ligarMoedas`): é `type=text`, e isso é
  decisão. O `type=number` do navegador ACEITA `e`, `E`, `+` e `-` e, com
  qualquer um deles dentro, devolve `.value` **vazio** — o formulário mandava
  `null` para um campo preenchido, sem nada na tela dizendo isso. Pior: ele não
  expõe `selectionStart`, então não há como recusar UM caractere sem apagar o
  resto. O filtro roda no `beforeinput`, o único evento que alcança teclado,
  colar e arrastar antes de o valor mudar. `Modal.pedacoMoeda` normaliza o que
  entra: sobrando mais de um separador, o **último** é o decimal e os outros são
  de milhar (é o que distingue `1.234,56` de `1,50`, e sem isso colar um valor
  de planilha esvaziava o campo); o sinal de menos recusa o texto **inteiro**,
  porque ele é parte do número e não enfeite como o "R$" — colar `-99` e ver
  `99` seria o campo mentindo sobre o que recebeu. A tela é pt-BR (vírgula) e
  `coletar()` devolve número; vazio é `null`, nunca zero. No cartão, o valor sai
  sempre com **dois centavos** (`minimumFractionDigits: 2`): `toLocaleString`
  sozinho corta o zero à direita, e `R$ 1.500,50` aparecia como `R$ 1.500,5`.
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
  **Todo campo de texto do modal tem o mesmo par de controles**: a **seta** de
  ver mais/ver menos no canto superior direito (`.linha-rotulo` /
  `.campo-ferramentas`) e a **alça** de arrastar a altura no canto inferior
  direito (`.alca-campo`, `Modal.ligarAlcas`). A alça é NOSSA, movida por
  eventos de ponteiro: a nativa (`resize: vertical`) **não existe no celular** —
  o iOS não a desenha nem responde ao arraste —, e era justamente lá que o campo
  apertava. Por isso o `resize` nativo fica **desligado**: duas alças no mesmo
  canto deixariam a de baixo inalcançável. A altura arrastada MANDA a partir
  dali (`data-ajustado`, mais `altura-manual` no bloco, que desliga o
  esticamento do flex do modal), e **"ver menos" a desfaz** — é a única saída de
  quem esticou demais.
  O mesmo par vale para o **bloco de leitura** (`info`): a seta abre o cartão
  inteiro (`.info-aberto`) e a alça arrasta a altura do corpo. Lá a seta só
  aparece quando o conteúdo **realmente** não cabe (medido em
  `marcarInfoRolavel`, com o modal já na tela) ou quando ela própria abriu o
  bloco — seta que não faz nada ensina a ignorar a seta.
  Com **`maxLinhas`** o campo nasce COMPACTO (as `rows` declaradas), cresce até
  esse teto e ali passa a rolar; a seta troca o teto pelas mesmas 60% da tela.
  Sem `maxLinhas`, a seta leva o campo direto às 60% (quem a toca quer espaço
  para escrever, e um campo que só cresce com o que já foi digitado não daria
  nenhum) e "ver menos" devolve o padrão do CSS. A seta é o MESMO caractere `▾`/`▴`
  com que as frentes de trabalho e os projetos abrem e fecham um bloco
  (`.seta-projeto`) — ícone próprio aqui ensinaria um segundo vocabulário para o
  gesto que o sistema inteiro já escreve assim; e ela VIRA ao abrir, senão o
  mesmo desenho diria as duas coisas.
  O **microfone não sobe** com ela: fica dentro da caixa, no canto inferior
  direito, como em todo campo de texto do sistema (decisão do cliente; um lugar
  só neste formulário seria um segundo padrão). Ele desvia da alça de
  redimensionar **pelo lado** (`right: 1.6rem`, 2.2rem no celular, onde a alça é
  maior), não pela altura: subir era o que se fazia antes e quebrou quando o
  campo passou a nascer com UMA linha — 1.6rem de fundo mais 1.75rem de botão
  passam da altura inteira, e o microfone saía por cima da borda. O desvio e o
  recuo do texto são condicionados a `:has(> .alca-campo)`: campo sem alça
  (a tela do participante) não pode perder essa faixa à toa. E o recuo repete o
  id — `#modal-campos .campo-voz:has(> .alca-campo) > textarea.form-control` —
  porque `#modal-campos .form-control` tem um id e vence qualquer regra só de
  classes: sem repeti-lo, a última linha digitada passava por baixo do
  microfone dentro do modal, e só ali.
  O botão muda o TETO, nunca a altura: escrevê-la direto criaria duas fontes
  para a mesma medida e a tecla seguinte desfaria a expansão. E ele limpa o
  `data-ajustado`, senão não teria efeito nenhum em quem já esticou o campo pela
  alça — que é justamente quem mais mexe nele.
  Duas armadilhas de medida já pagas: o Chrome conta o texto de **exemplo** no
  `scrollHeight`, então o campo nasce com duas linhas quando o exemplo quebra
  (é o certo — com o piso rígido, a segunda linha do exemplo ficaria escondida
  atrás de uma barra de rolagem no campo VAZIO); e a regra genérica
  `> div:has(textarea) > textarea.form-control` **vence** um
  `textarea[data-max-linhas]` sozinho na especificidade (o `:has` soma um tipo),
  por isso existe o seletor longo repetindo o atributo nos dois lados — sem ele
  o campo nascia com o dobro do tamanho, que ninguém chama de erro.
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
  **Ao logar, o ano da análise é o `ano_inicio` do ciclo em uso** (2027 no
  ciclo 2027–2035), não o ano do relógio — pedido do cliente em 2026-09-03: o
  `ano_base` é o de diagnóstico, e o preenchimento é do primeiro ano planejado.
  O que o usuário escolher no seletor vale até fechar a página
  (`Diag.anoSelecionado` não é persistido); prova em `provasAnoPadrao`.
- Promoção PESTEL/Porter → SWOT copia o `ano`; o botão do fator promovido
  mostra a categoria SWOT na cor do quadrante e reabre a edição.
- **Duas telas juntas** (`planejamento_versao`, `App\Core\Versao`,
  `public/assets/js/vivo.js`): mais de um ADMIN preenchendo ao mesmo tempo, e o
  que um grava aparece no outro em ~4s sem ninguém apertar F5. Nasceu do custo
  real de uma reunião com a direção, onde "atualiza aí" se repete dezenas de
  vezes.
  **O pulso é um contador por planejamento**, não `MAX(atualizado_em)`: a maioria
  das tabelas não tem carimbo, e as que têm não registram DELETE — apagar um
  fator não mexeria em carimbo nenhum e a outra tela seguiria mostrando o que já
  não existe. `GET /api/pulso?ciclo_id=` devolve `{versoes, bloqueios}` e é
  **a rota mais chamada do sistema** quando há gente preenchendo junto: por isso
  lê uma tabela de duas colunas e nada mais.
  **A marcação tem duas metades, cada uma num ponto de passagem obrigatório**, e
  é isso que a torna impossível de esquecer: `Auth::exigirEdicaoPlanejamento`
  diz QUAL plano (é o portão de toda escrita de conteúdo) e `Database::executar`
  diz que HOUVE escrita (é o único caminho de INSERT/UPDATE/DELETE). O contador
  sobe uma vez por requisição, num `register_shutdown_function` — o fim do
  switch nunca é alcançado, porque `Json::ok()` termina com `exit`. Chamar
  `bumpar()` em cada endpoint foi rejeitado: esquecer um não quebra nada
  visível, a pessoa aperta F5 e o defeito fica.
  **A exceção é uma só e é explícita:** `ImpactoController::salvar` chama
  `Versao::alvo()` na mão, porque autoriza pelo NEGÓCIO da célula e não pelo
  planejamento. Os cadastros (ciclo, negócio, usuário, driver, eixo) ficam fora
  de propósito — mudam a moldura, não o conteúdo de um plano.
  **`Vivo.armar` é chamado num lugar só**, em `App.recarregarSecaoAtiva`, DEPOIS
  de `carregar()` resolver: armar antes capturaria a referência com a tela ainda
  lendo, e uma escrita nesse intervalo passaria por "já vista". Um relógio só em
  todo o sistema — há uma seção visível por vez, e um por seção deixaria batendo
  o da tela que esqueceu de desarmar.
  **As guardas são o coração disso** e vieram do relógio da Sala, que já rodou em
  oficina: não repinta com **modal aberto** (descartaria o formulário) nem com o
  **foco num campo**; desarma quando a seção ganha `d-none`; nem arma em
  `App.modoDossie`; e ignora a batida se a rede piscar. Represar não perde a
  atualização: a versão de referência só avança quando a repintura acontece, e a
  batida seguinte à liberação traz o que ficou. Seções com relógio próprio
  (`coleta`, `sala`) e o `dossie` declaram `planosVigiados() { return []; }`;
  a `impacto` declara DOIS planos, porque é lida no contexto de um negócio e o
  conteúdo dela vive no corporativo.
- **Um item por vez: o cadeado de edição** (`edicao_bloqueio`,
  `App\Services\Bloqueio`, `public/assets/js/cadeado.js`). Continuação do
  pulso: duas telas se acompanharem não impede duas pessoas de abrirem o MESMO
  item e a segunda a salvar apagar o trabalho da primeira. Enquanto um admin tem
  o item aberto, ninguém mais grava nele. **5 minutos**, contador dentro do
  formulário e **"+1 minuto" manual, sem teto** — a renovação automática foi
  descartada porque *um batimento prova que o navegador está aberto, não que
  existe uma pessoa ali*: uma aba esquecida renovaria para sempre, que é o caso
  que o batimento deveria cobrir.
  **O ciclo de vida mora num lugar só:** `Modal.abrir({ bloqueio: { recurso,
  registro_id, planejamento_id } })` toma antes de pintar, solta no
  `hidden.bs.modal` e desenha a faixa. As seções passam o par e não cuidam de
  nada — quem esquecesse de soltar prenderia o item por cinco minutos.
  **`Versao::ignorar()` nas rotas do cadeado, e é obrigatório:** tomar e renovar
  escrevem, e o pulso marca escrita na infraestrutura; sem a exceção, cada
  renovação subiria a versão e todas as telas repintariam a cada 4 s.
  **`GREATEST(expira_em, NOW()) + 60`, nunca `NOW() + 60`:** o segundo
  ENCURTARIA um cadeado recém-tomado — o botão de ganhar tempo tirando tempo.
  **Aos 0:00 o cadeado cai, o texto não:** `exigirMeu` volta calado quando o
  item está `livre`, então quem perdeu o cadeado ainda grava se ninguém assumiu.
  Um cadeado vencido pode ser assumido por **qualquer admin** (decisão do
  cliente). **Falha ABERTA em todo o caminho do navegador**: um sistema de
  cadeados capaz de impedir todo mundo de trabalhar é pior que a sobrescrita que
  ele previne. Alcance: `fator`, `cenario_item`, `cascata_escolha`, `projeto` e
  `desdobramento` — os disputados de verdade numa reunião. O nome de quem edita
  aparece no item dos outros via `data-cadeado="recurso:id"` + `Vivo`; o
  atributo leva o RECURSO junto porque `data-card-fator` sozinho casaria um item
  de cenário nº 5 com o fator nº 5.
- **Matriz de Impacto por Negócio** (`impacto_negocio`, `ImpactoController`,
  `secoes/impacto.js`): o que o diagnóstico CORPORATIVO faz com cada negócio.
  Linha = ameaça/oportunidade da SWOT corporativa do ano, **sem curadoria
  própria** (a SWOT já é a lista curada e o GUT já é a priorização, então a
  ordem é `ORDER BY g.score DESC`); coluna = negócio; célula = sinal + como.
  A tabela **não tem `planejamento_id` nem `ano`** — os dois vêm do fator, e
  guardá-los criaria uma célula que discorda da própria linha.
  **Duas telas, uma tabela:** contexto Corporativo → grade; contexto de um
  negócio → a lista da coluna dele, com a contagem. A segunda é o motivo de a
  tela existir.
  **A autorização é a exceção única do sistema** (decisão do cliente 2026-09-01,
  escrita em `PLANEJAMENTO-SISTEMA.md §5`): GESTOR **lê** a descrição dos fatores
  corporativos e as células dos negócios dele — com o `score` da GUT removido do
  payload, não só da tela — e **grava** a célula dos negócios dele. Isso é seguro
  por modelagem, não por exceção: **a célula pertence à MATRIZ, não ao plano
  corporativo**, e só cita um fator. Por isso a regra é a do NEGÓCIO ("você mexe
  na célula de um negócio que você já mexe"), e o `fator_id` é conferido contra o
  plano corporativo do ciclo — ninguém cria linha pela borda. Nenhum dos dois
  métodos usa `exigirAcessoPlanejamento` no corporativo: ele devolve 403 ao
  gestor, que é justamente quem mais precisa da coluna dele.
  **Sinal é FORMA (▲/▼), não só cor** — cor sozinha não sobrevive ao daltonismo
  nem à impressão em P&B, e a tela vai impressa à reunião. Célula ausente já
  significa "sem impacto relevante": não existe `NEUTRO`, e `sinal` vazio apaga.
  **Cabeçalho grudado com outra solução que a Matriz de Execução:** aqui a tabela
  precisa de ~1450px, então a caixa não pode parar de rolar — ela ganha
  `max-height` e vira o container, e o `sticky` usa `top: 0` (o topo da caixa).
  A coluna do fator é `sticky left`, e a quina precisa de `z-index` acima das
  duas. `border-collapse: separate` pelo mesmo motivo da outra grade.
- **Mover um fator de análise** (`⇄`, `FatorController::mover`): PESTEL ⇄ Porter
  ⇄ SWOT. **A etapa e a categoria andam juntas, sempre** — as listas não se
  correspondem, e herdar a antiga produziria um fator invisível nas duas telas
  (a SWOT filtra por categoria dela, o PESTEL por etapa), que é o mesmo defeito
  que o `salvar()` já corrigiu por outro caminho. O modal tem **um campo de
  categoria por destino**, revelado por `visivelSe`: com um campo repintado,
  trocar de destino e voltar perdia a escolha já feita.
  **SEIS amarras RECUSAM o movimento**, cada uma dizendo o que desfazer: já
  virou ação (a mesma `Fatores::acoesQuePrendem` da exclusão — uma definição só
  de "está preso"), promoção nos **dois** sentidos, nota na Matriz GUT (que é da
  SWOT), citação num cruzamento (o par escolhe interno × externo por quadrante),
  **célula na Matriz de Impacto** e **vínculo com a Cascata**. As duas últimas
  entraram depois e não por simetria: elas perdiam dado **em silêncio**. Saindo
  da SWOT, as células do Impacto continuam no banco e somem da grade (ninguém
  apaga nada e ninguém consegue mais ler); o vínculo da Cascata é pior porque
  demora — a célula segue exibindo o fator, mas o `salvar` dela só reinsere
  fatores da SWOT, então o **próximo salvamento da mesma célula**, feito por
  outra pessoa e por outro motivo, derruba o vínculo. Os motivos chegam à tela
  em `mover_trava` (array — as amarras se acumulam) pela **mesma** consulta da
  recusa, e o `⇄` já nasce desabilitado com todos eles no `title`.
  **Promover ≠ mover:** promover COPIA (a origem fica no PESTEL, o par visível
  nas duas telas), mover TRANSFERE; os dois são legítimos, mas não no mesmo
  fator — e é por isso que a promoção trava o `⇄`.
- **Mover ATRAVESSANDO a tabela** (Cenário ⇄ fator): o quarto destino do `⇄`,
  e o `⇄` novo no cartão do Cenário (`CenarioController::mover`). Entre análises
  mover é `UPDATE fator SET etapa` — o id não muda, e por isso nada mais precisa
  mudar. Para o Cenário o **id morre**: é outra tabela, e o que ele sustentava
  vai à mão. **A ordem é a garantia, no lugar da transação** (o repositório não
  usa `beginTransaction`): cria o destino, leva as vozes (`Quiz::mudarDestino`),
  e só então apaga a origem — morrendo no meio, o pior caso é registro repetido,
  visível e apagável, e nunca voz apontando para id morto.
  **As vozes da sala VIAJAM** em vez de voltarem à fila: o item já existe no
  destino, e triar a ideia de novo criaria um segundo registro dizendo a mesma
  coisa. Isso obrigou a apertar o "solta quem saiu do conjunto" dos dois
  `vincularSugestoes` com um `JOIN quiz_pergunta`: a voz carregada veio de uma
  pergunta de outro alvo, nunca aparece no painel do destino, e a primeira
  edição do item a soltava calada — perdendo exatamente o que a travessia
  preservou. O catálogo de categorias mudou de casa por causa disto
  (`FatorController` → `Fatores::CATEGORIAS`): duas telas criam fator agora.
  Salvando, a tela **leva a pessoa até o item novo** (`Diag.irParaFator`) —
  cartão que some sem dizer para onde foi é indistinguível de cartão excluído.
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
  refeito a cada batida do polling. Uma seção pode pedir **tabela em vez de
  lista** declarando `colunas` (e `detalhe` no item): é o que os Cruzamentos
  usam, porque o item ali são dois lados de peso igual — o par e a estratégia.
- **Dossiê do plano** (`public/assets/js/secoes/dossie.js`): as etapas em
  sequência, por negócio, num documento só. O caro nunca foi imprimir — é que
  **só existe na tela a seção ativa**: as outras `#secao-X` estão vazias até
  alguém abri-las. O dossiê pinta cada etapa **de lado** e tira a **foto** do
  `innerHTML` dela. A foto é inerte por construção (atribuir `innerHTML` não
  carrega ouvinte), então a cópia não age e a tela viva fica intocada; e é o que
  permite onze negócios no mesmo documento, já que as seções são elementos FIXOS
  no shell. **Não** se montou uma tela que desenha o documento do zero: seria a
  segunda cópia do desenho de cada análise, que é o que `RelatorioAnalise`
  existe para evitar. Três coisas que a pintura de lado exige:
  - **`App.modoDossie`** — bandeira lida por `QuizSala.armarRelogio`, para que a
    pintura não arme relógio de polling que só se desarmaria na batida seguinte.
  - **A vista é zerada e devolvida.** Filtros e recolhimentos moram na seção e
    sobrevivem à repintura (`Diag.busca`, `Diag.filtroMovel`,
    `SecaoProjetos.filtroStatus`, `projetosFechados`). Sem zerá-los, quem
    tivesse "atrasado" no filtro de Projetos levaria ao Conselho um plano em que
    só existem projetos atrasados — **e nada na folha diria que houve filtro**.
    O `finally` devolve a vista de quem clicou, e o contexto do menu junto.
  - **Os `id` saem da foto.** Ela duplicaria todo id da seção, e a partir daí um
    `getElementById` poderia cair na cópia morta — defeito que só apareceria
    depois, longe dali.
  No papel, dentro de `.dossie-doc` a regra é a **geral** ("comando nenhum"),
  com duas exceções declaradas que são conteúdo desenhado como botão: o par do
  cruzamento e o "Virou ação ↗". A lista por atributo do `@media print` nomeia
  as telas uma a uma e por isso fica atrás de cada tela nova; ali, onde tudo é
  cópia inerte, a regra pode ser a larga. Ficam de fora do dossiê a Coleta
  (mostra uma `situacao` por vez, sem visão "todas"), o Painel e o Hub (são do
  ciclo, não do negócio) e o Relatório de Status (é o outro documento).
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
  **O painel de vozes da sala mora DENTRO do fixo** (o `<thead>` do
  `RelatorioAnalise.canvas`), nas quatro análises e nos Cruzamentos. Ele já
  ficou fora, para não engolir a tela numa oficina cheia — e o resultado foi o
  relato do cliente (2026-09-03): o cabeçalho grudava e o painel rolava para
  fora, e quem conduzia a reunião perdia de vista o que a sala respondia. Dentro
  do fixo, o `ResizeObserver` já o mede junto, e a grade de vozes tem **teto
  (30vh) com rolagem própria** — é isso que segura o crescimento, não o lugar.
  O "Recolher" continua para quem quer só os cartões; na impressão o painel não
  vai. Prova: `provasPainelSalaFixo`.
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
- **Fila de tratativa da Coleta** (sem rodada aberta): um card por vez, na
  ordem de chegada, com Pular. Desde 2026-09-02 cada cartão de "A tratar" da
  lista tem um **Tratar** que puxa a ideia para a fila fora da ordem (pedido
  do cliente); o cartão puxado ganha o selo "na fila" e perde o botão, e
  depois de tratada ou pulada a fila volta à ordem. O foco (`foco`) e o
  Pular (`pulados`) vivem só na sessão, porque a fila recarrega do servidor
  a cada ação. Prova em `sistema.js` (`provasTratarForaDaOrdem`).
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
  **A avaliação é G, U e T — e nada mais.** A quarta pergunta, o *esforço* de
  enfrentar a ameaça (`gut.esforco`, ENUM PEQUENO/MEDIO/GRANDE), **saiu** por
  decisão do cliente: quase ninguém estimava — a coluna vivia de traços —, a
  pergunta alongava um formulário que existe para ser respondido em segundos, e
  P/M/G aparecia na mesma tela com dois sentidos opostos (vermelho no score é
  "tratar agora"; no esforço era "caro de resolver"). Hoje a tela tem **uma
  leitura só**.
  A coluna virou **Prioridade**: a LETRA da faixa do score (`seloFaixa`,
  `.selo-faixa`, selo redondo), sempre preenchida quando há avaliação — P (<27,
  verde), M (27–63, dourado), G (≥64, vermelho), exatamente as faixas da barra
  da legenda. Quem produz a letra, a cor e o corte é **uma fonte só**
  (`SecaoGut.FAIXAS` → `faixaDoScore`): escritos separados, o selo e a legenda
  divergiriam na primeira revisão de paleta ou de limite, e a legenda passaria a
  explicar uma cor que a tabela não usa. **Sem avaliação não se pinta nada**:
  fica o traço (`.selo-faixa.vazio`), porque cor ali afirmaria uma prioridade
  que ninguém deu. A ordenação é só por score — o desempate por esforço
  (`pesoEsforco`) saiu junto com a estimativa.
  A coluna do banco **fica onde está, com as estimativas antigas**: nada foi
  apagado, e `FatorController::gut` só toca em `esforco` quando o corpo
  **declara** o campo (`array_key_exists`). Sem essa guarda, reabrir e salvar
  uma avaliação já feita apagaria calado o que tinha sido estimado antes — o
  `?? ''` vira NULL e `VALUES(esforco)` o grava.
  O que cada letra significa mora no **ⓘ** da legenda (chave `PMG`,
  `Diag.ligarOrientacoes`, o mesmo padrão das análises) e no `title` de cada
  selo — não num parágrafo acima da tabela, que custava uma faixa inteira da
  tela.
  **Ponto aberto medido, não resolvido**: sobre branco, o verde `#007a45` dá
  5,43:1 e o vermelho `#8f3b3b` 7,35:1, mas o dourado `#b08d4f` dá **3,10:1** —
  abaixo do 4,5:1 da WCAG AA para texto pequeno. Hoje a letra e a faixa
  numérica carregam a informação sem depender da cor, então nada fica
  ilegível; numa revisão de paleta o dourado deve subir (`#8a6a2a` dá 5,03:1 e
  mantém o tom).
  O cabeçalho da seção (`.cabecalho-gut`) e o `<thead>` da tabela **grudam**
  abaixo da topbar, em degraus: `--topo-app` e `--altura-cabecalho`, medida por
  `Diag.ligarCabecalhoFixo` (o mesmo helper das análises). Rolando o ranking,
  G, U, T, Score e Prioridade saíam de vista e viravam cinco colunas anônimas.
  A tabela **não** usa `.table-responsive`: `overflow-x: auto` faria da própria
  caixa o scrollport do `sticky`, e o cabeçalho grudaria numa caixa que nunca
  rola na vertical — ou seja, nunca. Ela só aparece a partir de `md`, onde
  cabe; no celular são cartões, cada um com os próprios rótulos, e ali nada
  gruda (o título quebra em duas linhas e custaria um quinto da tela).
- Metas plurianuais versionadas: `indicador_valor` única por
  (indicador, ano, tipo, versão); leitura usa a MAIOR versão de cada ano.
- **Qual par meta × real se mostra** é `SecaoMetas.metaReal`, e existe **uma vez
  só**: o ano de referência é o do ÚLTIMO real lançado e, sem real nenhum, o da
  PRIMEIRA meta. Não é "o ano corrente" — o ciclo semeado vai de 2027 a 2035 e as
  metas começam em 2027, então em 2026 a regra do ano corrente deixaria toda linha
  em "—". A Matriz de Execução chama a mesma função: duplicá-la faria duas telas
  vizinhas dizerem números diferentes do mesmo indicador.
- **Matriz de Execução** (aba da Cascata): por eixo, a escolha com a renúncia, os
  indicadores que a medem, o par meta × real de cada um e os projetos que a
  executam. Os dois lados do vínculo são `indicador_cascata` (N:N, clone de
  `cascata_fator`) e `projeto.cascata_id`. **Não é um mapa estratégico**: não há
  raias com setas nem `objetivo_estrategico` — a caixa é a própria
  `cascata_escolha`, que já traz a renúncia (que o BSC não tem) e os fatores
  SWOT/GUT que a fundamentam; as raias são os eixos já cadastrados. A linha da
  tabela é o INDICADOR (a escolha e as iniciativas ganham `rowspan` sobre ele),
  senão o nome do KPI e o número dele desalinham na primeira quebra de linha.
  Um horizonte por vez, com seletor: os três dariam mais de cem linhas.
  `IndicadorController::gravarCascatas` confere **cada escolha** contra o
  planejamento — `Auth::exigirEdicaoPlanejamento` valida o planejamento, não os
  filhos —, e só toca a tabela quando a chave `cascatas` vem no corpo: tratar a
  ausência como "lista vazia" apagaria vínculos que ninguém mandou apagar.
  **A aba é LEITURA**: as três colunas da direita não se preenchem ali, e o
  bloco `.como-amarrar` (`SecaoCascata.comoAmarrar`) diz o campo exato de cada
  uma e leva à tela — a frase solta que existia antes era lida como legenda, e a
  pergunta "como eu amarro isto?" veio mesmo com ela na tela. Editar daqui
  exigiria repetir os dois formulários na matriz, e duas telas gravando o mesmo
  vínculo divergem na primeira mudança.
  **Cabeçalho grudado** (`top: var(--topo-app)`, como Projetos e as análises),
  com duas condições que não são detalhe: (1) `.caixa-execucao` só deixa de
  rolar acima de 992px — `overflow-x: auto` faz o `overflow-y` computar `auto`
  junto, e um `sticky` dentro de um container de rolagem gruda no topo DELE, que
  sai da tela com a página; o corte de 992 saiu de medir (a tabela tem 911px
  intrínsecos, a caixa fica em 944), não de somar os `min-width`. (2) a tabela
  usa `border-collapse: separate` — com `collapse` os fundos pertencem à tabela
  e o TEXTO das `<td>` atravessa o cabeçalho grudado, e `z-index` na célula não
  resolve; o preço é redesenhar a grade do `table-bordered` com borda direita e
  de baixo por célula.
- **Governança de investimentos** (`investimento`, `envelope_capital`,
  `InvestimentoController`, `secoes/investimentos.js`): o funil é **envelope**
  (quanto há) → **papel** (agrupa antes de ordenar: OBRIGATORIO, MANUTENCAO,
  EFICIENCIA, CRESCIMENTO, ESTRATEGICO) → **ranking** por taxa de retorno →
  **decisão** com critério escrito → **auditoria +12M** (prometido × realizado).
  A situação nasce sozinha: com taxa de retorno informada entra `RANQUEADO`,
  sem ela `PROPOSTO`.
  As mudanças de situação passam por `TRANSICOES`, uma tabela explícita, e não
  por um conjunto de estados intercambiáveis — era por ali que vazava o que a
  regra proíbe: um PROPOSTO pulava direto a EXECUTADO sem decisão nenhuma, e um
  EXECUTADO voltava a PROPOSTO e **sumia do comprometido do painel**. Decidir e
  auditar têm ações próprias (`POST /api/investimentos/{id}/decidir` e
  `/auditar`), e `decidir()` repete a guarda pela mão: sem ela um item já
  EXECUTADO podia ser reprovado, o valor saía do comprometido e o envelope
  mostrava folga inexistente. O critério da decisão é **obrigatório** — "a
  cascata dá direção, não aprovação".
  **O envelope é um por horizonte** (`planejamento_id` + `horizonte_id`), e por
  isso `salvarEnvelope` recusa mover o envelope A para o horizonte de B: sem a
  recusa, editar um sobrescreveria o outro em silêncio.
  Duas coisas a saber antes de mexer: **`flex_percentual` é declarativo** — a
  tela o mostra como `±N%` ao lado do limite, mas nada no servidor o usa, e a
  barra satura em 100% do `valor_limite`; e **o "comprometido" está escrito em
  três lugares** (`investimentos.js`, e duas consultas do
  `RelatorioController`), sempre como `situacao IN ('APROVADO','EXECUTADO',
  'AUDITADO')`. É a duplicação que este projeto normalmente extrai para um
  serviço; ela sobreviveu porque nasceu antes da regra, e mudar o conjunto sem
  mudar os três faz o relatório e a tela discordarem sem ninguém errar nada.
- **Ata de reunião** (`reuniao`, `RelatorioController::listarReunioes` /
  `salvarReuniao` / `excluirReuniao`, `/api/reunioes`): data, período coberto,
  participantes, decisões e próximos passos, presa ao planejamento. O servidor
  exige as três datas com `periodo_ate >= periodo_de` e **exige as decisões** —
  ata sem o que foi decidido é lista de presença, não registro. O `autor_id` é
  **anulável de propósito**: excluir um usuário não pode levar junto o que ele
  escreveu, então na exclusão a ata vai para a pessoa indicada ou fica sem
  vínculo e a tela mostra «Sem usuário» (grupo `autoria` de
  `UsuarioController::excluir` — o mesmo contrato dos comentários).
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
  (`POST /api/negocios/{id}/excluir`) só vale para negócio **ainda sem uso em
  cadastro nenhum** — o ✕ aparece (ativo ou não) apenas quando `excluivel`,
  calculado no `listar()` do servidor: sem planejamento, sem escopo de usuário
  e fora da lista oficial. Quem já foi atribuído em algum lugar só se desativa.
  O servidor recusa três vezes, e as recusas existem para não iludir quem
  clica: negócio **com planejamento** (a FK é RESTRICT — o DELETE morreria, e
  junto iria o diagnóstico do negócio), **com escopo de usuário** (o CASCADE
  apagaria o vínculo em silêncio) e **código da lista oficial** (a
  sincronização recriaria a linha no deploy seguinte). O migrate faz a faxina
  dos inativos que sobraram de carga antiga, com as mesmas guardas.
- Excluir um fator de PESTEL/Porter/SWOT remove também o promovido para a SWOT
  e a linha correspondente na matriz GUT (`FatorController::excluir`).
- **Pesquisa dentro da análise** (`Diag.campoBusca` / `Diag.ligarBusca`): campo
  no cabeçalho fixo das **seis** telas do diagnóstico — Cenário, PESTEL,
  Porter, SWOT, Matriz GUT e Cruzamentos —, que esconde os itens que não casam
  com o texto digitado. **São quatro renderizadores para ligar**, não um:
  `Diag.etapaFatores` (PESTEL e Porter), `SecaoSwot`, `SecaoCenario`,
  `SecaoGut` e `SecaoCruzamentos`. Mexer em um e esquecer o outro é o defeito
  natural aqui, e foi cometido na primeira tentativa — PESTEL e Porter ganharam
  o campo e a SWOT, que era o pedido, não. O motor é um só, parametrizado:
  - `itens`: o seletor da unidade pesquisável (`[data-card-fator]` por padrão,
    `[data-card-cruzamento]` nos Cruzamentos);
  - `aposFiltrar`: gancho para quem tem "ver mais" PRÓPRIO — os Cruzamentos
    expandem o cartão inteiro por um botão só, que o helper genérico não
    alcança, e caixa escondida mede zero;
  - `Diag.textoBusca` decide o que é varrido: `[data-busca-texto]` primeiro (a
    LINHA da Matriz GUT marca só a descrição — varrer a linha inteira faria "5"
    casar com nota, score e ranking), depois `.texto-fator` e
    `.selo-cruz-texto` (nos Cruzamentos são os DOIS lados do par mais a
    estratégia), e o texto do item como último recurso;
  - a contagem é por **id do registro**, não por nó: a GUT desenha a mesma
    avaliação duas vezes (tabela no computador, cartões no celular, com o mesmo
    `data-card-fator`), e contar nós diria "12 de 48" onde há 24 fatores;
  - sem colunas para contar (a GUT), o vazio é anunciado por
    `[data-busca-vazio-geral]`; com colunas, por `[data-busca-vazio]` em cada.

  O que não pode mudar sem motivo:
  - filtra a TELA, não o dado. O relatório (`RelatorioAnalise`) é montado a
    partir de `fatores`, e continua saindo com a análise inteira: ele é o
    documento da análise, não a vista de quem está procurando;
  - o texto de cada cartão é lido UMA vez, na ligação. Reler o DOM a cada tecla
    varreria também os selos, e buscar "gut" casaria com todo cartão que tem
    score — resultado plausível e errado;
  - o `d-none` da busca vai no **cartão**; o do filtro de categoria do celular
    vai na **coluna**. No mesmo elemento, um desfaria o outro;
  - `ligarVerMais` roda de novo ao fim de cada filtragem: `scrollHeight` de
    elemento escondido é zero, e o cartão que reaparece precisa ser remedido;
  - o estado é por etapa (`Diag.busca`, como `filtroMovel`), e `irParaFator` o
    limpa — quem foi MANDADO a um card tem de vê-lo, e cair num "nenhum fator
    encontrado" pareceria registro apagado;
  - o contador do quadrante vira `visíveis/total` durante a busca. Só o número
    dos visíveis faria parecer que fatores sumiram do plano.
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
  **A etapa tem sala** (o 🎤 por bloco, `alvo_tipo = 'CRUZAMENTO'`): a direção
  propõe o PAR pelo celular e o condutor aceita. O "Usar" abre o formulário com
  o par JÁ escolhido pela pessoa e a estratégia dela como rascunho — aceitar é
  ato de quem conduz, e o texto final é dele. A voz fica amarrada por
  `destino_tipo = 'CRUZAMENTO'`, com a mesma guarda de alvo das outras telas, e
  é apagada junto se o cruzamento for excluído (`Quiz::excluirVozes`). O cartão
  ganha o selo 🎤 com quantas vozes o sustentam. A ficha do painel mostra o
  **par** acima do texto (`QuizSala.parDaVoz`), que é o que o condutor lê para
  decidir; o lado cujo fator foi excluído aparece como tal em vez de sumir.
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
- **Excluir o que está amarrado: o aviso vem ANTES do clique.** O × fica
  **desabilitado** onde o servidor vai recusar (fator, cruzamento e ideia da
  Coleta que viraram ação), com o motivo e o que fazer no `title`, e sem o
  `data-excluir` pendurado. Quem decide continua sendo o servidor — isto é o
  aviso, não a guarda. Duas armadilhas medidas: a marcação **tem de sair da
  mesma consulta da recusa** (`Fatores::acoesQuePrendem`), senão erra no
  promovido e no cruzamento, que são os casos comuns; e o Bootstrap põe
  `pointer-events: none` em todo `.btn:disabled`, o que esconderia o `title` —
  `.btn[aria-disabled="true"]` devolve o ponteiro sem destravar o clique, que
  continua bloqueado pelo atributo `disabled`.
  O `confirm()` diz **o que sai junto, com números**, montado por
  `public/assets/js/vinculos.js`: `Vinculos.aviso()` separa o que **some** do que
  **continua existindo sem o vínculo** (o comentário some; o investimento sem
  projeto continua sendo um investimento), e `Vinculos.quantos()` devolve vazio
  no zero — sem isso a frase saía "Sai junto: .". Os números vêm de contagens
  agregadas nas listagens que a tela já busca, **nunca de uma chamada por
  cartão**. Uniformizou-se o **aviso**, não a regra: recusar, cascatear e soltar
  o vínculo continuam sendo respostas diferentes para relações diferentes.
- **Quiz — a sala do PROJETO** (`coleta_rodada.modo = 'QUIZ'`): a MESMA sala da
  tempestade — PIN, token, tetos, trava de força bruta — servindo a TODAS as
  análises. **Um PIN para o encontro inteiro**: o participante escaneia uma vez
  e o celular acompanha a tela que o condutor abre. Uma pergunta ativa por vez
  (`quiz_pergunta.situacao` é a única fonte da verdade; não existe coluna
  "pergunta ativa" na rodada).
  A pergunta tem **alvo polimórfico** (`quiz_pergunta.alvo_tipo`):
  `CASCATA` (célula driver×horizonte×eixo), `CENARIO` (ano), `FATOR` (ano +
  etapa + categoria), `CRUZAMENTO` (ano + o BLOCO do TOWS, guardado em
  `categoria`) e `LIVRE` (a tempestade dentro do roteiro). Colunas nulas
  por tipo — e por isso a unicidade é a coluna gerada `alvo_chave`, que junta
  todas elas: NULL nunca colide com NULL num UNIQUE comum, e a mesma célula
  entraria duas vezes no roteiro.
  **O alvo `CRUZAMENTO` é o único em que o celular ESCOLHE registros**, e não
  só escreve: a pessoa marca dois fatores da SWOT (um de cada lado do bloco) e
  escreve a estratégia do encontro. Foi decisão do cliente — a alternativa era
  a sala escrever a estratégia de um par montado pela condução, que reaproveita
  tudo e não mexe na rota pública. Como a escolhida faz a **única escrita sem
  login do sistema aceitar ids de registro**, três coisas passaram a valer:
  a regra do par mora em **`App\Services\Cruzamentos::parValidado`** e é a
  MESMA dos dois lados (com login e sem — duas escritas divergiriam, e a frouxa
  seria a exposta); o `planejamento_id` vem da RODADA, nunca do corpo; e o
  bloco derivado do par tem de ser o bloco PERGUNTADO, senão a pergunta "Forças
  × Oportunidades" aceitaria força com ameaça e o painel encheria de resposta
  fora do assunto. O par viaja em `coleta_item.fator_interno_id`/
  `fator_externo_id` (FK **SET NULL**: apagar um fator não pode apagar o que
  alguém escreveu na oficina), e as duas listas descem ao celular por
  `Cruzamentos::doQuadrante`, que devolve só id e descrição — a decisão do que
  expor numa tela sem login mora num lugar só.
  O que cada alvo SIGNIFICA (o lado da resposta, o limite de texto, o rótulo, o
  contexto que o celular lê) mora em **`App\Services\Quiz`** — cinco telas
  reescrevendo isso divergiriam na primeira análise nova.
  **O 🎤 da ETAPA INTEIRA** (2026-09-03, pedido do cliente): além do 🎤 de
  cada coluna, o cabeçalho do PESTEL, do Porter e da SWOT tem um 🎤 que abre a
  análise toda — a pergunta é FATOR com `categoria` **NULL** (o front manda
  `alvos: ['']`, que `Quiz::validarAlvos` lê como "toda a etapa"), e é o
  **celular que escolhe a categoria**, em cartões iguais aos do formulário do
  fator, lendo ao escolher a orientação do ⓘ daquela categoria. Quem diz se
  uma pergunta tem lados, e quais, é **`Quiz::ladosDe`** (`escolheCategoria`),
  nunca a tabela `LADOS` sozinha: nessa pergunta os lados são as categorias,
  cada uma com cor, dica e orientação. O celular recebe `escolhe_categoria`
  e **não marca nenhuma por padrão**; o servidor **recusa a resposta sem
  categoria** em vez de "cair na primeira" (a regra dos alvos de dois lados) —
  cair em "Político" mandaria a voz para um quadrante que ninguém escolheu. A
  categoria viaja em `coleta_item.tipo_resposta`, que por isso virou
  **VARCHAR(40)** (o ENUM cresceria a cada análise nova; a lista branca já era
  "derivada do alvo"). O painel do condutor é **uma grade só, em ordem de
  chegada, com a etiqueta do quadrante em cada voz** (`.selo-categoria-voz`;
  já foi uma coluna por categoria, e cinco colunas vazias comiam a faixa fixa —
  o cliente pediu a grade única no mesmo dia); o "Usar" abre o fator com a
  categoria marcada; a guarda de
  `FatorController::vincularSugestoes` aceita a voz dessa pergunta
  (`qp.categoria IS NULL`) para QUALQUER categoria da etapa — a escolha do
  celular é sugestão, o quadrante final é de quem conduz. O catálogo das
  categorias (`Quiz::CATALOGO_CATEGORIA`, servido em `/api/me` como
  `categorias`) é uma cópia do `Diag.CATEGORIAS_ETAPA`/`CORES_QUADRANTE`/
  `DICAS_QUADRANTE`, porque a tela do participante não carrega o `Diag`;
  `provasEtapaNaSala` compara as duas e fica vermelha na primeira divergência.
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
  Quando o destino é apagado sem ser descartado (hoje, só a reclassificação da
  ideia da tempestade), a voz **volta sozinha e JÁ REDIGIDA**: `Quiz::guardarRedacao` guarda o
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
  por um pedido que ia falhar de qualquer jeito); e **excluir um item de
  cenário, um fator ou um cruzamento apaga as vozes do quiz de vez** por
  `Quiz::excluirVozes` (a ideia da tempestade volta a SELECIONADO, na matriz da
  Coleta). Até 2026-09-02 a voz voltava a NOVO e reaparecia no painel como
  sugestão nova depois de o condutor excluir o registro — o cliente viu isso
  numa voz que atravessou do Cenário para a SWOT e pediu a exclusão definitiva;
  `soltarVozes` (voz volta a NOVO, redigida) ficou só para a reclassificação
  da ideia, em que o conteúdo continua noutra análise. O encerra-e-abre é serializado por
  `GET_LOCK` por planejamento: era check-then-act, e dois condutores passavam
  os dois — o segundo encerrando a sala que o primeiro acabou de abrir.
  Plano e decisões: `docs/CASCATA-QUIZ-COLABORATIVO.md`.
- **Questionário prévio da tempestade** (2026-09-03, pedido do cliente): a
  rodada de tempestade pode nascer com **perguntas em ordem**, respondidas
  pelo celular ANTES do encontro (QR ou link do WhatsApp) — o encontro começa
  com as respostas na mesa. Só a tempestade tem isso; as análises (PESTEL,
  Porter, SWOT, Cenário) continuam ao vivo, pelo 🎤. As perguntas são linhas
  **LIVRE de `quiz_pergunta`** na própria rodada, com `ordem` e todas
  `ATIVA` ao mesmo tempo (`Quiz::perguntasDaTempestade`,
  `gravarPerguntasLivres`); a ideia aponta para a pergunta em
  `coleta_item.pergunta_id`, e o **teto `max_ideias` vale POR PERGUNTA**
  (`<=>` no INSERT: sem questionário a pergunta é NULL). Regras do cliente:
  as fichas numeradas deixam escolher qual pergunta responder; "Pular" passa
  sem responder; **atingido o teto, o celular passa sozinho à próxima**; a
  pergunta em que parou fica no `localStorage` por PIN; depois da última, o
  resumo. A rodada tem **`prazo`** e fecha sozinha ao passar dele
  (`Quiz::fecharVencidas`, chamado em toda leitura pública e na lista do
  condutor — não há cron) ou quando o condutor encerra. Perguntas novas só
  entram ao FIM (`POST /api/rodadas/{id}/perguntas`): reordenar trocaria a
  "pergunta 2" que alguém já respondeu. Na Coleta, a ideia leva a etiqueta
  `P2` e o painel da rodada tem o filtro por pergunta; a votação por ★ vem em
  blocos por pergunta. Só conta quem entrou — sem lista de convidados.
  **As ★ do questionário não esperam o condutor** (2026-09-04): com
  questionário, `paraVotar`/`votar` ficam liberados enquanto a rodada está
  aberta (`PublicoController::estrelasLiberadas`), o resumo depois de
  "Concluir" lista as respostas de todos por pergunta com a ★, e o **teto
  `max_votos` conta POR PERGUNTA** (`<=>` no INSERT, como o de ideias). A
  resposta traz `votacao` (a chave da sala, que segue mandando no campo de
  escrever) e `estrelas` separadas — no questionário elas divergem. Sem
  questionário nada mudou: ★ só com a sala fechada. Na Coleta, a **fila vem
  em blocos por pergunta** (a pergunta em cima, as respostas embaixo —
  `SecaoColeta.blocosPorPergunta`; ideia sem pergunta cai num bloco final),
  a bancada abre pela pergunta que a ideia respondeu, e a matriz não repete
  o texto em foco. Provas: funcional 8c e `provasQuestionarioTempestade`.
  **De quem é a resposta, DENTRO da ficha** (2026-09-05, pedido do cliente):
  `SecaoColeta.autorDaFicha` põe o nome na ficha da fila, no título da caixa
  agrupada e em cada palavra dentro dela (`.fn-autor`). O nome já vinha na
  listagem (`COALESCE(a.nome, ci.autor_nome, 'Participante') AS autor`) e já
  era desenhado na bancada, no painel da sala e nos cartões — **na fila ele
  existia só no `title`**, que é dica de MOUSE: no celular, que é onde a fila
  em blocos é lida com a sala em volta, não existe. Quem conduzia tocava em
  cada resposta para descobrir o autor na bancada. Regra do cliente: "o nome
  só serve para identificar de quem é a ideia". O genérico `'Participante'`
  (registro perdido) e a ideia lançada por dentro do sistema **não** repetem
  nome — seria ruído em toda ficha. A cor é própria e não `opacity`: a ficha
  selecionada fica verde, e cinza transparente sobre ela some. Nada mudou no
  celular do participante: **nenhuma rota pública devolve `autor_nome`**, e
  `paraVotar` desce só id, texto, pergunta e `votei` — entre participantes
  ninguém vê o nome de ninguém, e isso não deve regredir.
  **Excluir pergunta** (2026-09-04, pedido do cliente): o × de cada pergunta
  na lista da Sala (`POST /api/rodadas/{id}/perguntas/{pid}/excluir`,
  `RodadaController::excluirPergunta`; só rodada ABERTA, só o rito da
  tempestade). Sem resposta, sai com o `confirm` de sempre. Com resposta, é
  um **modal do sistema** com o destino delas em `ideias`: `manter` (ficam
  na Coleta sem pergunta, no bloco final da fila, e somem do celular) ou
  `apagar` (saem com as estrelas, pelo CASCADE dos votos); "manter" nasce
  marcada. **Sem a chave, o servidor recusa com 409/`PERGUNTA_RESPONDIDA`**:
  a tela decide pelo número que tem, e ele envelhece (o celular escreve a
  qualquer hora) — a recusa é o que impede uma tela desatualizada de apagar
  resposta que ninguém viu, e ao recebê-la a Sala abre o modal em vez de
  mostrar erro. `apagar` é recusado quando alguma resposta já virou registro
  (fator, cenário, ação): o vínculo se desfaz pela Coleta, uma a uma. As que
  ficam são **renumeradas** (`Quiz::renumerarPerguntasLivres`): o celular
  conta por posição ("2 de 3") e a Coleta pela `ordem` (`P2`), e com buraco
  as duas chamariam a mesma pergunta por números diferentes — a resposta
  segue presa ao `pergunta_id`, é só o rótulo que anda, então a regra de
  "pergunta nova só no fim" não é ferida. No celular,
  `Participante.seguirPerguntaVista` segue a pergunta pelo **id** entre um
  desenho e outro (a posição guardada apontaria para a vizinha depois da
  exclusão, e a pessoa leria uma pergunta e responderia outra); sumindo a
  própria, fica a posição com o aviso do porquê. Navegação explícita zera a
  referência (`irParaPergunta`). Provas: funcional 8c (as recusas, o
  `manter`, o `apagar`, a renumeração e a rodada encerrada) e o fim de
  `provasQuestionarioTempestade` (o ×, o confirm, o modal e o celular que
  segue para a próxima).
  **Corrigir o texto da pergunta** (2026-09-05, pedido do cliente): o ✎ ao
  lado do × (`POST /api/rodadas/{id}/perguntas/{pid}/editar`,
  `RodadaController::editarPergunta`; mesmas guardas do ×). O erro de
  digitação aparece com o QR já circulando, e encerrar a rodada para
  reformular jogaria fora PIN, participantes e respostas. **As respostas
  FICAM, sempre** — decisão do cliente contra a alternativa de deixar
  escolher o destino delas: editar é corrigir a REDAÇÃO, não trocar a
  pergunta por outra; quem quer perguntar outra coisa exclui pelo × (que
  decide o destino) e acrescenta no fim. Por isso o método **não toca em
  `coleta_item`**, e a `ordem` também não muda (renumerar trocaria a
  "pergunta 2" de quem já respondeu). Respondida, o modal **avisa** quantas
  respostas já chegaram e de quantas pessoas — o aviso é o que separa este
  gesto do ×. Texto repetido é recusado com mensagem, não com erro de banco:
  o enunciado entra na chave única da rodada (`uk_pergunta_alvo`, MD5 do
  texto para LIVRE), e sem a guarda o condutor levaria um 500. Quem está com
  a pergunta aberta no celular vê o texto novo no desenho seguinte sem
  perder o que digitou — `seguirPerguntaVista` acompanha pelo ID, não pelo
  texto. Provas: funcional 8c (+8) e `provasQuestionarioTempestade` (+3).
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
  **A sala tem duas fases, e a chave é uma só** (`coleta_rodada.votacao`):
  *aberta · recolhendo ideias* e *fechada · escolhendo com ★*. Fechar a sala
  tira o campo de escrever do celular de todo mundo e põe no lugar a lista do
  encontro para cada pessoa eleger as mais importantes — as **suas próprias vêm
  marcadas** ("sua", via `minha` em `paraVotar()`, com `<=>` porque a ideia
  cadastrada pela condução tem token NULL). O botão mora no painel da **Coleta**
  (é onde quem conduz está, vendo a nuvem) e também na aba Sala, com o MESMO par
  de rótulos nos dois: "Fechar a sala" / "Reabrir a sala". Ele só aparece **com
  alguém conectado** (`participantes > 0`), como o ✎ da pergunta: sem sala não há
  fase para trocar. No corpo da requisição a fase continua sendo `votacao`
  (`abrir: true` = abre as ★ = fecha a sala) — o rótulo fala do gesto, a rodada
  guarda o estado.
  Fechada, o servidor **recusa escrita** (`exigirSalaRecolhendo` em `ideia()` e
  `editarIdeia()`): sem isso um celular que ainda não bateu o polling seguia
  gravando ideia com a sala inteira já votando. A recusa repete a condição da
  tela — **fechada com a lista vazia não é fase nenhuma**: sem nada para votar o
  celular volta a recolher (senão seria um beco: não dá para escrever nem para
  votar), e recusar ali transformaria o contorno em erro na cara de quem digita.
  O polling da Coleta compara também o **retrato da rodada** (fase, pergunta,
  participantes): só as ideias no retrato deixavam o painel do outro condutor
  dizendo "sala aberta" com a sala já fechada.
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

- **Fila de "Aguardando plano de ação"**: o card de Projetos junta QUATRO
  origens — ideia da Coleta (`coleta_item.destino_tipo='ACAO'` com `destino_id`
  NULL), **fator do diagnóstico** (`fator.acao_em` preenchido com
  `desdobramento_id` NULL — PESTEL, Porter **ou** SWOT), **item da Análise de
  Cenário** (`cenario_item`, as mesmas três colunas) e
  **cruzamento (TOWS)**, com as mesmas três colunas do fator. O cruzamento vai
  **direto ao plano, sem passar pela cascata**: ele já é a estratégia que nasce
  do par, e a cascata decide outra coisa (em que horizonte cada driver aposta).
  Uma fila só de propósito: a origem muda o selo e o campo que fecha o vínculo,
  não a pergunta "o que ainda não virou ação?". A **chave da linha é por
  origem** (`c…`/`f…`/`x…`/`n…`), não o id: as quatro tabelas numeram separado e
  sem o prefixo dois registros disputariam a mesma linha — o "Transformar em
  ação" abriria a pendência errada, sem erro nenhum. O `modalConverterAcao`
  manda `coleta_item_id`, `fator_id`, `cruzamento_id` **ou** `cenario_item_id`,
  nunca mais de um, e o `salvarDesdobramento` fecha o vínculo com a mesma guarda
  no WHERE (só o que ainda está na fila), para pedido repetido não sequestrar
  vínculo alheio. As frases que mudam por origem (título, rótulo, pergunta e a
  barra colorida) vivem no objeto `falas` do `modalConverterAcao`, uma chave por
  origem — com ternário aninhado, a quarta origem teria de ser encaixada em
  quatro lugares do formulário, na mesma ordem, sem esquecer nenhum.
  **Qualquer etapa vai direto ao plano** — PESTEL, Porter e SWOT. Até 2026-08
  só a SWOT podia, para não pular a síntese que ela existe para fazer; a regra
  foi **revogada por decisão do cliente (2026-08-31)**, porque há fator do
  PESTEL e do Porter que já nasce com dono e prazo (uma lei com data marcada,
  um fornecedor que vai sair) e obrigá-lo a inventar um quadrante produzia SWOT
  de fachada. A promoção continua existindo e continua sendo o caminho
  recomendado quando o fator PRECISA de síntese — o que mudou é que ela deixou
  de ser obrigatória. As três etapas são a **mesma tabela** e fecham pelo
  **mesmo `fator_id`**; o que muda é só o CATÁLOGO do rótulo, lido num lugar só
  (`SecaoProjetos.categoriaDoFator`, que conhece os quadrantes da SWOT e as
  tuplas de `Diag.CATEGORIAS_ETAPA`). A fila devolve `origem = f.etapa`, e é
  por isso que o selo escreve "PESTEL · Legal" sem um `if` por tela. O selo de
  três estados (**→ Plano de ação · Aguardando ação · Virou ação ↗**) e os três
  ouvintes dele vivem em `Diag.seloPlanoAcao` / `Diag.ligarPlanoAcao`, chamados
  por `carregarEtapa` (PESTEL/Porter), pela SWOT e pelo Cenário —
  `ligarPlanoAcao` recebe o **recurso** (`fatores`/`cenario`) porque as tabelas
  e as rotas diferem; o selo, não.
  **A Análise de Cenário também vai ao plano** (mesma decisão): `cenario_item`
  ganhou `acao_em`/`acao_por`/`desdobramento_id` (migrate, `garantirColuna` +
  `garantirFk`), `CenarioController::planoAcao` e `::aguardandoAcao`
  (`origem = 'CENARIO'`, `categoria` = o tipo), e o vínculo fecha por
  `cenario_item_id`. É **outra tabela**, não o mesmo código — o que se
  compartilha é o desenho. Os rótulos e cores dos dois tipos moram em
  `SecaoCenario.TIPOS`, catálogo único lido pelo selo da fila e pelo modal de
  "aceitar sugestão da sala".
  `fator.desdobramento_id`, `swot_cruzamento.desdobramento_id` e
  `cenario_item.desdobramento_id` têm FK **ON DELETE SET
  NULL**: apagada a ação, a origem volta sozinha para a fila. A ideia da Coleta
  não tem FK (o destino é polimórfico) e por isso `excluirDesdobramento` limpa
  o `destino_id` dela à mão — sem essa linha a ideia sumia da fila para sempre,
  apontando para um desdobramento que não existe mais. Excluir um fator ou item
  de cenário que já virou ação é **recusado**: deixaria a ação no plano sem
  origem nenhuma. No cenário a trava sai direto de `acao_titulo` (o vínculo é
  único); no fator ela precisa de `Fatores::acoesQuePrendem`, porque lá também
  nasce do promovido e do cruzamento.

- **projeto → iniciativa → ação**, espelhando o projeto BSC. O cadastro do
  projeto tem só ano, título, descrição e responsável; **início e fim são
  consequência das ações** (menor `data_inicio`, maior `data_fim`) e o status
  agrega o das ações — tudo recalculado na leitura por `consolidarProjetos()`.
- O **ano** define o horizonte: `horizonte.ano_inicio/ano_fim` cobrem o ciclo e
  o projeto herda o H1/H2/H3 pelo ano. Não existe mais ação plurianual × anual.
- Status `NAO_INICIADO` e `ATRASADO` são **automáticos** (derivados da
  data-limite, reconciliados em `sincronizarAtrasos()`); os demais são manuais.
- **Ação CANCELADA não tem progresso.** O percentual dela vai a **zero** na
  gravação (`salvarDesdobramento` e o pop-up de status da barra) e ela fica
  **fora das médias** da frente e do projeto — no numerador *e* no denominador
  (`panorama()` na tela, `AVG(CASE WHEN d.status <> 'CANCELADO' …)` no
  relatório). Contá-la afundava o percentual por trabalho que ninguém vai
  fazer; guardar o valor antigo produzia número fantasma (a ação exibia 70% sem
  entrar em conta nenhuma). É a mesma regra que a `Consolidacao` já aplica ao
  status e ao prazo do projeto e da frente. Na tela a barra sai **inativa**
  (`.faixa-progresso.inativa`, sem controle para arrastar) e **sem**
  `data-progresso` — por isso some sozinha de `atualizarMedias`. A rota do
  ajuste rápido **recusa** ação cancelada com o código `ACAO_CANCELADA` (a
  barra inativa é conforto de tela, não autorização) e a tela recarrega ao
  receber esse código: o cartão estava velho. Um passo do migrate zera o
  percentual das canceladas anteriores à regra.
- O status da **frente** (iniciativa) é **todo derivado** das ações dela
  (`consolidarIniciativas()`): todas concluídas fecham a frente sozinha, e o
  modal **não tem campo de status** — um valor digitado seria sobrescrito na
  primeira leitura. Frente sem ação (ou só com canceladas) volta a "Aberta".
  Não existe frente "Atrasada": o atraso aparece no panorama e no projeto.
- **Recorrência** (`recorrencia` NENHUMA/SEMANAL/MENSAL + `recorrencia_dias` +
  `recorrencia_ate`): concluir uma ocorrência não encerra a ação — ela reabre
  na próxima data prevista e a conclusão vira um comentário automático — a
  ação volta a NAO_INICIADO e, sem esse registro, não sobraria rastro nenhum de
  que ela chegou a ser concluída. A regra está em `App\Services\Recorrencia`, o
  reagendamento avança ocorrência a ocorrência até passar de hoje, e o caminho
  que conclui uma ação é **um só**: o cadastro (era dois — o diário de bordo era
  o outro, e sumiu com ele).
  **Os DOIS tipos aceitam vários dias** (CSV em `recorrencia_dias`): "toda
  segunda e quinta" e "todo dia 5 e 20" são UMA rotina, e enquanto o semanal
  guardava um dia só quem precisava de dois cadastrava a mesma tarefa duas
  vezes — duas cobranças, dois cartões, dois relatórios para a mesma coisa.
  `recorrencia_dia` (singular) segue gravado com o **primeiro** dia e é o
  fallback das ações anteriores à coluna nova; ninguém mais decide por ele.
  A ação que se repete **não tem período digitado**: quem diz quando ela vence é
  a grade, e o servidor DERIVA `data_inicio`/`data_fim` dela — o atraso
  automático, os avisos por e-mail e o prazo consolidado do projeto leem as
  colunas, não a regra. Editar uma recorrente em dia não empurra o vencimento:
  ele só é recalculado quando a grade muda, porque avançar a grade é gesto da
  CONCLUSÃO, não do salvamento.
  **`recorrencia_ate` é OPCIONAL** (decisão do cliente): em branco, a rotina é
  por tempo INDETERMINADO — segue reabrindo até alguém encerrá-la. `null` é
  exatamente o que `Recorrencia` lê como "sem limite", então a ausência não
  precisa de tratamento em lugar nenhum. Data **escrita**, porém, continua tendo
  de ser data: quem recusa é o `periodo()` do controller, e sem essa recusa um
  texto que não parseia viraria um null silencioso — a rotina que alguém quis
  limitar passaria a não acabar nunca, que é justamente o significado do vazio.
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
  Na tela (pedido do cliente): o botão **Comentários** abre só a LISTA do que
  já existe; escrever é o **+** ao lado do título, que abre o formulário em
  **modal** (`modalComentario`, via `Modal.abrir` com `enviar` próprio) — texto
  com ditado por voz e o campo `arquivos` do modal (tipo novo: `coletar()` o
  pula, e o `enviar` lê os arquivos por `Modal.arquivosDe`).
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
- **Excluir usuário** (`UsuarioController::vinculos`/`excluir`, o ✕ na aba
  Usuários): ao contrário do negócio, aqui **excluir não exige estar sem uso** —
  o que a rota faz é dar DESTINO ao que a pessoa segurava. `UsuarioController::
  VINCULOS` é a lista das treze colunas que apontam para `usuario.id`, com as
  duas naturezas que a tela lê separadas: **carteira** (`desdobramento.
  quem_usuario_id`, `fator.acao_por`, `swot_cruzamento.acao_por`,
  `negocio.gestor_id`) — trabalho que alguém precisa assumir, de onde saem as
  cobranças por e-mail — e **autoria** (comentário, ata, ideia, rodada,
  cruzamento redigido), que é registro do passado. Uma lista só porque ela serve
  a TRÊS leituras (o que a tela mostra, o que a transferência move, a conferência
  final): escritas separadas, uma coluna nova entraria em duas e a terceira só
  apareceria como erro de chave estrangeira, no dia em que alguém tentasse
  excluir alguém.
  A tela **pergunta antes**: `GET /api/usuarios/{id}/vinculos` conta o que a
  pessoa segura, e a contagem chega ANTES da escolha — sem "1 ação do plano" na
  frente, o formulário estaria pedindo assinatura em branco. São dois destinos,
  e **um deles é obrigatório sempre que houver vínculo**: `transferir_para` (id
  de quem assume) ou `sem_responsavel: true`. Sem essa exigência (409/
  `DESTINO_OBRIGATORIO`) o caminho mais curto — só o id na URL — seria
  justamente o que apaga o dono de toda a carteira em silêncio, e "não escolhi"
  viraria escolha por omissão.
  Cinco colunas de autor eram **NOT NULL**, e é isso que a migração muda: sem
  nulo, a única alternativa a transferir seria apagar o registro junto com a
  pessoa — perder a ata porque quem a escreveu saiu da empresa. **Anular não é
  apagar**: texto, data e anexos ficam, e as quatro consultas que mostram autoria
  viraram `LEFT JOIN` com `COALESCE(u.nome, ?)` — com o `JOIN` fechado, o
  comentário sumia da lista junto com o autor. O rótulo tem **uma fonte só**
  (`UsuarioController::SEM_USUARIO`), e não se confunde com o
  `— sem responsável —` de `Avisos::carteira`: aquele agrupa ação sem dono num
  relatório de cobrança, este diz que quem escreveu não está mais no cadastro.
  `desdobramento.quem_usuario_id` e `coleta_item.unido_por` **ganharam chave
  estrangeira** (RESTRICT): sem ela o DELETE passava e deixava as duas apontando
  para um id que não existe — a ação seguia listada, sem dono e sem nada na tela
  dizendo isso. RESTRICT de propósito, não SET NULL: quem decide o destino é o
  controller, e a chave é a rede que faz o DELETE **falhar** se ele esquecer uma
  coluna; nulo silencioso a rede não pega.
  A conferência final (`referenciasRestantes`) lê as colunas do
  **information_schema**, nunca da constante — é essa a graça: uma tabela criada
  daqui a um ano com `REFERENCES usuario(id)` entra sozinha, e quem esquecer de
  somá-la a VINCULOS recebe uma recusa nomeando a tabela em vez de um erro cru de
  chave. Conferir contra a própria constante não provaria nada: ela é justamente
  o que pode estar incompleto.
  O **nome escrito na ação anda junto com o id** (`desdobramento.quem`), e o
  UPDATE dele roda **antes** do laço de transferência — depois, essas linhas já
  são de quem recebeu e não haveria como distingui-las das que ele já tinha.
  Sem destino ele fica **vazio**, nunca com o nome de quem saiu, e o cartão passa
  a mostrar o selo **«Sem usuário»** (`.selo-sem-usuario`): enquanto a linha
  "Quem:" só aparecia com o campo preenchido, a ação órfã ficava idêntica a uma
  bem atribuída, com o metadado apenas ausente no meio de outros seis.
  Dois impedimentos que transferência nenhuma resolve, e por isso o ✕ nem aparece
  (`excluivel` no `listar()`, como no cadastro de negócios): **você mesmo** (a
  sessão cai no meio do gesto) e o **último ADMIN ativo** (sem administrador não
  há quem crie usuário nem quem chegue de novo a esta tela — o conserto seria no
  banco, à mão). Quem recebe precisa estar **ativo**: inativo não recebe cobrança
  nenhuma, e transferir para lá é o mesmo sumiço de "sem responsável", só que com
  um nome na tela dizendo que alguém está cuidando disso.
  **A autoria transferida passa a ser de quem recebe** — uma ata passa a constar
  como escrita por quem não estava lá. Foi decisão do cliente, e a alternativa
  está a um clique.
  Nada roda em transação (o repositório não usa `beginTransaction` e
  `Json::erro()` encerra a execução), então a ordem é **UPDATE → conferência →
  DELETE**: interrompida no meio, a pior sobra é uma pessoa sem nada apontando
  para ela, que a tela mostra e deixa excluir de novo. Ao contrário, ficaria a
  linha apagada com registros pendurados nela.
- **Rótulo do Salvar** (`Modal.abrir({salvar: {rotulo, perigo}})`): "Salvar"
  descreve mal o gesto que exclui alguém, e o verde diz "siga em frente" bem na
  hora de parar para ler. O `#modal-salvar` é o MESMO elemento em todos os
  modais — como o `#modal-extra` —, então ele é **reposto ao padrão a cada
  abertura**: sem isso o primeiro formulário destrutivo deixaria "Excluir", em
  vermelho, no rodapé de todos os seguintes da sessão. O defeito só aparece no
  modal de DEPOIS, e a bateria o guarda abrindo um segundo formulário.
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
  para ajuste) com **passo 5 nas três pontas** — barra do cartão, campo do
  modal e servidor (`arredondarProgresso`), com o migrate normalizando o
  legado. Passo diferente entre elas faz o range do navegador "encaixar" o
  valor e a tela divergir do banco a cada salvamento.
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
  Ordem (pedido do cliente, com mockup): **o quê, como, quem**, a **caixa da
  repetição**, a **linha** de prioridade e status e, por último, os **ganhos
  previstos**. "Quem?" não estava na ordem pedida originalmente e ficou logo
  depois do "Como?": é de `quem_usuario_id` que saem os avisos por e-mail e o
  filtro de "minhas ações" — sem ele a ação não tem dono. Os ganhos vão por
  último por serem o único campo opcional: quem só descreve a ação chega ao
  Salvar sem passar por ele.
  **O prazo mora DENTRO da caixa da repetição**, e não é arrumação: é a
  repetição que decide QUAL prazo existe. Sem repetição aparece "Quando? (Prazo
  de Execução)", com início e fim previsto; toda semana ou todo mês, aparecem as
  fichas dos dias e a data fim da repetição. Ter os dois na tela fazia o usuário
  preencher um "fim previsto" que a primeira conclusão descartava.
  **Obrigatórios: o quê, como, quem e o período** — os quatro recusados em
  `ProjetoController::salvarDesdobramento`, não só marcados na tela. Ação sem
  caminho e sem prazo não é plano, e o prazo é o que alimenta o atraso
  automático, os avisos e o painel. A data fim da repetição NÃO entra nessa
  lista: em branco ela significa "sem prazo para terminar". Consequência para
  quem for mexer: ação antiga sem "como" ou sem datas passa a exigi-los na
  próxima vez que alguém abrir e salvar.
  **"Quanto custa?" virou "Ganhos previstos (R$)"** (pedido do cliente). A
  coluna continua sendo `quanto`: renomeá-la seria migração com alcance em todo
  o plano de ação para uma mudança de rótulo. Fica o aviso de que o SENTIDO
  mudou — os valores já cadastrados foram digitados como custo.
- **A PILHA de cabeçalhos**, de cima para baixo: topbar → cabeçalho de Projetos
  → cabeçalho do **projeto** (`.projeto-cabeca-fixa`) → as **frentes já
  percorridas** (`.iniciativa-cabeca`), **empilhadas**. Cada degrau soma a
  altura do anterior, e as alturas variáveis são **medidas**
  (`medirCabecalhosProjeto`): `--altura-cabecalho` por seção
  (`Diag.ligarCabecalhoFixo(el, '.cabecalho-projetos')` — o helper das análises
  ganhou o seletor como parâmetro), `--altura-projeto` **por cartão** (o selo de
  atraso, o "Prioritário" e o nome que quebra mudam a altura de um projeto para
  o outro; uma média só erraria em todos menos num) e `--desloca-frente` **por
  frente** — a soma dos cabeçalhos de frente acima dela.
  **Dentro do projeto as frentes ACUMULAM** (decisão do cliente, revertendo o
  "uma frente por vez" anterior): cada cabeçalho de frente fica grudado até o
  bloco do projeto acabar, e só o projeto seguinte varre a pilha. **Projeto não
  empilha embaixo de projeto**: todos usam o mesmo `top` e cada um é limitado ao
  próprio cartão, então o novo substitui o anterior na mesma linha. O custo é
  assumido: projeto de muitas frentes gasta uma faixa de tela por frente
  percorrida. Quem permite a pilha é a `.iniciativa` com **`display: contents`**
  — sticky só se move dentro do bloco PAI, e com a caixa da frente no meio cada
  cabeçalho grudava apenas enquanto o próprio bloco estava na tela; sem a caixa,
  o limite passa a ser `.iniciativas-projeto` inteiro. O elemento segue no DOM
  (`[data-iniciativa]`, duplo clique e `closest()` continuam valendo), mas
  filete verde/recuo foram para o container e o respiro entre frentes para o
  último bloco visível de cada uma — **nunca margem no cabeçalho sticky**, que
  viraria fresta transparente na pilha.
  O **`z-index` desce conforme se desce na pilha** — Projetos 20, projeto 15,
  frentes de 14 para baixo (em linha, pelo JS, piso 1: sempre acima dos cartões)
  —, para cada um passar POR BAIXO do de cima ao se encontrarem; invertido, a
  frente que sai no fim do bloco cobriria a de cima em vez de deslizar por
  baixo dela.
  Três cuidados separam "grudado" de "vazando": fundo **opaco** e igual ao do
  cartão, margens negativas cobrindo o recuo do bloco (senão sobra faixa
  transparente à esquerda e o texto das ações passa por ali) e a ordem de
  camadas acima. O que **não** se faz é pôr `overflow: hidden` num ancestral
  para "cortar" o conteúdo: qualquer ancestral com overflow vira o scrollport do
  sticky, e o cabeçalho passa a grudar numa caixa que não rola — ou seja, nunca.
  Quem esconde o que passa por baixo é o fundo opaco.
- **O cabeçalho de Projetos gruda** abaixo da topbar (`.cabecalho-projetos`,
  `top: var(--topo-app)`, fundo sólido e `z-index: 20`, o mesmo mecanismo do
  cabeçalho da GUT): os três botões de nível são o controle que se usa LENDO a
  lista, e trocar de visão no quinto projeto obrigava a subir a página inteira.
  O parágrafo de instruções fica **fora** do bloco fixo de propósito — ele se lê
  uma vez, e grudado custaria uma faixa de tela em toda rolagem, para sempre.
  As margens negativas cobrem a sarjeta do container, senão a lista aparece
  pelas beiradas ao passar por baixo.
- **Excluir ação, frente ou projeto pergunta o destino das ORIGENS**
  (2026-09-03, pedido do cliente): quando alguma ação nasceu de um fator, item
  de cenário, cruzamento ou ideia da Coleta, a tela abre um modal (nunca
  `confirm()`) com duas saídas — **devolver** à fila de "aguardando plano de
  ação" (o de sempre: a FK SET NULL devolve fator/cenário/cruzamento sozinha,
  a ideia fica ACEITA sem destino) ou **tirar de vez** (a marca `acao_em` e o
  vínculo somem ANTES do DELETE, e a ideia volta a SELECIONADO sem destino,
  como o "Desmarcar" da Coleta). A chave é `origens` no corpo das três rotas
  de exclusão (`ProjetoController::destinoDasOrigens`); ausente vale
  `devolver`, valor desconhecido é recusado. A listagem de projetos traz
  `origens` por ação — é o número que decide se há diálogo: ação sem origem
  exclui com o `confirm` de sempre. A própria fila ganhou o **×** que tira a
  pendência de vez (`tirarDaFila`: o `plano-acao` com `marcar:false` de cada
  origem, `reabrir` na Coleta). Provas: funcional 9k e `provasExcluirComOrigens`.
- **Pesquisa do plano de ação** (`.filtro-acoes`, no cabeçalho fixo): palavra e
  situação. A palavra casa com o texto do cartão da ação **e com os títulos da
  frente e do projeto**, e o resultado mostra os três níveis juntos; a situação
  casa pelo código (`data-status`), nunca pelo rótulo. É **filtro de DOM, não
  recarga** (`aplicarFiltro`): repintar mataria o foco de quem digita. O estado
  (`filtroTexto`/`filtroStatus`) mora na seção e o `carregar()` reaplica ao
  terminar. Com filtro ativo os acordeões **abrem à força** (resultado dentro de
  frente recolhida é resultado invisível) e, ao limpar, o recolhimento volta dos
  conjuntos, que o filtro nunca altera. Frente escondida zera o cabeçalho e o
  ResizeObserver reempilha a pilha sozinho. O campo é enxuto de propósito
  (11rem; no celular a dupla toma a linha inteira) — mora no cabeçalho fixo, e
  cada rem ali é tela roubada da lista o tempo todo.
  **A pessoa entra por DOIS caminhos, e eles não são redundantes.** A palavra
  passou a casar também com o **nome e o e-mail** de quem responde pela ação: é
  o caminho largo, e quem digita "Ana" acha as ações dela sem saber onde o nome
  aparece na tela. O preço é o falso positivo — "ana" está dentro de "semana", e
  a reunião semanal de outra pessoa vem junto. O **responsável**
  (`filtroResponsavel`, `[data-filtro-responsavel]`) é o caminho exato: casa SÓ
  contra quem responde, nunca contra o texto da ação. Quem "simplificar" fazendo
  os dois compartilharem o mesmo casamento deixa a tela funcionando e a precisão
  some — é o que a bateria guarda.
  O controle é `<input type="search" list=…>` com `<datalist>`: dá para escolher
  da lista **ou digitar parte do nome**, que é o que se faz sem lembrar a
  grafia. `<select>` fechado obrigaria a caçar a pessoa numa lista longa; texto
  puro não diria quem existe. Ele escuta **`input`, não `change`** — escolher da
  lista dispara os dois, mas digitar só dispara `input`, e com `change` quem
  digita ficava sem filtro até sair do campo.
  A lista (`pessoasParaFiltro`) soma DUAS fontes: quem **tem ação no plano**
  (única que traz o e-mail, e que inclui quem já foi desativado mas ainda
  carrega ação — justamente as que precisam ser reatribuídas) e os
  **responsáveis ativos** de `/api/responsaveis` (só nomes), para quem ainda não
  recebeu nada aparecer e a escolha devolver tela vazia, que é a resposta certa.
  A chave é o nome NORMALIZADO: as duas fontes escrevem a mesma pessoa com
  acentuação diferente, e sem normalizar ela entrava duas vezes.
  **«Sem usuário» é a primeira opção**, fixa (`ROTULO_SEM_DONO`): a ação órfã
  não é cobrada de ninguém. O rótulo é a mesma constante que o compara — a caixa
  é de texto livre, o item escreve nela o que volta para o filtro, e um rótulo
  escrito num lugar e comparado em outro deixaria de casar na primeira revisão
  de redação. Ele casa pelo rótulo INTEIRO, não por pedaço, senão digitar "sem"
  trocaria o sentido do filtro no meio da palavra.
  O que o filtro compara é o **`data-quem` do cartão** (`chaveQuem`: nome e
  e-mail normalizados, vazio quando não há dono) — o e-mail não está escrito na
  tela, então lê-lo do texto visível nunca o encontraria. O e-mail vem do
  servidor em `desdobramento.quem_email` (LEFT JOIN em `ProjetoController`), e
  não de `/api/usuarios`: aquela rota é exclusiva de ADMIN, e o gestor que
  precisa do mapeamento não a alcança.
  O placeholder da palavra é curto ("Palavra ou pessoa…") porque em 11rem o
  texto é cortado no meio: "Pesquisar palavra, pessoa ou e-mail…" morria em
  "pessoa ou e-", que promete pior do que não prometer. O resto mora no `title`.
  Armadilha da bateria já paga: **`page.fill(campo, '')` é NO-OP** num campo que
  já tem texto — o Chromium não apaga a seleção quando o texto inserido é vazio.
  A prova seguia com o filtro anterior de pé e ficava verde pelo motivo errado.
  Limpar é `ControlOrMeta+A` + `Backspace`, que é o que o usuário faz.
- **Três níveis de recolhimento** (`nivelAtual` / `aplicarNivel` /
  `pintarNiveis`): **Ações · Frentes · Projetos**, no lugar do "Recolher tudo"
  que só tinha os extremos. "Frentes" é o nível que faltava — esconde as ações e
  mantém projetos e frentes com os seus percentuais. Recolher a iniciativa já
  era o que escondia as ações dela; o grupo só dá um toque para chegar lá. Com o
  usuário abrindo/fechando itens à mão, `nivelAtual` devolve vazio e **nenhum
  botão fica aceso** — melhor que um botão mentindo. Os acordeões chamam
  `pintarNiveis`: eles mexem no DOM sem recarregar a seção, e sem isso o grupo
  seguiria marcando "Ações" com as ações já escondidas.
- **Resumo por situação** (`resumoStatus(acoes, apenas)`): nos cabeçalhos do
  projeto **e** da frente vai só o **atraso** (`apenas: ['ATRASADO']`) — quantas
  ações estão fora do prazo e quanto isso é do total daquele nível. A pergunta
  do cabeçalho é uma só; a distribuição inteira mora no **popover do título**, a
  um passar de mouse. O denominador é sempre o TOTAL do nível, mesmo com
  `apenas`: no projeto, todas as ações (as frentes somadas); na frente, as dela
  — as mesmas 2 atrasadas dão 33% num e 67% no outro, e trocar a base é defeito
  invisível.
  O cabeçalho da frente **não tem mais o selo de situação** ("Aberta"): a seta
  ao lado do nome já diz se ela está aberta ou recolhida, e a situação cadastrada
  passou para o rodapé do popover dela — tirada de lá, ela só existiria no
  formulário de edição.
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
  **O título do projeto e o da frente abrem um popover** com a distribuição
  inteira (`conteudoPopover` + `ligarPopoversResumo`, Popover do Bootstrap):
  nome da situação à ESQUERDA e contagem com percentual à DIREITA, em coluna —
  em linha corrida o olho procurava o número no meio do texto, e os números são
  o que se compara entre linhas. Duas armadilhas pagas: o sanitizador do
  Bootstrap **descarta `style`** do conteúdo, então a cor de cada situação vem
  por CLASSE (`.st-ATRASADO` e irmãs, no CSS) — de estilo em linha ela sumiria
  só dentro do balão; e as instâncias são **descartadas a cada pintura**, porque
  o balão mora no `<body>` e sobrevive à troca do `innerHTML` — sem o `dispose`,
  uma tarde de uso empilha dezenas deles fora da tela.
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
  exige **chave nova** — e, no cenário, a revisão pode ser **no lugar**: item
  `['de' => texto anterior, 'para' => texto novo]` atualiza a linha que ainda
  está na tela (mesmo id, mesma ordem, mesmas vozes e encaminhamento) em vez
  de inserir uma segunda versão do mesmo assunto; apagada ou reescrita à mão,
  o novo entra como item (`CargaConteudo::aplicarCenario`; prova em
  `testes/carga_cenario.sh`). Foi assim que a fotografia de setembro/2026
  (`cenario_macro_2026_09`, 17 revisões e 8 assuntos novos) substituiu a de
  agosto sem duplicar a tela. Cada carga mora num arquivo só
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
# Banco (MariaDB local; socket precisa de caminho curto). Numa sessão de agente,
# suba com setsid nohup … & disown: processo preso ao shell da ferramenta
# morre quando ela devolve, e "o banco caiu" vira depuração perdida.
setsid nohup mariadbd --user=$(id -un) --datadir=<dir> --socket=/tmp/ccm.sock \
  --port=33061 --bind-address=127.0.0.1 >db.log 2>&1 </dev/null & disown

export DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app
php database/migrate.php

# Servidor — o argumento router (public/index.php) é OBRIGATÓRIO.
# SALA_AUSENTE_SEG=6 encurta a ausência da sala: sem ela, duas provas da
# funcional (reentrada pelo nome) ficam vermelhas.
SALA_AUSENTE_SEG=6 setsid nohup php -S 127.0.0.1:8099 -t public public/index.php \
  >php.log 2>&1 </dev/null & disown

# Derrubar ao fim (o pkill devolve 144 — é inofensivo)
mysql --socket=/tmp/ccm.sock -uroot -e SHUTDOWN; pkill -f "php -S 127.0.0.1:8099"
```

- Login local de teste: `admin@coperdia.com.br` / `trocar123` (em produção a
  senha inicial vem de `ADMIN_SENHA`; sem ela o migrate gera uma aleatória e
  imprime uma única vez no log).
- **Zerar o plano de ação** (`php cli/limpar_plano_acao.php` conta;
  `... apagar` faz backup, pede `APAGAR-PLANO-DE-ACAO` e apaga numa transação;
  `--confirmo=` para one-off sem terminal; `--planejamento=ID` limita). Sai:
  projeto, iniciativa, desdobramento, comentários deles e cadeados. Fica, mudando
  de estado: fator/item/cruzamento voltam à fila de aguardando plano de ação,
  ideia da Coleta fica aceita aguardando, investimento perde o vínculo. Mesma
  contabilidade de `ProjetoController::excluir`. Pedido do cliente em
  2026-09-02 para recomeçar o cadastro; ver `docs/DEPLOY-RAILWAY.md` §7b.
- **Senha perdida** (`cli/senha.php listar` / `trocar <e-mail> [senha]
  [--ativar]`): é o caminho de quando NINGUÉM mais entra. A senha é bcrypt e
  não tem volta — nem o sistema, nem um ADMIN, nem o dono do banco leem a
  original. E **`ADMIN_SENHA` não resolve**: o passo do migrate que cria o admin
  só roda quando não existe nenhum (`WHERE perfil = 'ADMIN'` com contagem zero),
  então reimplantar com a variável definida não toca em quem já está lá — quem
  tentar por aí perde o deploy inteiro para descobrir isso.
  Não é buraco novo de segurança: quem executa já tem o shell do servidor e,
  com ele, o `config/config.php` e o banco — poderia escrever o hash à mão. O
  que a CLI acrescenta é fazer certo, com o mesmo `password_hash` e o mesmo
  mínimo de 8 caracteres da tela (duplicar a regra com outro número deixaria a
  CLI gravando o que o formulário recusa).
  Três decisões: a senha **sorteada é o padrão** (senha passada como argumento
  fica no `history` e é legível no `ps` enquanto o comando roda); **reativar é
  explícito** (`--ativar`) — devolver a senha de quem foi desativado de propósito
  e reativá-lo junto, calado, desfaria decisão de outra pessoa; e sem a opção a
  CLI **avisa** que o acesso segue bloqueado, porque `Auth::exigirLogin` relê
  `ativo` a cada requisição e a pessoa levaria "e-mail ou senha inválidos"
  concluindo que a redefinição falhou. Falha de conexão vira UMA linha, não um
  `stack trace`: a ferramenta é usada no pior momento possível, e uma pilha de
  exceção faz parecer que ela é que está quebrada.
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
  8px o código trata como toque, não arraste. E o polling repinta as listas:
  um `ElementHandle` guardado antes do repinte dá "Element is not attached to
  the DOM" — refaça a consulta pelo seletor (`.ideia-votavel >> nth=N`) na
  hora de clicar, em vez de guardar o elemento.
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
| `sistema.js` | As 18 seções em 1500×700 e 390×844, mais DUAS sessões no preenchimento simultâneo, no cadeado de edição (com dois usuários) e na oficina de Cruzamentos (computador + celular) | Uma tela parou de pintar, estourou erro de console ou passou a rolar na horizontal — **nas duas larguras** |
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

**Linha de base em 2026-09-04** (depois da exclusão de pergunta do
questionário; era 276/578 depois do PR #20): funcional **288 ✓ / 0 ✗**
(`BASE=http://127.0.0.1:8099 SALA_AUSENTE_SEG=6 bash testes/funcional.sh`) e
sistema **583 ✓ / 0 ✗** em cerca de nove minutos
(`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers node testes/sistema.js`). A de
sistema ainda **sai com código 1** por 14 avisos de console esperados (12 ×
`400` e 2 × `409` que as próprias provas provocam) — é pendência técnica do
parecer, não regressão; o que conta é `0 ✗`. Número abaixo da base sem ✗ é
sinal de **prova pulada em silêncio**: foi assim que uma sala deixada aberta
por uma execução anterior escondeu 13 provas (247 ✓, nenhum vermelho). Hoje a
seção 8 recusa `SALA_ABERTA` como falha e os `finally` das provas de sala
chamam `/api/quiz/encerrar` — toda prova que abre sala **fecha a sala**, mesmo
quando reprova no meio. Duas miudezas que já custaram uma rodada: `GET
/api/me` devolve o usuário em `dados.usuario.id`, não em `dados.id`; e a
funcional cria 7 rodadas por execução e não as apaga (pendência §3 do
parecer).
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

- **O `main` é produção.** O serviço web da Railway está ligado ao repositório
  `Trmartello/Controladoria`, branch `main`, com auto-deploy a cada push e
  *Wait for CI* desligado (não há CI para esperar — as baterias rodam na
  máquina de quem entrega). O caminho de uma entrega é: branch de trabalho →
  pull request → merge no `main` → a Railway constrói e sobe sozinha. **Nunca
  reaproveitar o serviço de produção** para homologar outra coisa (o app do
  repositório irmão, uma branch experimental): é o sistema que a cooperativa
  usa, e o banco dele é o único.
  Referência do último deploy grande: **2026-09-02, PR #4** (`69eb128`, 23
  commits de 2026-08-18 a 09-01, 52 arquivos) — levou o Dossiê, a Matriz de
  Execução, o Impacto por Negócio, o Vivo, o bloqueio de edição, os movimentos
  entre análises e a sala nos Cruzamentos. A migração criou quatro tabelas
  (`impacto_negocio`, `indicador_cascata`, `edicao_bloqueio`,
  `planejamento_versao`) e alargou dois ENUMs; **não pediu variável nova**.
  Antes dele a produção estava 23 commits atrás do trabalho — e ficou assim por
  dias sem ninguém notar, porque nada avisa. Confira `git rev-list --count
  origin/main..<branch>` antes de dizer que "está no sistema".
  **Último deploy: 2026-09-04, PRs #13 a #20** (oito merges em dois dias: 🎤 da
  etapa inteira, questionário da tempestade e a revisão pelo time de agentes).
  A migração acrescenta `coleta_rodada.prazo` (PR #17) e dois índices
  (`idx_fator_plan_ano`, `idx_cenario_plan_ano`, PR #20); **não pediu
  variável nova**. O sinal de que a versão subiu está no cron de avisos: entre
  2026-09-02 e esse deploy, `cli/notificar.php` morria na primeira escrita
  (classe `Versao` fora do autoloader), mandava o primeiro e-mail da lista e
  caía — *Cron Runs* do serviço `avisos` deve mostrar falha diária nesse
  intervalo e execução limpa depois. Ninguém confirmou o print ainda.
- **Ler o deploy certo.** A Railway tem duas abas de log e elas confundem:
  *Build Logs* é a construção da imagem (Dockerfile) e **não** mostra a
  migração; o que interessa está em *Deploy Logs*, onde o `entrypoint.sh`
  escreve. Um deploy saudável imprime, nesta ordem:
  `entrypoint: iniciando na porta 8080` → `Aplicando migração do banco...` →
  `migrate: conectando em mysql.railway.internal:3306/railway (usuário root).`
  → `migrate: ok.` → a linha do `php -S` com os trabalhadores
  (`PHP_CLI_SERVER_WORKERS` — 8 no Dockerfile; o log de 2026-09-02 mostrou 10,
  a variável está sobrescrita no serviço). Sem o `migrate: ok.` o `set -e` do
  entrypoint aborta o start e o deploy nunca fica *Active* — a Railway mantém o
  anterior no ar. É a proteção principal: **não a contorne** com `|| true`.
  **As linhas de cada passo do migrate só saem quando o passo AGE.** Elas moram
  dentro das guardas de `information_schema` (`garantirColuna`, `garantirFk`, o
  `str_contains` que testa o ENUM), então "a linha do CRUZAMENTO não apareceu"
  significa "a produção já tinha", não "o passo foi pulado" — foi o que
  aconteceu em 2026-09-02, quando o banco de produção estava à frente do
  código. É a direção segura, e só é segura porque **toda migração deste
  repositório é aditiva**: cria tabela, coluna, índice, FK, alarga ENUM; nunca
  apaga nem estreita. Por isso o *rollback* de código (Railway → *Deployments*
  → deploy anterior → *Redeploy*) nunca precisa de rollback de schema — o
  código velho ignora a coluna nova. Quem quebrar essa regra quebra o rollback.
- O servidor é o embutido do PHP (`php -S` no `entrypoint.sh`). Hoje é o que
  roda em **produção**, para os usuários da cooperativa; php-fpm + nginx continua sendo
  a evolução recomendada quando a carga pedir (ver `docs/DEPLOY-RAILWAY.md`),
  não um bloqueio.
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
- **Os dois serviços de cron estão ligados em produção** — o cliente confirmou
  em 2026-09-01 (`44bd2ff`), e por isso os itens 0 e 6 do backlog saíram da
  fila. Os dois rodam a **mesma imagem** do web (o Dockerfile instala o
  `default-mysql-client` justamente para o `backup.sh` existir lá), como
  serviços separados, sem porta, cada um com *Custom Start Command* e *Cron
  Schedule* em *Settings*:

  | Serviço | Comando | Cron (UTC) | Em Brasília | Precisa de |
  |---|---|---|---|---|
  | `backup` | `./cli/backup.sh` | `0 7 * * *` | 04h | Volume em `/backups`, `BACKUP_DIR=/backups`, `BACKUP_MANTER=14` |
  | avisos | `php cli/notificar.php` | `0 11 * * *` | 08h | `SMTP_*`, `EMAIL_REMETENTE` |

  A Railway agenda em UTC, três horas à frente. *Serverless* fica desligado (a
  própria tela diz que não existe para serviço com cron) e *Teardown* também.
  **Sem o Cron Schedule o serviço vira servidor**: roda, termina em segundos, a
  Railway reinicia, e assim a noite inteira — gasta o crédito do plano e enche
  o log. O `backup.sh` confere o próprio arquivo e só o batiza (`.parcial` →
  `.sql.gz`) depois de íntegro; a linha do ✓ traz o tamanho e o número de
  tabelas, e é ela que se procura no log.
  **Configurado não é funcionando.** A aba *Settings* prova a configuração; a
  aba **Cron Runs** é o único lugar que prova a execução — falha de cron não
  acorda ninguém, por construção. E o log do plano Hobby dura **7 dias**: não
  dá para olhar três semanas atrás. Por isso a conferência é ritual mensal
  (passo 8 de `docs/DEPLOY-RAILWAY.md`: a cópia que fica no computador de
  alguém é a prova que não expira), e o tamanho do arquivo impresso no ✓ fecha
  a retenção — até 50 MB cabem 30 dias no volume de 5 GB do Hobby; 50–150 MB,
  14; 150–300 MB, 7; acima de 300 MB o caminho é tirar os anexos do banco, não
  encolher a retenção. O pico no disco é `BACKUP_MANTER + 1` arquivos, porque a
  faxina roda **depois** de gravar o novo.
  **Nunca force o backup com `*/5 * * * *`.** O truque de encurtar o cron para
  provar que roda, que o roteiro do e-mail usa, é inofensivo lá (a tabela
  `envio_email` não deixa mandar duas vezes) e **destrutivo aqui**: cada
  execução gera um arquivo, a faxina apaga tudo além dos 14 mais novos, e em 70
  minutos os 14 dias de histórico viram 14 cópias da última hora. Para uma
  execução avulsa use *Redeploy* no serviço `backup` (uma rodada, sem mexer no
  agendamento); se for mesmo preciso repetir, suba `BACKUP_MANTER` para 30
  antes e devolva depois.

## Convenções de entrega

- Branch de trabalho: **`claude/acesso-planejamento-controladoria-9pe28s`** —
  desenvolver, commitar e fazer `git push -u origin` sempre nessa branch, e
  levar ao `main` por pull request (o `main` é produção — ver *Deploy*). A
  branch anterior, `claude/git-repo-overview-d17774`, foi o PR #1 e está
  encerrada; `docs/ESTADO-IMPLEMENTACAO.md` ainda a cita porque é um
  **retrato datado** (2026-08-02), não o estado atual.
  **PR mesclado é PR terminado**: o trabalho seguinte reinicia a branch a
  partir do `main` (`git fetch origin main && git checkout -B <branch>
  origin/main`) em vez de empilhar commits sobre a história já mesclada — foi
  assim depois do PR #4, e um `push --force-with-lease` é aceitável quando a
  branch só continha o que já entrou.
  Nesta sessão o clone é raso: antes de comparar branches, busque com
  `+refs/heads/*:refs/remotes/origin/*` — um `git rev-list` sem as referências
  remotas devolve vazio e parece "nada pendente".
- Mensagens de commit em português, primeira linha descritiva.
- Ao concluir trabalho grande: rodar o time de agentes de revisão
  (segurança, corretude, infra, frontend, testes) e aplicar os achados
  confirmados; manter a responsividade mobile; rodar `./testes/rodar.sh`
  antes de commitar. Defeito corrigido vira **prova na bateria**, no mesmo
  commit: é o que impede que ele volte na refatoração seguinte sem ninguém
  notar. A última rodada é `docs/REVISAO-2026-09-04.md` — o que foi
  corrigido, o que espera decisão do cliente (seeds ilustrativos, força bruta
  no login, PIN de questionário longo, política das FKs de `acao_por`) e o
  que ficou como pendência técnica. Regras que nasceram dela e não devem
  regredir: `Database.php` carrega `Versao.php` (as CLIs escrevem sem
  autoloader); `Database::afetadas` também marca o pulso; todo caminho que
  apaga fator passa por `Fatores::apagar` (vozes dos cruzamentos que caem
  por CASCADE); a contenção de PIN roda ANTES de resolver o PIN nas rotas
  sem token (`PublicoController::exigirOrigemComFolga`); `SessaoBanco`
  implementa `validateId`; o 🎤 não assume tempestade com questionário
  (`QUESTIONARIO_ABERTO`); na funcional, guarda `if [ -n "$X" ]` só depois de
  um `afirma` que FALHE com `$X` vazio, e pular seção é falha, não ✓.
  Como a rodada foi feita, para repetir: cinco agentes em paralelo, um por
  frente, cada um com o mandato de **conferir o achado no código antes de o
  relatar** e de gravar o relatório num arquivo do scratchpad — o limite de
  uso da sessão caiu no meio, os cinco morreram por `429`, e o que estava em
  arquivo sobreviveu; o que estava só na resposta final se perdeu. Retomados
  por mensagem depois do limite voltar, terminaram de onde pararam. Achado
  vira correção **só com prova nova na bateria**, e a documentação
  (`docs/DEPLOY-RAILWAY.md`, este arquivo, o parecer) entra no mesmo PR.
- Acessibilidade que já custou defeito: as seções **não são destruídas** ao
  navegar (só ganham `d-none`), então id repetido entre telas coexiste no
  documento e o `for` do label casa sempre com o primeiro — ids de tela levam
  sufixo (`sel-ano-swot`) ou viram atributo (`data-novo-fator`). Botão de cor
  própria precisa de `--bs-btn-focus-shadow-rgb`, senão fica sem indicador de
  foco. Alvo de toque no celular cresce por **dimensão real**, nunca por
  `::after` sobreposto — áreas invisíveis de botões vizinhos se cobrem e o
  toque na fronteira vai para o errado.
- Roadmap e especificações: `docs/PLANEJAMENTO-SISTEMA.md` (fases 1–6 já
  entregues) e `docs/BACKLOG-EVOLUCAO.md` — o backlog vivo, com o veredito de
  o que vale ou não construir. **Estado em 2026-09-02: tudo entregue ou
  ligado, menos um item** — `4d`, a síntese dos Cruzamentos da SWOT (fatia 4,
  §6 de `docs/CRUZAMENTOS-SWOT.md`, esforço P, ordem 1). Os itens 0 (SMTP +
  cron) e 6 (backup) não foram construídos, foram **ligados** pelo cliente em
  2026-09-01, e viraram conferência periódica. O Mapa BSC com raias foi
  descartado nesta forma. Leia o backlog **na ponta da branch**: a cópia do
  `main` fica para trás entre um PR e outro, e foi dela que saiu uma
  recomendação de "ligar backup e SMTP" que já estavam ligados.
- `docs/DEPLOY-RAILWAY.md`: o roteiro de operação — serviços, variáveis,
  volume, crons, o que cada tela deve mostrar e a tabela de "se der errado".
  É escrito para o cliente executar sozinho; mudança de infraestrutura entra
  lá antes de entrar em conversa.
- `docs/REFATORACAO-GTD-COLETA.md`: o fluxo GTD da Coleta como ficou (matriz
  única, arraste, menu da pílula, saídas da ideia encaminhada), as decisões do
  cliente e os defeitos que a validação pegou. Leia antes de mexer na condução da
  tempestade.
- `docs/CRUZAMENTOS-SWOT.md`: o plano dos **cruzamentos (TOWS)** — a quinta tela
  do diagnóstico, o modelo de dados (tipo derivado do par, par único por ano),
  a ponte para a cascata e as cinco fatias de entrega. **Fatias 1, 2, 3 e 5
  entregues** (tabela, API e tela; o relatório do §7; o cruzamento que vira
  ação, §10; a sala que propõe o par pelo celular, §11 — decisão do cliente
  contra a recomendação, e o §11 registra o que a escolha obrigou). O §9
  guarda o que foi decidido na execução — entre outras coisas, que o
  `destino_tipo`/`destino_id` polimórfico do plano **não entrou** na tabela: a
  fatia 3 foi pelo mesmo caminho do fator da SWOT (`acao_em`, `acao_por`,
  `desdobramento_id`), e coluna que nada escreve é regra que ninguém testa.
  **Falta só a síntese** (§6): um campo de prosa por bloco ("o que este bloco
  diz ao planejamento") mais um geral, guardados por planejamento e ano,
  acima das colunas na tela e antes dos blocos no relatório — sem virar campo
  estruturado. É o item `4d` do backlog e o último pedaço da etapa. O §12
  lista o que ficou fora de propósito.

## Limites do ambiente de sessão

- O proxy da sessão **bloqueia `*.up.railway.app`** (`CONNECT tunnel failed,
  403`). Não é possível abrir o sistema em produção daqui nem conferir um
  deploy pela URL (não há rota de saúde própria; o Railway olha a porta). A
  verificação de deploy é feita
  pelo cliente — print das abas *Deployments*, *Deploy Logs* e *Cron Runs* — e
  a leitura desses prints é o trabalho. **Não afirme que "está no ar" sem uma
  dessas evidências**; o que dá para provar daqui é o estado do Git
  (`origin/main` contém o commit) e o conteúdo da migração.
- CDNs também são bloqueados — por isso o Bootstrap e o `qrcode.js` são
  vendorados, e o projeto não tem dependência PHP nenhuma de propósito.
- O Chromium do Playwright está em `/opt/pw-browsers` e o MariaDB local sobe
  com socket em `/tmp` (detalhes em *Rodando localmente*).
- O **limite de uso** da sessão pode acabar no meio de um trabalho longo e
  voltar depois. O que sobrevive é o que está em arquivo ou em commit; por
  isso relatórios de agentes vão para o scratchpad, e serviços locais sobem
  desprendidos do shell (`setsid nohup … & disown`) — os dois já custaram uma
  retomada.

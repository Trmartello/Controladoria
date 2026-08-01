# Backlog de evolução — Sistema de Planejamento Estratégico Copérdia

Consolidação das propostas de evolução discutidas depois das fases 1–6
(`docs/PLANEJAMENTO-SISTEMA.md`), já com os cortes de escopo aplicados após a
revisão adversarial de cada uma. Salvo onde marcado **ENTREGUE**, nada aqui foi
implementado — este documento é backlog, não registro de entrega.

**Como ler.** Cada tema traz: veredito, onde encaixa (sistema + menu), modelo de
dados concreto, telas e fluxo, entrega mínima, esforço, dependências/riscos e —
importante — **o que ficou de fora** depois da crítica. Quando o revisor cortou,
o corte foi adotado; o que a proposta original queria e não sobreviveu está
listado explicitamente, para ninguém reabrir a discussão sem contexto.

**Escala de esforço.** P = uma PR pequena, ~1 dia. M = 2–4 dias com validação
Playwright e revisão. G = uma semana ou mais.

**Aviso de completude.** Chegaram quatro propostas com crítica (impacto,
coleta/triagem, mapa BSC, contingência). O quinto tema — **ritual de
acompanhamento** — não veio com proposta nem revisão; a seção 5 foi escrita
diretamente da leitura do código (`app/Services/Avisos.php`, `cli/notificar.php`,
`RelatorioController`, `PlanejamentoController::checklist`) e deve ser tratada
como primeira versão, não como conclusão revisada.

---

## 1. Matriz de Impacto por Negócio

### Veredito: **CONSTRUIR SIMPLIFICADO** (esforço P)

É a única leitura transversal que o método promete e nunca entregou — hoje um
fator do PESTEL corporativo morre no PESTEL corporativo — e custa uma tabela,
um controller de dois métodos e uma seção JS.

### Onde encaixa

- **Método:** entre o diagnóstico corporativo e a SWOT de cada negócio. O
  corporativo lê o macro; a matriz diz *quem* sofre o quê.
- **Menu** (`views/shell.php`, bloco *Diagnóstico*): item novo **depois** de
  "Matriz GUT" — e não entre Porter e SWOT, como a proposta original queria.
  Motivo: a linha da matriz sai da SWOT corporativa priorizada pelo GUT, então
  o dado de entrada só existe depois do GUT.
  `<li><a class="nav-link" href="#impacto" data-secao="impacto">Impacto por Negócio</a></li>`
  Mais `<section id="secao-impacto" class="secao d-none"></section>` no `<main>`,
  `<script src="<?= versao_asset('/assets/js/secoes/impacto.js') ?>">` e
  `impacto: SecaoImpacto` em `App.recarregarSecaoAtiva()` (`app.js`).
- **Dono do dado:** o planejamento **CORPORATIVO** do ciclo. A matriz é anual
  (o ano vem do `fator`), usando `Diag.seletorAno()` / `Diag.ligarSeletorAno()`.
- **Comportamento por contexto** (é o que faz a tela valer para todo mundo):
  - contexto = Corporativo → grade completa, editável por ADMIN/CONTROLADORIA/DIRECAO;
  - contexto = um negócio → **só a coluna daquele negócio**, em lista, somente
    leitura: "o que o corporativo diz que me impacta neste ano".
  Essa segunda leitura é **inegociável**. Sem ela a tela vira um slide bonito que
  só a controladoria abre.

### Modelo de dados

Uma tabela em `database/schema.sql` (`CREATE TABLE IF NOT EXISTS` — o
`executarArquivoSql` do `migrate.php` já cobre, sem `garantirColuna`):

```sql
CREATE TABLE IF NOT EXISTS impacto_negocio (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  fator_id      INT NOT NULL,                       -- ameaça/oportunidade da SWOT corporativa
  negocio_id    INT NOT NULL,
  sinal         ENUM('POSITIVO','NEGATIVO') NOT NULL,
  texto         TEXT NULL,                          -- como impacta este negócio
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_impacto (fator_id, negocio_id),
  CONSTRAINT fk_imp_fator   FOREIGN KEY (fator_id)   REFERENCES fator(id) ON DELETE CASCADE,
  CONSTRAINT fk_imp_negocio FOREIGN KEY (negocio_id) REFERENCES negocio(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Duas decisões que sobreviveram inteiras à crítica:

1. **Nada de `planejamento_id` nem `ano` na tabela** — os dois vêm do `fator`,
   o que elimina qualquer chance de a célula divergir da linha.
2. **Não reusar `fator.promovido_de_id`** para o vínculo cross-planejamento.
   `FatorController::listar` faz `LEFT JOIN fator pr ON pr.promovido_de_id = f.id`;
   reusar o campo **multiplicaria** a linha do fator corporativo por negócio,
   duplicando cards no PESTEL.

**Linhas da matriz** (mudança adotada da crítica): não há curadoria própria. A
linha é o fator do plano corporativo com `etapa='SWOT'`,
`categoria IN ('OPORTUNIDADE','AMEACA')` e `ano = ?`, com
`LEFT JOIN gut ORDER BY score DESC`. A SWOT corporativa **já é** a lista curada,
e o GUT **já é** a priorização — curar de novo à mão seria uma segunda
priorização sem score e sem rastro.

**Rotas** (`app/Controllers/ImpactoController.php`, duas linhas no `switch`):

- `GET /api/impacto?ciclo_id=&ano=[&negocio_id=]`
- `POST /api/impacto` — upsert por `uk_impacto`; `sinal` vazio apaga a célula.

**Autorização** (a armadilha principal): o `GET` **não** pode usar
`Auth::exigirAcessoPlanejamento($planCorporativo)` — ele devolve 403 a GESTOR, e
o gestor é justamente quem mais precisa da coluna dele. Regra própria:
`Auth::veTudo()` → grade inteira; demais perfis → forçar
`negocio_id IN (escopoNegocios())` e devolver **só a descrição do fator, o sinal
e o texto da célula**, nunca o registro do fator inteiro. O `POST` usa
`Auth::exigirEdicaoPlanejamento($planCorporativoId)` **e** valida que o
`fator_id` pertence a esse plano (padrão `FatorController::exigirFator`) e que o
`negocio_id` está `ativo = 1`.

Sem seeds: a matriz é conteúdo do usuário.

### Telas e fluxo

- **Desktop, contexto corporativo:** `<table class="table table-bordered tabela-impacto">`
  dentro de `.table-responsive`. Primeira coluna (fator) `position: sticky; left: 0`.
  Cabeçalho das colunas com o **`cod_negocio`** (1, 2, 4, 6…) e o rótulo
  `cod - nome` completo no `title` — o formato oficial que `NegocioController::listar`
  e o seletor do menu já usam. Abreviar o nome não funciona: só 8 dos 10 nomes do
  `QlikSync::NEGOCIOS_FONTE` começam com "NEGOCIO ".
- **Célula = só o glifo** `▲` (verde) / `▼` (vermelho) / vazio, com `aria-label` e
  o texto no `title`. Cor sozinha não é acessível nem sobrevive à impressão em P&B.
  Coluna de ~64px: 10 negócios + a coluna fixa cabem em 1500px sem rolagem — a
  versão original, com 170px por coluna, dava ~1900px e não passaria na validação
  Playwright prometida.
- **Clique na célula → `Modal.abrir`** com `fator_id`/`negocio_id` ocultos,
  `sinal` (`tipo: 'botoes'`) e `texto` (textarea). Atenção: `tipo: 'botoes'`
  **ignora** `cor` nas opções (só `quadrantes` usa) — o verde/vermelho exige ~4
  linhas de CSS novo em `public/assets/css/app.css`.
- **Celular (<768px) e contexto = negócio:** `d-none d-md-block` na tabela e, logo
  abaixo, um bloco `d-md-none` com **lista de cards** (um por fator, com chips dos
  negócios impactados). **Não** reusar `Diag.ligarSeletorCategoriaMovel`: ele faz
  `col.classList.toggle('d-md-block', ...)`, e `.d-md-block{display:block!important}`
  aplicado a `<td>`/`<th>` desmonta a tabela no desktop — pior, o estado mora em
  `Diag.filtroMovel`, que é global e persiste.
- **Estado vazio:** "nenhuma ameaça/oportunidade priorizada no plano corporativo
  deste ano" com atalho para a seção SWOT/GUT.

### Entrega mínima

1. `impacto_negocio` no `schema.sql`.
2. `ImpactoController` com `listar()` + `salvar()`.
3. Duas rotas em `public/index.php`.
4. `public/assets/js/secoes/impacto.js` (grade + lista mobile + lista no contexto
   de negócio + seletor de ano via `Diag`).
5. `views/shell.php` + `app.js` + ~15 linhas em `public/assets/css/app.css`.

Validar em 1500×800 e 390×844, como manda o `CLAUDE.md`.

### O que ficou de fora (cortes adotados)

- **Ponte "Levar para a SWOT do negócio"** — a peça mais cara e a única escrita
  cross-planejamento do sistema. Como especificada era **impossível**:
  `Auth::exigirEdicaoPlanejamento` no plano corporativo devolve 403 a GESTOR,
  então "a decisão do gestor" viraria a controladoria escrevendo em 10 planos.
  E o gatilho escolhido (`modal-extra`) **descarta o formulário**:
  `Modal.executarExtra()` chama `aoClicar()` e `hide()`, nunca `coletar()`.
  Quando entrar (v2): botão na própria célula/card, `exigirAcessoPlanejamento`
  (leitura) no corporativo + `exigirEdicaoPlanejamento` no plano do negócio, e
  `fator_negocio_id` via `garantirColuna`.
- Curadoria de linhas (`negocio_id NULL`), coluna gerada `negocio_chave`, rotas
  `/impacto/linha`, `/impacto/{id}/excluir`, o modal "+ Fator na matriz" e o `×`
  por linha — todos mortos pela decisão de tirar as linhas da SWOT/GUT.
- `NEUTRO` (célula ausente já significa "sem impacto relevante"), peso/intensidade
  1–3, diário de bordo na célula, versionamento próprio.
- Extração de `PlanejamentoController::resolver()` — o `GET` só precisa de
  `SELECT id FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'`.
  Criar o planejamento corporativo como efeito colateral de um `GET` disparado
  por gestor é exatamente o que se quer evitar.
- Cartão no Hub e bloco no Relatório de Status — v2. Se algo do relatório voltar
  primeiro, que seja só a seção **do negócio** ("Fatores macro que impactam este
  negócio"), que é o que abre reunião; a matriz inteira no relatório corporativo
  é redundante com a própria tela.

### Riscos

- **Autorização do `GET`** é o ponto crítico (ver acima).
- **Mudança de política de acesso:** o gestor passa a ler descrições de fatores
  do plano corporativo. Hoje a regra é dura e está escrita em
  `docs/PLANEJAMENTO-SISTEMA.md` §5. É defensável, mas precisa ser **decisão
  explícita** e o documento precisa ser atualizado junto. (Ver "Decisões em aberto".)
- **Confusão de ano:** trocar o ano no seletor troca a matriz inteira. Repetir o
  aviso "a análise é anual" que o resto do diagnóstico já usa.
- **Sobreposição conceitual com a Cascata:** a matriz é diagnóstico (o que o
  ambiente faz com a gente); a cascata é escolha. Se a célula ganhar campos de
  "ação" ou "resposta", as duas telas viram a mesma coisa mal feita.

---

## 2. Quiz / brainstorm com tratativa item a item

### Veredito: **CONSTRUIR SIMPLIFICADO** (esforço M) — **ENTREGUE**

Entregue como especificado abaixo, antecipando a posição 6 da fila a pedido do
cliente. Uma ressalva registrada: a **decisão em aberto nº 2** (perfil LEITURA
pode gravar ideia?) continua sem resposta, então `Auth::exigirRespostaColeta()`
existe com nome próprio mas repete a regra de `exigirEdicaoPlanejamento` —
liberar LEITURA depois é trocar uma linha, sem afrouxar a regra geral.

A **coleta** tem substituto barato (Google Forms, planilha, a própria oficina) —
o que não tem substituto é a **tratativa**: hoje alguém pega a lista crua e
redigita à mão dentro de Cenário/PESTEL/SWOT, e o vínculo entre "o que o Fulano
disse na oficina" e "o fator que entrou no plano" se perde. É esse vínculo que
justifica construir, não o questionário.

### Faz sentido para o projeto? Sim, com uma ressalva

Faz, porque é o mesmo movimento que o sistema já faz uma etapa adiante:
`FatorController::promover()` copia descrição + ano para um fator SWOT novo,
grava `promovido_de_id`, e `diagnostico.js` desenha o selo clicável que volta à
origem. A coleta é isso **antes** do PESTEL: item cru → (cenário | fator | nada).

A ressalva é de calendário, não de mérito: **a coleta é sazonal**. Ela é usada
uma vez por rodada anual de diagnóstico. Se a próxima oficina estiver longe, esse
tema pode e deve esperar — construir agora significa deixar a fila parada por
meses, e a hipótese de risco do módulo é comportamental ("alguém vai mesmo triar
as ideias?"), não técnica.

### Onde encaixa

- **Menu:** primeiro item do bloco *Diagnóstico*, antes de "Análise de Cenário",
  rotulado **"Coleta de Ideias"** (`data-secao="coleta"`). É o passo 0.
- Arquivos: `app/Controllers/ColetaController.php`,
  `public/assets/js/secoes/coleta.js`, `<section id="secao-coleta">` no shell,
  chave `coleta: SecaoColeta` em `App.recarregarSecaoAtiva()`.
- **Hub:** uma linha no `checklist()` com `'itens'` = ideias ainda `NOVO` (o
  pendente é o que interessa) e **sem** `meta` — as outras linhas também não têm.
  Não usar denominador: o `checklist()` não filtra por ano e a coleta é anual;
  "42/165" somaria todas as rodadas de todos os anos do ciclo.

### Modelo de dados

**Uma tabela**, em `schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS coleta_item (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  ano              SMALLINT NOT NULL,
  autor_id         INT NOT NULL,
  texto            TEXT NOT NULL,                  -- como foi dito
  texto_tratado    TEXT NULL,                      -- o que vai ao destino
  destino_sugerido ENUM('CENARIO','PESTEL','PORTER','SWOT','NAO_SEI') NOT NULL DEFAULT 'NAO_SEI',
  situacao         ENUM('NOVO','ACEITO','DESCARTADO') NOT NULL DEFAULT 'NOVO',
  destino_tipo     ENUM('CENARIO','FATOR') NULL,
  destino_id       INT NULL,
  motivo           TEXT NULL,                      -- obrigatório no descarte
  triado_por       INT NULL,
  triado_em        DATETIME NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ci_plan (planejamento_id, ano, situacao),
  KEY idx_ci_destino (destino_tipo, destino_id),
  CONSTRAINT fk_ci_plan  FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_ci_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

O `planejamento_id` direto na tabela (em vez de resolver por JOIN numa
`rodada_coleta`) **mata o risco nº 1 do próprio módulo**: vazamento de escopo por
esquecer um `exigirItem()` em uma rota.

**`destino_sugerido` é preenchido pelo autor**, num select do modal da ideia
("Onde isso entra? Cenário / PESTEL / Porter / SWOT / Não sei"). Entrega o mesmo
ganho de velocidade na triagem (o botão certo já vem destacado) sem tabela de
perguntas, sem tela de administração de roteiro e sem seed. Quem escreveu a ideia
sabe melhor onde ela cai do que uma pergunta genérica.

**Rotas — 4:**

- `GET  /api/coleta?planejamento_id=&ano=`
- `POST /api/coleta` e `POST /api/coleta/{id}` (criar/editar a própria ideia)
- `POST /api/coleta/{id}/encaminhar`
- `POST /api/coleta/{id}/descartar`

Registrar as específicas **antes** de `/api/coleta/(\d+)$`, como já se faz com
`/api/fatores/(\d+)/promover`.

**Rastreio inverso — 2 `LEFT JOIN`, zero coluna nova:** `FatorController::listar`
e `CenarioController::listar` passam a expor `coleta_item_id` e o nome do autor,
e o card ganha o selo cinza "Coleta · Maria" ao lado dos selos PESTEL/GUT que já
existem. São ~6 linhas de SQL e ~4 de JS reusando `.selo-link`.

**Encaminhamento sem transação.** O repositório não usa `beginTransaction` em
lugar nenhum e `Json::erro()` é `: never` com `exit` — abrir transação aqui
introduziria um padrão novo justamente onde sair no meio é comum. Em vez disso,
**reserva atômica**: primeiro
`UPDATE coleta_item SET situacao='ACEITO', triado_por=?, triado_em=NOW() WHERE id=? AND planejamento_id=? AND destino_id IS NULL`
(checando linhas afetadas — exige um `Database::afetadas()` de ~5 linhas), e só
então o `INSERT` no destino + o `UPDATE` do `destino_id`. Isso mata a corrida do
duplo clique, que o `$jaPromovido` de `promover()` ainda tem, e o pior caso vira
um fator órfão em vez de um vínculo perdido.

### Telas e fluxo

Uma seção, **sem abas**:

1. **Minhas ideias** — botão "+ Nova ideia" → `Modal.abrir` com textarea (o
   microfone sai de graça: `Modal.renderCampo` já embrulha `textarea` em
   `.campo-voz` quando há suporte a voz) + o select `destino_sugerido`. Cada autor
   edita/exclui só as próprias ideias, e só enquanto `situacao = 'NOVO'`.
2. **A tratar** (só para quem edita) — **fila**, um card por vez: ideia crua,
   autor, data, e botões grandes. Cada botão abre o modal já com os campos do
   destino (o `Modal` não suporta campo condicional): Cenário (select tipo +
   textarea), PESTEL/Porter (select categoria + textarea), SWOT
   (`Diag.campoQuadrante()` + textarea), Descartar (**motivo obrigatório**,
   visível ao autor), Pular. O `destino_sugerido` destaca o botão provável.
3. **Tratadas** — mesmo bloco, filtro de situação, mostrando item → destino com
   link que abre o registro.

Mobile-first: a triagem acontece em reunião, muitas vezes no celular.

### Entrega mínima — feita

1 tabela + `ColetaController` (listar/salvar/encaminhar/descartar) + 4 rotas +
`coleta.js` + item de menu + os 2 selos de origem + a linha no hub.
O pré-requisito do destaque no Cenário (`data-card-fator` + `aplicarDestaque`)
foi feito junto, e `Database::afetadas()` entrou para a reserva atômica.

**Pré-requisito de 2 linhas, obrigatório:** hoje `SecaoCenario` não emite
`data-card-fator` nos cards nem chama `Diag.aplicarDestaque` — só PESTEL/Porter,
SWOT e GUT fazem. Sem isso o link do rastreio para um item de cenário abre a
seção e não rola até nada, quebrando a promessa central do módulo.

### O que ficou de fora (cortes adotados)

- **`rodada_coleta` e `rodada_pergunta` inteiras**, com o CRUD de perguntas, o
  roteiro padrão de 11 perguntas, `situacao RASCUNHO/ABERTA/ENCERRADA` e a
  administração de roteiro. Duas tabelas e onze rotas a menos.
  (De quebra some o argumento circular "15 pessoas × 11 perguntas = 165 itens",
  que justificava metade da complexidade da tela — os 165 itens eram consequência
  do roteiro que a própria proposta ia construir.)
- **Duplicados/mesclagem** e o contador de convergência, **reabrir**,
  `por_voz` (o `Modal.coletar()` lê só `el.value`; marcar a origem do ditado
  exigiria alterar `modal.js`, e a coluna não era usada em lugar nenhum).
- **Destinos AÇÃO e INVESTIMENTO** — `salvarDesdobramento` exige `o_que` e `quem`,
  e investimento exige `valor NOT NULL`; os dois travam a fila. v2.
- **`rodada_participante`** (convite/cobrança de quem não respondeu). v2.

### Riscos

- **LEITURA gravando seria a primeira exceção ao modelo de autorização em todo o
  código** — `Auth::exigirEdicaoPlanejamento` barra LEITURA antes de qualquer
  verificação, e hoje nenhuma rota de escrita a aceita. É a decisão certa de
  produto (brainstorm amplo sem inflar perfis de escrita), mas tem de virar um
  método nomeado — `Auth::exigirRespostaColeta()` — amarrando ano aberto +
  autoria própria, nunca um afrouxamento de `exigirEdicaoPlanejamento`.
  (Ver "Decisões em aberto".)
- **Ano errado no destino:** o item herda o `coleta_item.ano`, nunca o ano do
  seletor `Diag`. Herdar do seletor corrompe a análise anual em silêncio.
- **Órfãos:** se alguém apagar o fator na SWOT, o `coleta_item` fica apontando
  para id morto e o rastreio exibe link quebrado. `FatorController::excluir`
  precisa limpar `destino_tipo`/`destino_id` (ou a listagem tolerar o JOIN vazio
  exibindo "destino removido").
- **Atrito social:** o autor aparece ao lado da ideia e a ideia pode ser
  descartada publicamente. Motivo obrigatório e visível ao autor é o que
  transforma veto silencioso em aprendizado — e é o que dá legitimidade ao
  processo.

---

## 2.1 Tempestade de ideias: quiz por QR, separação e priorização

### Situação: **A PLANEJAR** — não implementado

Revisão do tema 2 pedida pelo cliente **depois** da entrega da Coleta. Não
substitui o que está no ar: reaproveita o modelo de dados e a tratativa, e
acrescenta a sessão ao vivo, a separação das ideias e a matriz de priorização.

> **Como o cliente descreveu.** "Fazer via quiz, onde os usuários escaneiam o
> código e vão dando as ideias, numa tempestade de ideias. E aí o administrador
> vai selecionando as palavras e destinando, se será tratado, sim ou não,
> colocando dentro da matriz de priorização." E depois: "separar as palavras,
> complementar o texto, classificar conforme priorização, e decidir se os
> assuntos serão esquecidos ou direcionados para uma análise PESTEL, SWOT,
> Porter."

### O fluxo em cinco passos, e o que já existe

| # | Passo | Situação hoje |
|---|-------|---------------|
| 1 | **Tempestade** — participantes escaneiam o QR e disparam ideias | **falta tudo**: rodada, código, QR, tela de quiz |
| 2 | **Separar as palavras** — quebrar o despejo bruto em ideias distintas | **falta**: ação "dividir ideia" (1 bruta → N itens) |
| 3 | **Complementar o texto** — redigir o enunciado que vai ao diagnóstico | **existe**: `coleta_item.texto_tratado`, no modal de encaminhamento |
| 4 | **Classificar por priorização** — posicionar na matriz | **falta**: impacto × esforço no próprio item |
| 5 | **Decidir** — esquecer ou direcionar a PESTEL/SWOT/Porter/Cenário | **existe**: `encaminhar` / `descartar` com motivo |

Ou seja: os passos 3 e 5 estão prontos e testados. O trabalho novo é 1, 2 e 4 —
e o passo 1 é o que carrega quase todo o risco.

### Decisões que precisam ser tomadas antes de escrever código

**A. Quem pode responder ao quiz?** É a decisão que define a arquitetura.

- **A1 — só usuário cadastrado.** O QR abre a rodada; quem não estiver logado
  faz login e volta. Zero mudança no modelo de autorização. Custo: todo mundo
  na sala precisa de conta, o que atrapalha uma oficina com convidados.
- **A2 — código da rodada + nome digitado**, sem senha (modelo Mentimeter).
  É a experiência que o cliente descreveu. Custo real: seria **a primeira rota
  de escrita sem autenticação em todo o sistema**. Exige, no mínimo, rodada com
  abertura e encerramento explícitos, código curto e descartável, limite de
  envios por origem, e escrita restrita a `coleta_item` de uma rodada aberta.
  O "autor" passa a ser texto digitado, não usuário — e o motivo do descarte
  deixa de ter um destinatário garantido.
- **A3 — anônimo.** Quebra a premissa que sustenta a tratativa: sem autor, o
  descarte com motivo visível vira veto sem destinatário. **Não recomendado.**

**Recomendação: A2**, com a ressalva de que ela abre um caminho de escrita não
autenticado e por isso merece revisão de segurança própria antes de subir.
Nada impede começar por A1 e migrar: o que muda é o portão, não o resto.

**B. O que exatamente é "separar as palavras"?** Duas leituras, e elas levam a
telas diferentes:

- **B1 — dividir a ideia.** O participante despeja um parágrafo; o
  administrador quebra em N ideias separadas, cada uma seguindo seu caminho.
  É o que combina com "complementar o texto" logo depois.
- **B2 — nuvem de termos.** Extrair as palavras recorrentes para ver
  convergência ("cinco pessoas falaram em *frete*"). É visual de oficina, não
  altera o dado.

**Recomendação: B1 como funcionalidade** (uma ação `dividir` que cria itens
filhos apontando para o pai) **e B2 como leitura opcional depois**, se sobrar
fôlego. B2 sozinho não resolve nada do fluxo.

**C. A matriz de priorização daqui conflita com a Matriz GUT?** É o risco
conceitual do tema. O backlog já rejeitou, no tema 1, "uma segunda priorização
sem score e sem rastro". A saída que mantém coerência:

> A matriz da Coleta é **instrumento de oficina**: serve para decidir, na hora,
> o que vale tratar primeiro. Ela vive **dentro da Coleta** e morre quando a
> ideia vira fator — daí em diante quem prioriza é a GUT, com score e rastro.
> O impacto e o esforço **não** são copiados para o `fator`.

Se essa fronteira não for respeitada, o sistema passa a ter duas priorizações
concorrentes e a GUT perde sentido.

**D. A rodada volta ao escopo.** `rodada_coleta` foi **cortada** na revisão do
tema 2 — o QR a traz de volta, porque um código para escanear é, por definição,
o identificador de uma sessão com início e fim. O que **continua cortado** é o
roteiro de perguntas, o CRUD de perguntas e os participantes convidados.

### Modelo de dados — só o delta

```sql
CREATE TABLE IF NOT EXISTS coleta_rodada (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id INT NOT NULL,
  ano             SMALLINT NOT NULL,
  tema            VARCHAR(180) NOT NULL,     -- a pergunta que abre a tempestade
  codigo          VARCHAR(12) NOT NULL,      -- o que vai no QR
  situacao        ENUM('ABERTA','ENCERRADA') NOT NULL DEFAULT 'ABERTA',
  criado_por      INT NOT NULL,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  encerrada_em    DATETIME NULL,
  UNIQUE KEY uk_rodada_codigo (codigo),
  KEY idx_rodada_plan (planejamento_id, ano, situacao),
  CONSTRAINT fk_rod_plan FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Em `coleta_item`, quatro colunas via `garantirColuna()`:

- `rodada_id INT NULL` — nulo mantém funcionando tudo que já foi coletado;
- `dividido_de_id INT NULL` — o pai, quando a ideia nasceu de uma separação;
- `impacto ENUM('ALTO','BAIXO') NULL` e `esforco ENUM('BAIXO','ALTO') NULL`.

E um estado novo em `situacao`: **`SELECIONADO`**, entre `NOVO` e `ACEITO` —
"vai ser tratada, ainda não tem destino". É o que o cliente chamou de decidir
"sim ou não" antes de escolher para onde vai. `NÃO` continua sendo
`DESCARTADO`, com o motivo obrigatório que já existe.

### Telas

1. **Abrir rodada** (controladoria): tema, e pronto. A tela mostra o QR grande,
   o código por extenso (para quem não conseguir escanear) e o contador ao
   vivo. Botão "Encerrar" fecha a entrada.
2. **Quiz** (participante, celular): o tema no topo, uma caixa de texto, botão
   enviar, e abaixo só as próprias ideias, para poder corrigir. Sem lista dos
   outros durante a tempestade — ver o que os outros escreveram ancora o grupo
   e reduz a diversidade, que é justamente o que a tempestade busca.
3. **Separar e complementar** (controladoria): a ideia bruta, botão "dividir"
   que abre N campos, e o texto tratado de cada uma.
4. **Matriz de priorização**: 2×2 impacto × esforço com as ideias selecionadas
   como fichas; um toque move de quadrante. É a mesma leitura já registrada na
   seção "Leitura impacto × esforço" deste documento.
5. **Direcionar**: a fila que já existe, agora alimentada pelo quadrante — as
   fichas de "fazer agora" aparecem primeiro.

### Risco técnico já medido: o QR

Foi feita uma tentativa de escrever o gerador de QR em JavaScript puro (o
ambiente bloqueia CDN e o projeto não usa Composer nem npm). Resultado da
verificação contra uma implementação de referência:

- **codificação de dados e Reed-Solomon: corretos** — os 26 codewords de um
  caso de teste batem byte a byte com a referência;
- **seleção de versão: correta** — os cinco casos escolheram a mesma versão;
- **montagem da matriz: com defeito** — nenhuma das 8 máscaras reproduz a
  referência, e o defeito não foi isolado numa primeira investida. O rascunho
  ficou fora do repositório de propósito.

Orçar o QR como **tarefa de risco médio**, não como detalhe. Alternativas se
ele custar caro: mostrar só o código curto e a URL para digitar; ou gerar o QR
uma vez, fora do sistema, e colar como imagem na tela da rodada.

### Fatiamento sugerido

| Fatia | Conteúdo | Esforço | Entrega valor sozinha? |
|---|---|---|---|
| 1 | Rodada + tela de quiz + entrada por código digitado (sem QR) | P | Sim — já dá para rodar a oficina |
| 2 | Separar ideia (`dividir`) + estado `SELECIONADO` | P | Sim — organiza o despejo bruto |
| 3 | Matriz de priorização 2×2 | P | Sim — é a decisão da oficina |
| 4 | QR na tela da rodada | P–M | Só conveniência sobre a fatia 1 |
| 5 | Entrada sem login (decisão A2) | M | Depende de revisão de segurança |
| 6 | Nuvem de termos (B2) | P | Opcional |

Fatias 1 a 3 entregam o fluxo inteiro que o cliente descreveu, com o código
digitado no lugar do escaneado. A 4 e a 5 são o que transforma em experiência
de oficina — e são, também, onde mora todo o risco.

---

## 3. Mapa Estratégico BSC e as 4 perspectivas

### Veredito: **NÃO CONSTRUIR o mapa** / **CONSTRUIR SIMPLIFICADO a Matriz de Execução** (esforço P)

O que falta de verdade não é o desenho das 4 raias — é o **vínculo entre
indicador e projeto**, que não existe no banco (`projeto` só tem `cascata_id`,
`horizonte_id` e `impacto`); e ele se resolve com uma tabela N:N dentro da
Cascata, sem entidade nova, sem controller novo e sem item de menu.

### Em que momento entra e vale a pena?

**Momento:** a jusante da escolha — Kaplan/Norton é tradução de estratégia já
decidida, então o mapa só faria sentido entre a Cascata e Metas/Projetos.
**Vale a pena?** A *matriz de 4 colunas* (Perspectiva & Objetivo | Indicadores |
Metas | Iniciativas), que é o pedido operacional real, vale e é barata. O *mapa
com raias e setas de causa-e-efeito* **não vale agora**, por três motivos:

1. **A caixa do mapa já existe no banco: é a `cascata_escolha`.** Driver ×
   horizonte × eixo já é "o objetivo desta raia neste horizonte", já traz a
   **renúncia** (que o BSC não tem) e já traz os fatores SWOT/GUT que a
   fundamentam (`cascata_fator`, com `ORDER BY g.score DESC`). Criar
   `objetivo_estrategico` ao lado disso é criar uma segunda estratégia que
   ninguém concilia.
2. **A evidência do próprio repositório é contra mais uma classificação.** O item
   de execução já carrega horizonte + driver + eixo, e `projeto` ainda tem
   `cascata_id` e `impacto ENUM('RENTABILIDADE','FATURAMENTO','SUSTENTABILIDADE','PESSOAS')`
   — que são, na prática, quatro perspectivas. **Ambos estão mortos**:
   `ProjetoController::salvar` não lê nem grava nenhum dos dois, e `modalProjeto`
   não tem os campos (verificado). Duas classificações de execução já foram
   abandonadas por atrito de preenchimento; a perspectiva seria a terceira.
3. **Não há nada a portar do BSC de referência.** Em
   `/workspace/bsc-coperdia/apps/api/prisma/schema.prisma`, `IndicatorMap.flowData`
   é um blob JSON do ReactFlow e `IndicatorMapEntry` guarda `positionX/positionY`
   por indicador. Não existe perspectiva modelada. E um mapa guardado como JSON
   não responde "quais objetivos estão sem KPI?" — que é exatamente o valor de
   controladoria da coisa.

Sem as setas, um "mapa" é uma lista agrupada em quatro faixas. Seja honesto com a
diretoria: **a entrega é a matriz, não um mapa Kaplan/Norton.**

### Onde encaixa

- **Aba nova dentro da seção Cascata** (`cascata.js`), rotulada **"Matriz de
  Execução"**, ao lado da matriz de escolhas. Custo em `shell.php`: zero. Em
  `app.js`: zero. No hub: zero. E coloca a leitura ao lado da decisão.
- Promover a item de menu próprio depois é 1 linha, se o uso justificar.

### Modelo de dados

**Uma tabela**, clone literal de `cascata_fator`:

```sql
CREATE TABLE IF NOT EXISTS indicador_cascata (
  indicador_id INT NOT NULL,
  cascata_id   INT NOT NULL,
  PRIMARY KEY (indicador_id, cascata_id),
  CONSTRAINT fk_ic_ind FOREIGN KEY (indicador_id) REFERENCES indicador(id) ON DELETE CASCADE,
  CONSTRAINT fk_ic_cas FOREIGN KEY (cascata_id) REFERENCES cascata_escolha(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Preenchida por um multiselect **"Escolhas que este indicador mede"** no modal de
indicador que já existe (`metas.js`), gravado em `IndicadorController::salvar`
com a mesma validação de escopo que `CascataController` já faz ao inserir em
`cascata_fator`. **Zero rota nova** — `POST /api/indicadores` e
`/api/indicadores/{id}` já existem.

**Micro-entrega que fecha a outra metade do vão (~15 linhas):** acrescentar o
select "Escolha da cascata que origina este projeto" em `modalProjeto`
(`projetos.js`) e persistir `cascata_id` em `ProjetoController::salvar`,
validando que a escolha é do mesmo planejamento. **O JOIN já existe**
(`LEFT JOIN cascata_escolha ce ON ce.id = p.cascata_id`) e **a exibição já
existe** (`projetos.js` mostra "↳ Escolha da cascata: …") — hoje o campo
simplesmente nunca tem valor.

**Fonte de dados da matriz:** ampliar `CascataController::listar`
(`GET /api/cascata`, já consumida por `cascata.js` e `projetos.js`) para devolver,
por escolha, os indicadores vinculados e os projetos com aquele `cascata_id`.
Nenhuma rota nova, nenhum controller novo.

**As raias, na v1: agrupar por EIXO** (6 eixos, já cadastrados, já ordenáveis por
arrastar-e-soltar, já curados pelo usuário). Se as 4 perspectivas forem
inegociáveis para a diretoria, a forma barata é
`garantirColuna eixo.perspectiva VARCHAR(40) NULL` + um select no modal de eixo
que já existe — **uma coluna e um campo**, contra tabela + controller + cadastro
+ 4 rotas. Cor por posição em CSS (`.raia-1`..`.raia-4`), sem coluna `cor`.

### Telas e fluxo

Tabela agrupada por eixo (`rowspan` na primeira coluna, padrão que `metas.js` já
usa), dentro de `.table-responsive`:

| Eixo & Escolha (com a renúncia) | Indicadores | Meta / Real | Iniciativas (projetos) |

- Coluna **Meta/Real** deve reusar a regra que `metas.js` já aplica: **último real
  lançado** e, na falta dele, a **primeira meta futura** — e não "meta do ano
  corrente". O ciclo semeado é 2027–2035 e as metas começam em 2027: com a regra
  do "ano corrente", em 2026 toda linha da matriz mostraria "—" e a tela nasceria
  vazia.
- Coluna **Iniciativas** lista os projetos com o badge de status.
- Custo de leitura: cuidado com N+1 sobre N+1 (objetivo → KPIs → metas versionadas
  com `MAX(versao_meta)` → projetos). Montar em 3 queries agregadas, não em laço.

### Entrega mínima

1. `indicador_cascata` no `schema.sql`.
2. Multiselect no modal de indicador + gravação com validação de escopo em
   `IndicadorController::salvar`.
3. Select `cascata_id` em `modalProjeto` + persistência em `ProjetoController::salvar`.
4. Ampliação de `CascataController::listar` + aba "Matriz de Execução" em `cascata.js`.

### O que ficou de fora (cortes adotados)

- **Tudo o que a proposta original queria construir**: tabelas `perspectiva`,
  `objetivo_estrategico`, `objetivo_projeto`, `objetivo_relacao`;
  `ObjetivoController.php`; 4 rotas novas; a ampliação dos regexes para
  `(drivers|eixos|perspectivas)`; item de menu; `<section>` no shell; registro em
  `App.recarregarSecaoAtiva()`; entrada no checklist do hub; cadastro de
  Perspectivas.
- **As setas de causa-e-efeito** (`objetivo_relacao` + overlay SVG). Tecnicamente
  são viáveis sem biblioteca (~60–80 linhas com `getBoundingClientRect()` + `<path>`
  bezier + `<marker>`), e a análise de que **não** se deve fazer drag-and-drop com
  `positionX/positionY` está certa. Mas só faz sentido depois que existirem caixas
  que alguém cadastrou — e, em 390px, o valor da seta é zero de qualquer jeito.
- **Bug evitado pelo corte:** a FK `objetivo_estrategico.cascata_id` (sem
  `ON DELETE`) quebraria `CascataController::excluir`, que hoje faz
  `UPDATE projeto SET cascata_id = NULL WHERE cascata_id = ?` **exatamente porque**
  a FK não tem `ON DELETE`. Sem replicar esse `UPDATE`, excluir uma escolha
  passaria a estourar erro 1451 → "Erro interno de banco de dados".

### Riscos

- **IDOR nos vínculos N:N:** `Auth::exigirEdicaoPlanejamento` valida o
  planejamento, não os filhos. Sem checar que `cascata_id` pertence ao mesmo
  planejamento, um GESTOR vincula escolhas de outro negócio passando o id.
  O padrão já existe no `CascataController` — copiar.
- **Terceiro lugar para "a métrica que importa":** já há o driver Métrica-Âncora
  na cascata e `indicador.metrica_ancora`. A matriz **só lê** `indicador`; se a
  coluna KPI virar cadastro próprio, viram três fontes divergentes.
- **Texto longo na caixa:** `cascata_escolha` é textarea. Se ficar ilegível como
  rótulo, a evolução barata é `garantirColuna cascata_escolha.titulo VARCHAR(120) NULL`
  — uma coluna, não uma tabela.

---

## 4. Plano de Contingência

### Veredito: **CONSTRUIR SIMPLIFICADO** (esforço M)

Ancorado na **ameaça da SWOT já priorizada pelo GUT** — não dentro de cada
projeto — porque o risco estratégico da Copérdia (dólar, preço do leite, clima,
crédito) atravessa o negócio inteiro e o sistema já tem o registro de riscos e a
priorização; o que falta é a **resposta datada com gatilho verificável**.

### A hipótese "dentro de cada projeto", avaliada

**Rejeitada como casa principal**, por quatro motivos:

1. **Grão errado.** O risco existe mesmo sem projeto algum. Dentro do projeto ele
   ficaria repetido em N projetos ou órfão.
2. **Já existe um registro de riscos priorizado**: `fator` com `etapa='SWOT'` e
   `categoria='AMEACA'` + `gut.score` (1–125, com ranking). Criar uma segunda
   lista de riscos que ninguém concilia é o pior desfecho possível.
3. **Competiria com o motor de execução que já existe.** Projeto → iniciativa →
   ação 5W2H tem status automático pela data-limite, prioridade, progresso e
   diário. Um campo risco/gatilho/resposta dentro do projeto seria um campo morto:
   sem situação, sem data de checagem, um risco só por projeto. O caminho correto
   é o inverso — quando o gatilho dispara, **a resposta vira um projeto**.
4. **Governança.** A reunião roda pelo Relatório de Status. Risco escondido numa
   aba de projeto não é revisado; risco na pauta é revisado toda reunião.

**Concessão:** a contingência dentro do projeto **não está descartada, está
derivada**. Quando alguém pedir, o padrão de `MODIFY COLUMN` idempotente já
provado no `migrate.php` transforma `fator_id` em referência polimórfica sem
quebrar nada.

**Uma correção de premissa, para o registro:** a afirmação de que "o ranking GUT
morre e nada é feito com a ameaça de score alto" é **falsa**. O vínculo
ameaça → escolha existe e é explícito (`cascata_fator`, `CascataController::listar`
com `ORDER BY g.score DESC`, o multiselect "Fatores que fundamentam (SWOT/GUT)"
e o badge "GUT nn" na célula). O buraco real é mais estreito e continua válido:
**não existe resposta datada, com gatilho e com checagem verificável.**

**Sobre as outras âncoras avaliadas:** envelope de capital (`envelope_capital.regras`
já guarda guard-rails; vira referência depois, se pedirem) e indicador fora da
meta — o modelo mais bonito e o mais inviável agora, porque em `indicador_valor`
o real é **anual e digitado à mão**: comparar meta × real dispararia uma vez por
ano, depois do ano fechado. Isso é post-mortem, não contingência. Só quando
houver série mensal vinda do Qlik.

### Onde encaixa

- **Bloco no fim da própria Matriz GUT** ("Respostas às ameaças priorizadas"),
  reusando `Diag.preparar`/`seletorAno` e a chamada `/api/fatores?etapa=SWOT&ano=`
  que `SecaoGut` já faz. **Sem seção nova, sem item de menu, sem `<section>` no
  `shell.php`, sem linha em `app.js`.** O botão fica a um toque do ranking que
  motivou o plano.
- Promover a seção própria só quando a lista passar de ~10 planos — e aí no bloco
  **Gestão** (ao lado do Relatório de Status), não em Diagnóstico: contingência é
  acompanhamento, não diagnóstico.
- **Relatório de Status:** nova seção "6. Riscos e contingências", ao fim, sem
  renumerar as existentes. É o que faz o ritual acontecer.

### Modelo de dados

```sql
CREATE TABLE IF NOT EXISTS contingencia (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id INT NOT NULL,
  fator_id        INT NULL,                 -- ameaça de origem (NULL = risco avulso)
  risco           TEXT NULL,                -- só no avulso; com fator_id vem do JOIN
  gatilho         TEXT NOT NULL,            -- sinal observável + limiar
  fonte_gatilho   VARCHAR(120) NULL,        -- onde se confere (CEPEA, BCB, Qlik, contrato)
  responsavel     VARCHAR(255) NOT NULL,
  resposta        TEXT NOT NULL,
  situacao        ENUM('MONITORANDO','ACIONADO','ENCERRADO') NOT NULL DEFAULT 'MONITORANDO',
  verificado_em   DATE NULL,                -- última checagem na reunião
  acionado_em     DATE NULL,
  CONSTRAINT fk_cont_plan  FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_cont_fator FOREIGN KEY (fator_id) REFERENCES fator(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Dez colunas em vez de dezesseis. Quatro decisões, todas vindas da crítica:

- **`fator_id` com FK `ON DELETE CASCADE` no lugar de `ref_tipo`/`ref_id`
  polimórficos.** O polimorfismo nasceria com 6 valores dos quais 5 nunca seriam
  gravados, e era ele que obrigava a copiar o `validarRef` do `DiarioController`
  (~25 linhas), criava a **única brecha real de segurança** da feature (escopo por
  negócio via `ref_id` forjado) e deixava órfãos ao excluir o fator. Um campo
  resolve os três.
- **Sem `severidade`.** Duplicaria o score GUT da própria ameaça de origem —
  `SecaoGut.corScore` já classifica em três faixas (≥64 alta, ≥27 média, resto).
  Duas escalas de gravidade sem conciliação é exatamente o defeito que a feature
  diz querer evitar.
- **Sem copiar `risco` quando há `fator_id`.** `FatorController::salvar` atualiza
  `fator.descricao` sem tocar em mais nada; a cópia congelada divergiria no
  primeiro edit e a tela mostraria dois textos para a mesma ameaça.
- **Sem coluna `ano`.** A contingência pertence ao **planejamento (ciclo)**, não
  ao ano — o ano do fator de origem já aparece como rótulo ("ameaça priorizada em
  2027"). Isso resolve de graça o "sumiu tudo em 2028" e dispensa o botão "repetir
  no próximo ano". É o ponto de maior atrito com o método (o diagnóstico é refeito
  por ano **por desenho**), e vale registrar a exceção no `CLAUDE.md` junto com a
  regra dos horizontes.

**Rotas — 4:** `GET /api/contingencias?planejamento_id=`, `POST /api/contingencias`,
`POST /api/contingencias/{id}` (o `salvar($id)` já cobre a checagem — o modal curto
manda só `situacao` + `verificado_em`), `POST /api/contingencias/{id}/excluir`.
Controller de ~90 linhas no padrão do `FatorController`.

Sem seeds.

### Telas e fluxo

- **Bloco no fim do GUT:** lista ordenada por situação (ACIONADO → MONITORANDO →
  ENCERRADO) e depois por score GUT. Cada cartão: badge de situação, badge do score
  GUT com a cor do quadrante, a **ameaça** (do JOIN), o **gatilho** em destaque com
  a fonte, o responsável, "última checagem: dd/mm/aaaa", a **resposta**. Acima,
  "Ameaças graves sem plano" — `AMEACA` com `score >= 64` e sem contingência, cada
  uma com "+ Criar plano". É o gancho que faz a lista se preencher sozinha.
- **Botão nos cartões AMEACA do GUT** — atenção: o cartão inteiro já é
  `data-avaliar role="button" tabindex="0"` e o listener captura tudo que borbulha;
  como o `Modal` é singleton, sem `stopPropagation` **no clique e no keydown**
  (Enter/Espaço) o modal de contingência abre e é imediatamente sobrescrito pelo de
  Avaliação GUT. Não sai de graça.
- **Modal** (`Modal.abrir`): `gatilho` textarea obrigatório com ajuda "escreva um
  limiar verificável: o quê, quanto e por quanto tempo — ex.: saca do milho acima
  de R$ 90 por 30 dias corridos"; `fonte_gatilho`; `responsavel` do tipo
  `selecao_livre` alimentado por `/api/responsaveis` (igual ao 5W2H); `resposta`;
  `situacao`. Campo `tipo: 'info'` no topo com a ameaça de origem.
- **Na reunião:** plano a plano, "o gatilho disparou?" → botão "Registrar checagem"
  → grava `verificado_em` (+ `acionado_em` quando vira ACIONADO). Um clique por
  risco; é isso que mantém o plano vivo.
- **Relatório, seção 6:** Risco | Gatilho (com a fonte) | Situação | Responsável |
  Última checagem | Resposta. Acima da tabela, a linha que cobra o ritual:
  **"N planos · X acionados no período · Y sem checagem desde \<de\>"**, calculada
  na leitura — mesmo padrão declarado no `ProjetoController` ("a reconciliação
  acontece na leitura, sem agendador"). Sem coluna "Severidade" (é o GUT), para
  caber no mobile e no `.xls`.

### Entrega mínima

Tabela de 10 colunas + `ContingenciaController` (~90 linhas) + 4 rotas + bloco no
fim de `SecaoGut` com o modal e o botão (com `stopPropagation`) + seção 6 no
Relatório de Status (tela + `.xls`). ~55% da superfície da proposta original, com
o mesmo valor: ameaça priorizada → gatilho verificável → resposta → checagem
datada cobrada pelo relatório.

**Alternativa ainda menor (fatia 0, ~1/5 do custo)** — recomendada **se o ritual
mensal ainda não estiver rodando**: três colunas em `fator` via `garantirColuna`
(`gatilho TEXT NULL`, `resposta TEXT NULL`, `verificado_em DATE NULL`), dois
textareas e um campo de data a mais no modal de Avaliação GUT reaproveitando
`POST /api/fatores/{id}/gut`, e um bloco no relatório listando ameaças com
`score >= 64` e suas respostas. Zero tabela, zero controller, zero rota, zero
mexida no `shell.php`. Testa a única hipótese que pode matar a feature, que é
comportamental ("alguém vai mesmo checar todo mês?"). Se em dois ciclos de reunião
as colunas estiverem preenchidas e faltar histórico/acionamento, promove-se para
tabela com um `INSERT ... SELECT` idempotente no `migrate.php`.

### O que ficou de fora (cortes adotados)

- `ref_tipo`/`ref_id` polimórfico, `severidade`, `ordem`, `risco` no caminho
  padrão, `ano`, `EM_ALERTA` (não muda nenhuma decisão nem filtro — quem está
  preocupado escreve na observação da checagem).
- **`projeto_id`** (o projeto de resposta). Some junto o defeito: a FK sem
  `ON DELETE` derrubaria `ProjetoController::excluir` com 500, porque o método só
  limpa `investimento.projeto_id`. Quando entrar, entra com `garantirColuna` **e**
  a linha `UPDATE contingencia SET projeto_id = NULL WHERE projeto_id = ?` ao lado
  da de investimento.
- **Seção própria + item de menu + `<section>` + registro em `app.js`.**
- **Cartão no Hub** — exige levar `ano` até `/api/contexto`, `checklist()` e
  `hub.js`, que hoje não têm ano nenhum (verificado: `checklist(int $planId, int $cicloId)`).
  Não são "6 linhas". E o sinal "ameaça grave sem plano" já aparece dentro do GUT,
  que é onde a pessoa está quando prioriza.
- Diário de bordo em contingência, contador no Painel, e a variante "indicador
  fura a meta" — todos v2+.

### Riscos

- **Gatilho vago mata a feature.** "Se o mercado piorar" é inverificável. O
  servidor só valida não-vazio; o resto é disciplina da Controladoria na primeira
  rodada de preenchimento.
- **Não prometer alerta automático.** Não há cron no app (o `entrypoint.sh` roda
  migrate + `php -S`) e o Qlik hoje só alimenta a lista de negócios. O vocabulário
  da UI é **"checagem na reunião"**. (Ressalva importante: a infra de e-mail
  **não** é zero — ver o tema 5.)
- **Ritual que ninguém executa** é o risco dominante, e é comportamental. Por isso
  a fatia 0 existe.

---

## 5. Ritual de acompanhamento (metodologia + calendário + suporte no sistema)

### Veredito: **CONSTRUIR SIMPLIFICADO** (esforço P no sistema; o resto é método)

Sem cadência definida, tudo que está acima vira cadastro morto — e o sistema já
tem 80% do suporte necessário (Relatório de Status por período, diário de bordo,
status automático por data-limite, avisos por e-mail semanais e diários); falta a
**pauta** e a **memória da reunião**.

> Este tema não recebeu proposta nem revisão adversarial. Trate como v1.

### O que já existe (e talvez ninguém saiba que existe)

Verificado no código, e é mais do que se supunha nas outras propostas:

- **`app/Services/Avisos.php` + `cli/notificar.php` estão vivos e implementados**:
  disparo **SEMANAL** (segunda-feira, panorama da semana de cada responsável) e
  **DIÁRIO** (só o que vence hoje ou já venceu), por responsável
  (`desdobramento.quem_usuario_id`), com deduplicação em `envio_email`
  (`UNIQUE (tipo, referencia, usuario_id)`) — rodar duas vezes no mesmo dia não
  reenvia. Há inclusive a rota `POST /api/avisos/despachar` (só ADMIN) para
  disparar na hora e conferir o SMTP.
- O agendamento está documentado (`docs/DEPLOY-RAILWAY.md` §6: cron do Railway
  `0 11 * * *` + `php cli/notificar.php`). **Só depende de `SMTP_HOST`/`SMTP_REMETENTE`
  configurados e do cron ligado.**
- **Relatório de Status** aceita `de`/`ate` (padrão: últimos 30 dias) e já traz
  metas/reais, projetos com progresso, capital, decisões de investimento do
  período e diário de bordo do período — em tela, impressão (PDF pelo navegador)
  e `.xls`.
- **Hub do Planejamento** com o checklist de completude do método.

Conclusão: a primeira entrega do ritual é **operacional, não de software** —
ligar o cron e conferir o SMTP.

### Metodologia proposta (três cadências)

| Cadência | Quem | Duração | Insumo no sistema | Saída |
|---|---|---|---|---|
| **Semanal — pessoal** | cada responsável, sozinho | 10 min | e-mail de segunda (`Avisos` SEMANAL) | atualizar status/progresso das ações 5W2H e escrever no diário |
| **Mensal — por negócio** | gestor + controladoria | 45 min | Relatório de Status do mês (negócio) | ações atrasadas repactuadas, contingências checadas, registro de reunião |
| **Trimestral — direção** | direção + controladoria + gestores | 2 h | Painel consolidado + relatório corporativo + Matriz de Impacto | decisões de capital, revisão de metas, ameaças acionadas |
| **Anual — replanejamento** | todos | oficina | Coleta & Triagem → diagnóstico do ano | novo diagnóstico anual, cascata revisada |

**Pauta fixa da reunião mensal** (é a ordem das seções do Relatório de Status, de
propósito — a pauta é o documento):

1. Métricas-âncora: meta × real.
2. *(quando existir)* Fatores macro que impactam este negócio — tema 1.
3. Projetos: atrasados primeiro, depois os que mudaram de status.
4. Capital: envelope × comprometido; decisões do período.
5. Diário de bordo do período (o que aconteceu).
6. *(quando existir)* Riscos e contingências: gatilho disparou? — tema 4.
7. Encerramento: ações repactuadas e quem faz o quê até a próxima.

**Regra de higiene:** a reunião **não** é para preencher o sistema. Quem chega com
o status desatualizado tem a ação marcada como "sem informação" no registro — e é
isso que o item 7 cobra.

### Suporte no sistema — a entrega mínima

**Fatia 0 (operacional, zero código):** configurar `SMTP_HOST`/`SMTP_REMETENTE`,
ligar o cron diário do Railway, e rodar `POST /api/avisos/despachar` uma vez para
conferir. Isso liga um módulo inteiro que já está pronto e não roda.

**Fatia 1 (P) — "Registro de reunião" (ata leve): ENTREGUE.** Uma tabela e um
botão. Implementado como descrito abaixo, com edição e exclusão; as rotas são
`GET/POST /api/reunioes`, `POST /api/reunioes/{id}` e
`POST /api/reunioes/{id}/excluir` (`RelatorioController`), e o bloco "6. Últimas
reuniões de acompanhamento" fecha o Relatório de Status.

```sql
CREATE TABLE IF NOT EXISTS reuniao (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id INT NOT NULL,
  data_reuniao    DATE NOT NULL,
  periodo_de      DATE NOT NULL,          -- o período do relatório usado na pauta
  periodo_ate     DATE NOT NULL,
  participantes   TEXT NULL,
  decisoes        TEXT NULL,              -- o que se decidiu
  proximos_passos TEXT NULL,
  autor_id        INT NOT NULL,
  criado_em       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reu_plan (planejamento_id, data_reuniao),
  CONSTRAINT fk_reu_plan  FOREIGN KEY (planejamento_id) REFERENCES planejamento(id) ON DELETE CASCADE,
  CONSTRAINT fk_reu_autor FOREIGN KEY (autor_id) REFERENCES usuario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- **Onde:** dentro da seção **Relatório de Status** (já está no bloco *Gestão* do
  menu). Botão "Registrar reunião" no topo, que abre `Modal.abrir` pré-preenchido
  com `data_reuniao = hoje` e o período que está na tela. Abaixo, "Últimas
  reuniões" com as 5 mais recentes.
- **Rotas — 2:** `GET /api/reunioes?planejamento_id=` e `POST /api/reunioes`
  (+ `POST /api/reunioes/{id}` se quiser edição).
- **Por que vale:** hoje a memória da reunião ou não existe, ou está espalhada no
  diário de bordo de cada projeto. Uma linha por reunião responde "quando foi a
  última, quem estava, o que se decidiu" — e o próprio registro vira a evidência
  de que o ritual está vivo.

**Fatia 2 (P, opcional) — "Pendências para a reunião":** um bloco no topo do
Relatório de Status, calculado na leitura, sem cron: ações atrasadas, projetos
sem registro no diário no período, contingências sem checagem no período,
indicadores sem real lançado no último ano fechado. É a linha que o relator lê em
voz alta no começo da reunião.

### O que fica de fora

- **Cron dentro do app.** Não existe agendador no `entrypoint.sh` e não vale criar
  um; o cron do Railway já está documentado e é a resposta certa.
- **Aviso automático de "checagem de contingência vencida" por e-mail.** É barato
  adiante (`Avisos` já tem a estrutura, o dedup e o template), mas só depois que
  houver contingências cadastradas de verdade.
- **Convite/agenda/integração com calendário.** Não é problema deste sistema.
- **Aprovação/assinatura de ata.** Ata leve é ata leve; se virar documento formal,
  vira outro projeto.

### Riscos

- **O ritual é 90% comportamental.** Nenhuma tabela faz uma reunião acontecer. Se
  a controladoria não marcar as reuniões, a `reuniao` fica vazia — e essa é a
  informação mais honesta que o sistema pode dar.
- **SMTP não configurado** silencia o único componente automático que existe:
  `cli/notificar.php` sai com erro e ninguém percebe. Vale checar o retorno do
  cron no primeiro mês.
- **E-mail só chega a quem tem `desdobramento.quem_usuario_id` preenchido** — ação
  com responsável digitado como texto livre não gera aviso. Isso precisa entrar na
  higiene do cadastro, ou o gestor vai concluir que "o sistema não avisa".

---

## Ordem de implementação recomendada

O critério é: **o que faz as reuniões de acompanhamento acontecerem primeiro**, e
depois o que se alimenta delas. Construir conteúdo (matriz, mapa, coleta) antes de
existir um fórum que consome esse conteúdo é como o sistema morre.

**0. Ligar o que já existe (horas, zero código).** SMTP + cron diário do Railway
para `cli/notificar.php`. Um módulo pronto que não roda é a melhor relação
valor/esforço do backlog inteiro.

**1. Ritual de acompanhamento — fatia 1 (P).** Registro de reunião no Relatório de
Status. É o menor código do backlog e é a pré-condição de valor de tudo o que vem
depois: sem reunião mensal, contingência não é checada, matriz não é lida e a
matriz de execução não é discutida.

**2. Micro-entrega da Matriz de Execução (P, ~15 linhas).** Reanimar
`projeto.cascata_id` no modal de projeto e em `ProjetoController::salvar`. O JOIN
e a exibição já existem; é a correção mais barata do repositório e pré-requisito
da coluna "Iniciativas".

**3. Plano de Contingência (M — ou fatia 0, se o ritual ainda não estiver rodando).**
Entra logo depois do ritual porque é o item que mais alimenta a pauta: gatilho +
checagem datada + a linha "Y sem checagem desde \<de\>" no relatório. Se houver
qualquer dúvida de que a reunião mensal vai percorrer os planos, **comece pela
fatia 0** (3 colunas em `fator`), que custa um quinto e testa a hipótese.

**4. Matriz de Execução — resto (P).** `indicador_cascata` + multiselect no modal
de indicador + aba na Cascata. Depende do passo 2 para a coluna de iniciativas
fazer sentido, e é a leitura que a direção pede no trimestral.

**5. Matriz de Impacto por Negócio (P).** Independente de tudo, mas depende de a
SWOT/GUT **corporativa** do ano estar preenchida — por isso vem depois de o ciclo
de reuniões já estar consumindo o diagnóstico. Abre a reunião do gestor com
"o que o corporativo diz que me impacta".

**6. Coleta & Triagem (M) — condicionada ao calendário.** É sazonal: só serve na
janela da próxima oficina de diagnóstico. Se a oficina for daqui a 30–60 dias,
**este item pula para o topo** (junto com o passo 0), porque perder a janela
significa esperar um ano. Se for daqui a 6 meses, fica onde está.

**Nunca (nesta forma):** o Mapa Estratégico com raias, caixas próprias
(`objetivo_estrategico`) e setas de causa-e-efeito. Reavaliar só se, depois de a
Matriz de Execução estar em uso, aparecer a necessidade concreta de um objetivo
que atravessa eixos ou que não nasce de nenhuma escolha da cascata.

---

## Leitura impacto × esforço

Cruzamento dos dez temas, para quem quiser conferir a fila por outro ângulo.
O esforço e o veredito vêm da tabela abaixo; **a posição no eixo de impacto é
leitura derivada dos argumentos de cada seção** — este documento não atribui
nota de impacto, e não convém passar a atribuir.

| | **Esforço pequeno (P)** | **Esforço médio/alto (M, G)** |
|---|---|---|
| **Impacto alto** | **Fazer agora** — 0 SMTP+cron · 5 registro de reunião · 3b vínculo com a Cascata · 3a Matriz de Execução · 1 Matriz de Impacto | **Planejar** — 4 Plano de Contingência · 2 Coleta & Triagem |
| **Impacto baixo** | **Encaixar** — 4b contingência dentro do projeto *(não construir agora)* | **Descartar** — 3c Mapa BSC · 2b rodadas e roteiro da coleta |

**O que essa leitura mostra — e o que ela não decide.** Cinco dos dez temas
caem no mesmo quadrante. Não é falha da leitura: é o retrato de um backlog já
podado, em que o trabalho caro e duvidoso foi cortado antes de entrar na lista.
O que sobrou é quase tudo barato e valioso.

Por isso a coluna "Ordem" da tabela-resumo **não** sai desse cruzamento — ele
empataria cinco itens. Ela sai da **dependência**: o que precisa existir antes
para o item seguinte valer alguma coisa. Quem quiser reordenar a fila deve
discutir a dependência, não o quadrante.

## Tabela-resumo

| # | Tema | Veredito | Esforço | Ordem |
|---|------|----------|---------|-------|
| 0 | Ligar SMTP + cron dos avisos (já implementado) | Executar | — | 0 |
| 5 | Ritual de acompanhamento (registro de reunião) | **Entregue** | P | 1 ✔ |
| 3b | Reanimar `projeto.cascata_id` | Construir | P (micro) | 2 |
| 4 | Plano de Contingência (ancorado na ameaça GUT) | Construir simplificado | M | 3 |
| 3a | Matriz de Execução (`indicador_cascata` + aba na Cascata) | Construir simplificado | P | 4 |
| 1 | Matriz de Impacto por Negócio | Construir simplificado | P | 5 |
| 2 | Coleta & Triagem (tratativa item a item) | **Entregue** | M | 6 ✔ (antecipada) |
| 2.1 | Tempestade: quiz por QR, separação e matriz de priorização | **A planejar** | M | a definir |
| 3c | Mapa Estratégico BSC: raias, `objetivo_estrategico`, setas | **Não construir** | G | — |
| 4b | Contingência dentro de cada projeto | **Não construir agora** (deriva de 4) | P | — |
| 2b | Rodadas, roteiro de perguntas e participantes da coleta | **Não construir** | M | — |

---

## Decisões em aberto

Perguntas que nenhuma análise de código responde — só o dono do processo.

1. **Acesso do gestor ao diagnóstico corporativo.** A Matriz de Impacto faz o
   GESTOR ler descrições de fatores do plano corporativo. Hoje a regra é dura
   (`docs/PLANEJAMENTO-SISTEMA.md` §5). Flexibiliza? Se sim, o payload do gestor
   traz **só** descrição do fator + sinal + texto da célula, e o §5 precisa ser
   reescrito na mesma PR.
2. **Perfil LEITURA pode gravar ideia na Coleta?** Seria a primeira escrita por
   LEITURA em todo o sistema. É o que permite brainstorm amplo sem inflar perfis —
   mas é mudança do modelo de segurança, não detalhe de controller.
3. **Quando é a próxima oficina de diagnóstico?** Define se a Coleta & Triagem
   pula para o topo da fila ou espera.
4. **As 4 perspectivas do BSC são inegociáveis para a diretoria**, ou os 6 eixos
   já cadastrados servem como raia? A primeira resposta custa uma coluna
   (`eixo.perspectiva`); a segunda custa zero.
5. **Cadência oficial do ritual:** mensal por negócio + trimestral com a direção,
   como proposto? Quem conduz? A reunião mensal é por negócio ou agrupa negócios?
6. **A contingência é do ciclo (recomendado) ou do ano?** Se for do ano, é preciso
   orçar desde já o "repetir no próximo ano" — e aceitar que os planos somem da
   tela na virada.
7. **Existe (ou existirá) real com granularidade mensal vindo do Qlik?** Se sim,
   destrava a contingência ancorada em indicador (o `OFF_TRACK` do BSC) e muda a
   pauta do ritual. Se não, meta × real continua sendo assunto anual.
8. **Quem responde ao quiz da tempestade (tema 2.1)?** Só usuário cadastrado,
   ou qualquer pessoa com o código da rodada? A segunda opção é a experiência
   que o cliente descreveu e seria a primeira rota de escrita sem autenticação
   do sistema — precisa de decisão explícita e de revisão de segurança própria.
9. **"Separar as palavras" é dividir a ideia em várias, ou nuvem de termos?**
   As duas leituras levam a telas diferentes (ver tema 2.1, decisão B).
10. **Quem é o dono da Matriz de Impacto:** a controladoria preenche a grade inteira,
   ou cada gestor preenche a coluna dele? A versão proposta assume a primeira
   (gestor só lê) — é o mais simples e o mais defensável, mas é decisão de processo.
9. **SMTP e cron já estão configurados em produção?** Se não estiverem, o passo 0
   é a primeira coisa a fazer, antes de qualquer linha de código deste backlog.

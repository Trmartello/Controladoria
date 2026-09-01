# Backlog de evolução — Sistema de Planejamento Estratégico Copérdia

Consolidação das propostas de evolução discutidas depois das fases 1–6
(`docs/PLANEJAMENTO-SISTEMA.md`), já com os cortes de escopo aplicados após a
revisão adversarial de cada uma. Salvo onde marcado **ENTREGUE**, nada aqui foi
implementado — este documento é backlog, não registro de entrega.

**Onde olhar primeiro:** a *tabela-resumo*, no fim. É ela que diz o que está de
pé e o que vem depois; as seções abaixo são o porquê de cada veredito. Dois
temas (4 e 6) chegaram depois da rodada de propostas e têm documento próprio —
aqui ficam só o veredito e o ponteiro. Os temas **7 e 8** chegaram depois disso,
direto do uso: o 7 é pedido do cliente (marcado urgente) e o 8 nasceu de uma
pergunta dele sobre exclusões. Nenhum dos dois passou por revisão adversarial —
leia-os como primeira versão.

**Como ler.** Cada tema traz: veredito, onde encaixa (sistema + menu), modelo de
dados concreto, telas e fluxo, entrega mínima, esforço, dependências/riscos e —
importante — **o que ficou de fora** depois da crítica. Quando o revisor cortou,
o corte foi adotado; o que a proposta original queria e não sobreviveu está
listado explicitamente, para ninguém reabrir a discussão sem contexto.

**Escala de esforço.** P = uma PR pequena, ~1 dia. M = 2–4 dias com validação
Playwright e revisão. G = uma semana ou mais.

**Aviso de completude.** Chegaram três propostas com crítica (impacto,
coleta/triagem, mapa BSC). O quarto tema — **ritual de
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

### Situação: **ENTREGUE** — completo

Revisão do tema 2 pedida pelo cliente **depois** da entrega da Coleta. Não
substitui o que está no ar: reaproveita o modelo de dados e a tratativa, e
acrescenta a sessão ao vivo, a separação das ideias e a matriz de priorização.

> **Entregue e verificado.** A tempestade por QR/PIN está no ar e passou pela
> revisão de segurança exigida (commit `22e6f31` — "Correções das revisões da
> tempestade: 9 de segurança e 11 de corretude"). No ar: `RodadaController`
> (abrir com PIN de 6 dígitos, listar, encerrar, votação), `PublicoController`
> e as rotas públicas **sem sessão** (`GET /entrar/{pin}`, `/api/publico/*`), a
> página isolada do participante (`views/participante.php` + `participante.js`)
> e a tela de condução ao vivo (`coleta.js`: nuvem por *polling*, bancada,
> matriz impacto × esforço, agrupamento por arraste, destinos). As travas da
> decisão A estão todas presentes (CSRF por lista explícita, teto de
> ideias/votos dentro do `INSERT`, nome vindo do registro, PIN errado contado
> em `coleta_tentativa`, `Content-Type` JSON obrigatório) e o `CLAUDE.md`
> documenta a regra final.
>
> O texto abaixo é o **plano original, mantido como registro**. Três notas para
> a leitura:
>
> - a coluna do QR virou `pin CHAR(6)` / `uk_rodada_pin`, **não**
>   `codigo VARCHAR(12)` como o delta SQL adiante especifica;
> - vieram **extras** fora do escopo: votação com teto, agrupamento manual
>   arrastando fichas (`agrupado_em_id`), caixa "tratar depois" (`adiado`),
>   reclassificação não-destrutiva (`reabrir`), estado `DIVIDIDO`, e o destino
>   **Plano de ação** (`ACAO`) na triagem;
> - **sem ressalvas em aberto** — as duas que existiam já foram fechadas: a
>   edição da própria ideia pelo participante e o acoplamento da matriz ao
>   encaminhamento (o quadrante "Descartar" agora decide esquecer e a fila
>   ordena por prioridade). Detalhes na tabela de fatiamento, adiante.

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

### Decisões — fechadas pelo cliente

**A. Quem responde: qualquer pessoa, sem cadastro.** Entra pelo QR ou por um
link, "assim como já fizemos no Quiz Copérdia". Adotado o modelo daquele
projeto: **PIN de 6 dígitos + QR + link**, nome digitado, sem senha e sem
conta.

Consequências que precisam ser tratadas, não contornadas:

- é a **primeira rota de escrita sem autenticação** do sistema. Ela só pode
  gravar `coleta_item` de uma rodada **aberta**, e nada mais;
- `coleta_item.autor_id` (hoje `NOT NULL`, com FK para `usuario`) precisa
  aceitar nulo, com `autor_nome` ao lado para quem entrou pelo PIN;
- o "motivo do descarte visível ao autor", que dava legitimidade à tratativa,
  **muda de natureza**: sem conta não há a quem notificar depois. No modelo ao
  vivo isso não se perde — a discussão acontece na sala, em voz alta, e o
  motivo passa a ser registro do que foi decidido, não recado. É uma troca
  aceitável **porque** o formato é presencial;
- limites obrigatórios: teto de ideias por participante (o Quiz usa
  `maxAnswers`), tamanho máximo do texto, limite por origem, e rodada que
  encerra explicitamente.

**B. "Separar as palavras" é a tela ao vivo, não uma ação em separado.** O
cliente descreveu: as respostas chegam e formam uma **tempestade de palavras**
na mesma tela; o administrador vai **selecionando uma por uma**, discutindo com
o grupo, **complementando o texto** e jogando na matriz de priorização.

Ou seja, não são duas telas (coletar e depois tratar): é **uma tela de
condução**, projetada, com a nuvem de um lado e a bancada de trabalho do outro.
A ação "dividir uma ideia em várias" continua útil quando alguém despeja um
parágrafo, mas é secundária — o principal é selecionar da nuvem.

**C. A matriz decide o encaminhamento.** Confirmado pelo cliente: a matriz de
priorização é o que direciona os tópicos para PESTEL, SWOT e Porter. O que fica
de fora dela é o que será **esquecido**.

A fronteira com a Matriz GUT continua valendo, e agora é decisão registrada:

> A matriz da Coleta prioriza **ideias cruas, na oficina**. A GUT prioriza
> **fatores já na SWOT**, com score e rastro. `impacto` e `esforco` **não** são
> copiados para o `fator` — ao virar fator, quem prioriza é a GUT. Sem essa
> linha o sistema passa a ter duas priorizações concorrentes.

**D. A rodada volta ao escopo.** `rodada_coleta` foi **cortada** na revisão do
tema 2 — o PIN a traz de volta, porque um código para escanear é, por
definição, o identificador de uma sessão com início e fim. Continua cortado o
roteiro de perguntas e o CRUD de perguntas: **uma rodada = um tema**, e a
oficina abre rodadas em sequência se quiser mais de uma pergunta.

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

### O que o Quiz Copérdia já resolve — e o que não serve

O repositório `Trmartello/Quiz_Coperdia` (Node, sem dependências) roda
exatamente este formato: PIN de 6 dígitos, QR no telão, participante entra pelo
celular só com o nome, e tem os tipos de pergunta **"nuvem de palavras"** e
**"brainstorm"**. Vale copiar o que está resolvido e **não** copiar o que não
cabe no deploy do Controladoria.

**Copiar — o gerador de QR.** `js/vendor/qrcode.js` (Kazuhiko Arase, licença
MIT) já está vendorado lá e funcionando. Vendorar o mesmo arquivo aqui, em
`public/assets/vendor/qrcode.js`.

> Isso **elimina o risco técnico** que este plano registrava. Uma tentativa de
> escrever o gerador à mão foi feita e verificada contra uma implementação de
> referência: dados e Reed-Solomon saíram corretos byte a byte, seleção de
> versão correta, mas a montagem da matriz não reproduzia a referência em
> nenhuma das 8 máscaras. Trabalho descartado — havia uma biblioteca MIT
> pronta e testada em produção na casa. O QR deixa de ser risco médio e passa
> a ser "copiar um arquivo".

**Copiar — o modelo de sala.** PIN numérico de 6 dígitos gerado na abertura,
tela de projeção com PIN grande + QR + link para copiar, e teto de respostas
por participante.

**Não copiar — Server-Sent Events.** O Quiz entrega tempo real com
`text/event-stream` e `EventSource`, o que funciona porque o servidor é Node.
O Controladoria roda no **servidor embutido do PHP** (`php -S` no
`entrypoint.sh`), que é single-threaded: **cada conexão SSE aberta prenderia o
único worker e travaria o sistema inteiro** para todo mundo. Não é ajuste de
configuração — `PHP_CLI_SERVER_WORKERS` exigiria um worker por participante
conectado.

> **Aqui a tela ao vivo tem de usar *polling*:** um `GET` leve a cada 2–3
> segundos devolvendo só o que chegou depois do último id conhecido. É mais
> simples de escrever e não tem custo perceptível numa oficina de 15 pessoas.
> É, também, mais um argumento para php-fpm + nginx em produção, como
> `docs/DEPLOY-RAILWAY.md` já recomenda.

**Avaliar depois — a votação do brainstorm.** No Quiz, o brainstorm tem uma
segunda fase em que os participantes votam nas ideias e o telão ranqueia. O
cliente não pediu isso (quer selecionar uma a uma, discutindo com o grupo), mas
é um mecanismo de convergência que a casa já conhece. Fica como opção, não como
escopo.

**Melhorar — agrupamento de repetidas.** O Quiz agrupa por `toLowerCase()`. Em
português isso deixa "logística" e "logistica" como termos diferentes.
Normalizar sem acento (o `norm()` que `modal.js` já usa nos comboboxes).

### Modelo de dados — o delta do acesso público

Além de `coleta_rodada` (acima), em `coleta_item`:

- `autor_id` passa a aceitar **nulo** — é um `ALTER TABLE ... MODIFY`, e não um
  `garantirColuna()`; a migração precisa checar `IS_NULLABLE` em
  `information_schema` antes de aplicar, para seguir idempotente;
- `autor_nome VARCHAR(120) NULL` — o nome digitado por quem entrou pelo PIN;
- `participante_token CHAR(32) NULL` — identifica quem enviou sem conta, para
  aplicar o teto de ideias e permitir que a pessoa corrija a própria ideia.

### Rotas públicas (sem sessão)

| Rota | O que faz | Guarda |
|---|---|---|
| `GET /entrar/{pin}` | página do participante, fora do shell do app | rodada aberta |
| `POST /api/publico/entrar` | nome → devolve `participante_token` | rodada aberta |
| `POST /api/publico/ideia` | grava a ideia | token válido + teto + tamanho |
| `GET /api/publico/rodada/{pin}` | tema e situação da rodada | — |

Sem sessão não há CSRF a validar: **o token do participante é a única guarda**,
e por isso precisa ser aleatório, ligado à rodada e inútil depois do
encerramento. A página do participante não pode carregar o shell do app nem
qualquer JS de seção — só o necessário para escrever.

### A tela de condução (o coração do tema)

Uma tela só, projetada, dividida em duas zonas:

- **Tempestade** (esquerda/topo): as ideias chegando como fichas, tamanho
  proporcional a quantas pessoas disseram o mesmo, atualizando por polling.
  Tocar numa ficha a leva para a bancada.
- **Bancada** (direita/baixo): a ideia selecionada, o texto editável
  (*complementar*), a matriz 2×2 impacto × esforço para posicionar, e os botões
  de destino — Cenário, PESTEL, Porter, SWOT, ou **Esquecer**.

O que já está no ar (`texto_tratado`, `encaminhar`, `descartar` com motivo)
vira o motor da bancada; a novidade é a nuvem, o posicionamento e o fato de
tudo acontecer numa tela só.

### Fatiamento sugerido

| Fatia | Conteúdo | Esforço | Entrega valor sozinha? | Situação |
|---|---|---|---|---|
| 1 | `coleta_rodada` + PIN + tela do participante + rotas públicas | M | Sim — já dá para coletar na oficina | ✅ entregue |
| 2 | Tela de condução: nuvem por polling + bancada | M | Sim — é o fluxo que o cliente descreveu | ✅ entregue |
| 3 | Matriz 2×2 no item + destino a partir do quadrante | P | Sim — fecha a decisão | ✅ entregue |
| 4 | QR (vendorar `qrcode.js`) e link para copiar | P | Conveniência sobre a fatia 1 | ✅ entregue |
| 5 | Dividir ideia em várias | P | Opcional | ✅ entregue |
| 6 | Votação dos participantes nas ideias | P–M | Opcional, se quiserem convergência | ✅ entregue |

As fatias 1 a 3 entregam o fluxo inteiro. A 4 é barata e faz a diferença na
sala. A revisão de segurança exigida (rota de escrita sem autenticação, o único
caso do sistema) **foi executada antes de subir** — commit `22e6f31`, com 9
achados de segurança e 11 de corretude corrigidos.

**Fatia 3 — matriz decide o encaminhamento — ENTREGUE.** A **decisão C** foi
implementada de forma fiel, sem o mapeamento arbitrário que a crítica temia
(impacto×esforço não indica o tipo de diagnóstico). O ponto onde a matriz
**realmente decide** é o quadrante **"Descartar"** (baixo impacto, alto
esforço): posicionar ali abre o descarte já com o motivo sugerido — a matriz
decide **esquecer**. Os outros três quadrantes mantêm a ideia (`SELECIONADO`) e
a **fila passa a ordenar por prioridade** (Fazer agora → Planejar → Encaixar →
não posicionadas), com um selo do quadrante na nuvem. De quebra, corrigiu-se uma
inconsistência real: antes, posicionar em **qualquer** quadrante — inclusive
"Descartar" — marcava a ideia como `SELECIONADO`, o oposto de esquecer. A
escolha do destino de diagnóstico (Cenário/PESTEL/Porter/SWOT) segue manual,
por ser genuinamente ortogonal ao eixo impacto×esforço.

**Edição da própria ideia — ENTREGUE.** O participante agora corrige o texto da
própria ideia enquanto a rodada está aberta e a ideia ainda está `NOVO`: botão
"editar" na lista "Suas ideias", editor inline, rota pública
`POST /api/publico/ideia/{id}` (guardada pelo token + escopo do `UPDATE`, no
mesmo padrão das outras rotas públicas). Editar reavalia o agrupamento por
texto sem dissolver grupos de outras pessoas.

**Ditado por voz — ENTREGUE.** A caixa de ideia (e o editor de correção) da
página do participante ganharam o microfone da Web Speech API, no mesmo padrão
do `modal.js` e reusando as classes `.campo-voz`/`.btn-ditar` do `app.css`. O
botão só aparece onde o navegador suporta; sem suporte, a caixa segue como
texto simples. Fecha a página do participante — não sobra ressalva ali.

---

## 3. Mapa Estratégico BSC e as 4 perspectivas

### Veredito: **NÃO CONSTRUIR o mapa** / a Matriz de Execução está **ENTREGUE** (esforço P)

> **Entregue.** `indicador_cascata` no `schema.sql`, o campo “Escolhas da cascata
> que este indicador mede” no modal de indicador (`metas.js` +
> `IndicadorController::gravarCascatas`, com a guarda de IDOR), a ampliação de
> `CascataController::listar` e a aba **Matriz de Execução** na Cascata. O que
> mudou em relação ao previsto está em *Como ficou*, no fim desta seção.

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
   — que são, na prática, quatro perspectivas. `impacto` continua **morto**:
   `ProjetoController::salvar` não o lê nem o grava, e `modalProjeto` não tem o
   campo. Uma classificação de execução já foi abandonada por atrito de
   preenchimento; a perspectiva seria a segunda.
   > **Atualizado.** O `cascata_id` **deixou de estar morto**: o item 3b foi
   > entregue e ele agora tem campo no modal, validação de escopo no controller
   > e gravação. Isso não enfraquece o argumento — reforça: o vínculo que a
   > diretoria queria (execução ↔ escolha) coube num select, sem entidade nova.
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

**Micro-entrega que fechava a outra metade do vão — ENTREGUE (item 3b):** o
select "Escolha da Cascata que este projeto executa" está em `modalProjeto`
(`projetos.js`) e `ProjetoController::salvar` persiste `cascata_id` validando
que a escolha é do mesmo planejamento (a guarda de IDOR listada em Riscos, mais
abaixo, foi aplicada). O JOIN e a exibição "↳ Escolha da cascata: …" já existiam.
Ou seja: **metade da fonte de dados da matriz já está preenchível hoje**; falta
o lado do indicador.

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
3. ~~Select `cascata_id` em `modalProjeto` + persistência em
   `ProjetoController::salvar`~~ — **feito** (item 3b).
4. Ampliação de `CascataController::listar` + aba "Matriz de Execução" em `cascata.js`.

Sobraram os passos 1, 2 e 4. O passo 2 deve usar `lista_marcavel` (com
`obrigatorio` opcional), não `multiselect`: o texto de uma escolha da cascata é
uma frase inteira, e o `multiselect` não funciona no celular, onde não há Ctrl
(ver CLAUDE.md). O controle já existe e agora também tem modo de escolha única.

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

### Como ficou

Os quatro passos da entrega mínima foram feitos, e nenhum corte foi reaberto: a
caixa continua sendo a `cascata_escolha`, as raias continuam sendo os eixos, e
não há `objetivo_estrategico`, `perspectiva`, controller novo, rota nova nem item
de menu. **A entrega é a matriz, não um mapa Kaplan/Norton** — e a tela diz isso
de si mesma.

Três decisões que a execução acrescentou ao previsto:

- **A linha é o INDICADOR, não a escolha.** O plano falava em quatro colunas com
  `rowspan` na primeira; na prática, com "Indicadores" e "Meta / Real" em colunas
  separadas, dois KPIs na mesma escolha desalinhavam o nome do número na primeira
  quebra de linha. A escolha e as iniciativas é que ganham `rowspan` sobre os
  indicadores dela. São cinco colunas — Eixo | Escolha (e a renúncia) |
  Indicadores | Meta / Real | Iniciativas —, e o KPI fica sempre na mesma linha
  do número dele.
- **Um horizonte por vez, com seletor.** Seis drivers × sete células × três
  horizontes passariam de cem linhas numa tabela só, e a pergunta do trimestral é
  sempre sobre uma fase.
- **A regra do par meta × real virou função** (`SecaoMetas.metaReal`), chamada
  pela tela de Metas e pela matriz. Era o risco mais concreto do tema: escrita
  duas vezes, as duas telas passariam a dizer números diferentes do mesmo
  indicador — e a matriz fica ao lado da tela que os cadastra.

Duas coisas que a tela declara em vez de esconder: a escolha sem KPI diz *"Sem
indicador que meça esta escolha"* (é a pergunta de controladoria que a matriz
existe para responder), e um aviso conta quantos indicadores ainda não medem
escolha nenhuma, com o caminho para amarrá-los.

**A raia "Síntese da célula" vem primeiro.** A síntese não tem eixo — ela é o
texto que a matriz publica, e as aberturas por eixo detalham o que ela resume;
lê-las antes dela seria ler o detalhe sem o todo.

**O que sobrou como dívida conhecida:** `CascataController::listar` já tinha um
N+1 (fatores e sugestões por escolha) e ele ficou como estava. O que este tema
acrescentou não repete o problema — são quatro consultas agregadas para o
conjunto todo —, mas o laço antigo passou a ser chamado mais vezes desde o
Dossiê, que percorre `/api/cascata` uma vez por negócio.

---

## 4. Cruzamentos da SWOT (TOWS)

### Veredito: **CONSTRUIR** (esforço M) — **fatias 1–3 e o relatório ENTREGUES**

O elo entre descrever o ambiente e decidir o que fazer: o par de um fator
interno com um externo e a estratégia que nasce dele, no bloco que o próprio par
define. Tema que não passou pelo rito das seções acima (não veio como proposta
com crítica) — nasceu do material do cliente e tem **documento próprio**, com
modelo de dados, decisões e fatiamento: **`docs/CRUZAMENTOS-SWOT.md`**.

Entregues a tabela, a API, a tela das quatro colunas, o cadastro, a edição, a
ponte com o plano de ação (fatia 3 — o destino é o plano, não a cascata: §10) e o
**⤓ Relatório** da etapa, em tabela de duas colunas como o material do cliente
(§7). Da fatia 4 falta a **síntese** (§6): os campos “o que este bloco diz ao
planejamento”, acima das colunas na tela e antes dos blocos no relatório. A sala
(fatia 5) segue adiada por decisão registrada.

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
| **Mensal — por negócio** | gestor + controladoria | 45 min | Relatório de Status do mês (negócio) | ações atrasadas repactuadas, registro de reunião |
| **Trimestral — direção** | direção + controladoria + gestores | 2 h | Painel consolidado + relatório corporativo + Matriz de Impacto | decisões de capital, revisão de metas |
| **Anual — replanejamento** | todos | oficina | Coleta & Triagem → diagnóstico do ano | novo diagnóstico anual, cascata revisada |

**Pauta fixa da reunião mensal** (é a ordem das seções do Relatório de Status, de
propósito — a pauta é o documento):

1. Métricas-âncora: meta × real.
2. *(quando existir)* Fatores macro que impactam este negócio — tema 1.
3. Projetos: atrasados primeiro, depois os que mudaram de status.
4. Capital: envelope × comprometido; decisões do período.
5. Diário de bordo do período (o que aconteceu).
6. Encerramento: ações repactuadas e quem faz o quê até a próxima.

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
sem registro no diário no período,
indicadores sem real lançado no último ano fechado. É a linha que o relator lê em
voz alta no começo da reunião.

### O que fica de fora

- **Cron dentro do app.** Não existe agendador no `entrypoint.sh` e não vale criar
  um; o cron do Railway já está documentado e é a resposta certa.
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

## 6. Backup do banco (operação)

### Veredito: **EXECUTAR** — código entregue, **ligado** (cliente, 2026-09-01)

> **Ligado.** O cliente confirmou em 2026-09-01 que o backup está configurado em
> produção, junto com o envio de e-mail. O que este tema deixa de herança não é
> mais "falta ligar", e sim **o que confere se continua de pé** — abaixo.

`cli/backup.sh` gera, verifica e restaura o dump do MySQL, com bateria própria
(`testes/backup.sh`). Não é desenvolvimento: falta a configuração no Railway, e
o detalhe está em **`docs/DEPLOY-RAILWAY.md` §7**.

O ponto que decide se o backup é real: **o disco do contêiner é efêmero**. Um
cron gravando em `/app/backups` produz arquivo que morre no deploy seguinte —
parece backup e não é. Precisa de um **Volume** montado com `BACKUP_DIR`
apontando para ele, mais uma cópia periódica **fora do provedor** (backup no
mesmo lugar do banco não protege contra perder a conta).

**O que continua valendo depois de ligado**, porque "configurado" e "funcionando"
não são a mesma coisa em nenhum dos dois — e as três falhas abaixo são silenciosas:

| Conferir | Como | Por que passa despercebido |
|---|---|---|
| O arquivo sobrevive | a linha `✓` no log traz o caminho: fora do Mount Path do Volume, é efêmero | o script imprime `✓` e o arquivo morre com o contêiner |
| A cópia fora do provedor | `B2_KEY_ID`/`B2_KEY` definidas | sem elas `remoto()` não envia nada e **não reclama**, de propósito |
| Dá para restaurar | `./cli/backup.sh verificar <arquivo>`, e o passo 9 do deploy | backup que ninguém restaurou é hipótese |

E no e-mail há uma armadilha própria do Railway: **as portas de SMTP são
bloqueadas lá**, e quem envia de verdade é a API sobre HTTPS (`EMAIL_API_CHAVE`).
Configurar o bloco `SMTP_*` não dá erro e não envia. `php cli/notificar.php
diagnostico` diz, na primeira linha, qual dos dois caminhos está em uso —
rodado **de dentro do contêiner que envia**, que é o único lugar onde a resposta
vale (o serviço de cron não enxerga as variáveis do web).

---

## 7. Dossiê: imprimir as abas em sequência, por negócio

### Veredito: **CONSTRUIR** (esforço P–M) — **ENTREGUE**

Hoje cada análise imprime a si mesma. Quem prepara a reunião do Conselho abria
seção por seção, mandava imprimir, e juntava as folhas à mão — trocando de
negócio no menu e repetindo a volta inteira. O pedido era uma saída só: **o plano
do negócio escolhido, todas as etapas em sequência, num documento**.

Isto **não é** um relatório novo. Cada seção já sabe se desenhar em papel — é a
`RelatorioAnalise` com `canvas`/`bloco`, e o `@media print` já abre as colunas e
repete os cabeçalhos. O que faltava é **quem manda todas se desenharem de uma
vez**: `public/assets/js/secoes/dossie.js`, seção **Dossiê do plano**, no menu ao
lado do Relatório de Status.

### O desenho — e o que a execução mudou

O caro não é imprimir: é que **só existe na tela a seção ativa**. As seções
pintam sob demanda (`App.recarregarSecaoAtiva`), e a `#secao-X` das outras está
vazia — mandar imprimir produziria uma folha.

Eram duas saídas: **(1)** pintar todas e imprimir, reaproveitando cada seção como
ela é, ao custo dos efeitos colaterais; **(2)** uma tela que busca os dados e
desenha o documento do zero, sem efeito colateral e com uma segunda cópia do
desenho de cada análise — a cópia que diverge na primeira revisão, e que a
`RelatorioAnalise` existe para evitar.

**Foi a 1, com uma correção que mudou o desenho**: em vez de tirar o `d-none` de
todas as seções e imprimir a tela, o dossiê **fotografa** o `innerHTML` de cada
uma e monta um documento próprio. Três coisas saíram de graça daí:

- A foto é **inerte por construção** — atribuir `innerHTML` não carrega ouvinte
  nenhum. A cópia não pode agir, e a tela viva fica intocada.
- Cabem **onze negócios** no mesmo dossiê. As seções são dezessete elementos
  FIXOS no shell; a saída original, que revelava os elementos, só conseguiria
  imprimir um negócio por vez.
- O documento ganha **capa, sumário e ordem própria**, que a tela revelada não
  teria.

O “modo só desenho” virou `App.modoDossie`, e é menor do que se previa: os
relógios de polling já param sozinhos quando a seção tem `d-none`, mas só na
batida seguinte — a bandeira evita que nasçam, e é lida em `QuizSala.armarRelogio`.

**O que o plano não previa e a execução encontrou:** os filtros moram na seção e
sobrevivem à repintura (`Diag.busca`, `SecaoProjetos.filtroStatus`,
`projetosFechados`, `Diag.filtroMovel`). Sem zerá-los, quem tivesse “atrasado” no
filtro de Projetos levaria ao Conselho um plano em que só existem projetos
atrasados — e **nada na folha diria que houve filtro**. O dossiê monta com a
vista limpa e devolve a de quem clicou no `finally`. É a prova mais importante da
bateria.

### O que ficou de fora (cortes adotados)

- **⤓ Word do dossiê.** O plano dizia “se sair barato”, e não sai: a foto é HTML
  com classes do Bootstrap, e sem a folha de estilo o Word renderiza uma pilha de
  `<div>`. Fazê-lo direito seria montar o documento a partir do modelo de dados
  de cada etapa — a saída 2, com a cópia que se quis evitar. O PDF pela impressão
  é o que o cliente pediu; o ⤓ Word por análise continua onde está.
- **Coleta e Tempestade não é etapa do dossiê.** `SecaoColeta.filtro` casa uma
  `situacao` exata e não há visão “todas”: qualquer página dela seria uma fatia
  arbitrária do material da oficina, apresentada como se fosse a etapa inteira.
  Entra quando tiver uma visão de leitura própria.
- **Painel e Hub também não.** São do ciclo inteiro, não de um negócio — repetidos
  por negócio, imprimiriam a mesma folha onze vezes.
- **O documento não aparece na tela.** Ele é `d-none d-print-block`, e quem
  confere usa a pré-visualização da caixa de impressão. Mostrá-lo na tela traria
  de volta os comandos mortos e os `id` duplicados que a foto justamente remove.

### Riscos — e o que se fez com cada um

- **Volume.** Doze negócios × dez etapas são 120 documentos. A tela conta a
  seleção enquanto se marca e pede confirmação acima de 40; a montagem mostra
  barra de progresso e aceita cancelamento no meio.
- **Etapa vazia.** Sai como a própria seção a desenha (“Nenhum fator.”), e etapa
  que **falha** ao carregar entra dizendo isso, em vermelho: sumir com ela
  deixaria um documento que parece completo e não é.
- **Trocar de negócio recarrega o mundo.** Continua verdade — é o custo da saída
  1, e é o que a barra de progresso torna suportável.
- **`/api/contexto` cria o planejamento que não existe.** Um dossiê de “todos”
  cria a linha vazia dos negócios que ninguém abriu — o mesmo que visitar a aba
  deles já fazia, só que de uma vez. Benigno, mas é escrita: está declarado no
  comentário do laço.

---

## 8. Excluir o que já está amarrado noutra tela

### Veredito: **CONSTRUIR SIMPLIFICADO** (esforço P) — **ENTREGUE**

> **Entregue.** `Fatores::acoesQuePrendem` (a regra da trava, agora uma só, usada
> pela recusa E pela tela), `acao_trava` na listagem de fatores, o × desabilitado
> com o motivo em Fatores/Cruzamentos/Coleta, as contagens nas listagens de
> cascata, projetos e investimentos, e `public/assets/js/vinculos.js`, que monta
> a frase. O que mudou em relação ao previsto está em *Como ficou*, no fim.

O sistema todo é feito de vínculos — o fator vira cruzamento, o cruzamento vira
ação, a escolha da cascata vira projeto, a ideia da coleta vira fator. Apagar
qualquer uma dessas pontas mexe na outra, e **hoje cada tela resolve isso de um
jeito**.

O levantamento, controller a controller:

| Onde | O que acontece hoje |
|---|---|
| `CruzamentoController::excluir` | **recusa** com mensagem, se já virou ação |
| `ColetaController::excluir` | **recusa** com mensagem, se já virou ação |
| `NegocioController`, `UsuarioController` | **recusam** com mensagem e contagem |
| `FatorController::excluir` | **recusa** se o fator (ou o promovido dele, ou um cruzamento que o cita) virou ação; fora disso, **apaga em cascata** o promovido e a avaliação GUT |
| `CascataController::excluir` | o projeto originado **perde o vínculo** — sem avisar |
| `ProjetoController::excluir` | os investimentos **perdem o vínculo** — sem avisar |
| `IndicadorController::excluir` | apaga direto, sem guarda nenhuma |

Nenhum desses comportamentos está errado isoladamente; o problema é o conjunto.
São três regras diferentes (recusar, cascatear, soltar o vínculo) e **quase
nenhuma delas aparecia antes do clique**: o botão × tinha a mesma cara nos sete
casos, o `confirm()` dizia quase sempre a mesma frase, e o usuário só descobria a
diferença depois — por um erro vermelho ou, pior, por não descobrir nada, porque
a cascata é silenciosa.

> **Correção do levantamento.** Duas telas já faziam o certo e serviram de
> modelo: a **SWOT** já montava a frase item a item (`f.score` e `f.cruzamentos`
> vindos da listagem — "Também será apagado: a avaliação dele na Matriz GUT e 2
> cruzamento(s) da SWOT") e a **Coleta** já dizia o tamanho da caixa e o destino.
> O que faltava não era inventar o padrão: era estendê-lo, e acrescentar a parte
> que nenhuma delas tinha — o botão marcado.

### A entrega mínima — três coisas, nesta ordem

1. **Contar o vínculo antes.** Cada `excluir` já sabe o que está amarrado (é a
   consulta que ele faz para decidir); falta devolver isso numa rota de
   *pré-visualização* — `GET .../vinculos` — ou embutir a contagem na listagem
   que a tela já busca.
2. **Dizer no `confirm()` o que vai acontecer**, com os números: “Excluir o
   fator? A promoção dele à SWOT e a avaliação GUT saem junto.” / “2
   investimento(s) ficam sem projeto.” A frase muda por caso porque a
   consequência muda — uma frase genérica é o que existe hoje.
3. **Marcar o botão** onde a exclusão é recusada. O selo “Virou ação ↗” já diz
   que existe vínculo; o × ao lado continua parecendo que funciona. Desabilitado,
   com o motivo no `title`, o usuário para antes — e a recusa do servidor volta a
   ser rede de segurança, não o canal por onde se descobre a regra.

O ponto 3 é a menor parte do código e a maior do valor. Os pontos 1 e 2 são o que
falta para as exclusões *silenciosas* (fator, cascata, projeto) pararem de ser
silenciosas.

### O que fica de fora

- **Uniformizar as três regras.** Não vale: recusar, cascatear e soltar o vínculo
  são respostas certas para relações diferentes. A ação órfã no plano é grave (por
  isso recusa); o investimento sem projeto continua sendo um investimento (por
  isso solta). O que precisa ser uniforme é o **aviso**, não a regra.
- **Lixeira / desfazer.** Outro projeto, e resolveria um problema que ninguém
  relatou.

### Riscos

- **A pré-visualização é mais uma consulta por cartão.** Se for por linha, a tela
  de projetos faz dezenas. A contagem tem de vir junto da listagem que já existe,
  não numa chamada por botão.
- **Contagem que mente é pior que contagem nenhuma.** Ela vale no instante da
  pintura; entre ela e o clique, alguém pode ter criado o vínculo. O servidor
  continua sendo quem decide — este tema melhora o aviso, não substitui a guarda.

### Como ficou

Os três pontos da entrega mínima foram feitos, e o corte principal continua de
pé: **uniformizou-se o aviso, não a regra**. Recusar, cascatear e soltar o
vínculo seguem sendo respostas diferentes para relações diferentes.

**O ponto 3 saiu maior do que "marcar o botão", e por um motivo que só apareceu
no código.** A trava do fator nasce de **três** caminhos — o fator virou ação, um
**promovido** dele virou, ou um **cruzamento** que o cita virou —, e os dois
últimos são os mais comuns (o PESTEL não vai direto ao plano: passa pela SWOT).
Uma tela que decidisse por conta própria erraria exatamente aí, e mentiria nos
dois sentidos: × morto onde dava para apagar, × vivo onde o servidor recusa. A
saída foi extrair a consulta da recusa para **`Fatores::acoesQuePrendem`** e
servi-la à listagem como `acao_trava`. `exigirSemAcao` passou a chamá-la — há uma
única definição de "está preso", e a tela e o servidor não podem discordar.

**Uma armadilha de CSS que quase anulou a entrega.** O Bootstrap põe
`pointer-events: none` em todo `.btn:disabled`, e sem ponteiro o navegador não
mostra `title` nenhum — o botão ficaria cinzento e **mudo**, que é meio caminho
para o defeito original. A regra `.btn[aria-disabled="true"] { pointer-events:
auto }` devolve o ponteiro sem destravar coisa alguma: quem bloqueia o clique é o
atributo `disabled`, que continua lá. A bateria mede isso (`pointerEvents ===
'auto'`), porque é o tipo de coisa que uma atualização do Bootstrap desfaz em
silêncio.

**A frase virou `public/assets/js/vinculos.js`.** `Vinculos.aviso()` separa duas
listas — o que **sai junto** e o que **continua existindo, sem o vínculo** —, e a
distinção não é enfeite: perder a discussão de uma célula não é o mesmo que um
investimento ficar sem projeto. `Vinculos.quantos()` devolve string vazia no
zero, para o chamador despejar tudo na lista sem contar antes. O primeiro defeito
que a bateria pegou foi justamente aqui: um registro sem vínculo nenhum ganhava a
frase **"Sai junto: ."** — pior que não dizer nada, porque parecia informação.

**Onde as contagens entram, sem consulta nova por cartão:** os projetos e os
indicadores da escolha já vinham no payload da Matriz de Execução (tema 3a); os
comentários por escolha, os investimentos e os comentários por projeto e os
comentários por investimento entraram como **consultas agregadas** nas listagens
que a tela já busca.

**Um vínculo que este tema tornou visível é recente e meu:** excluir uma escolha
da cascata agora também apaga as linhas de `indicador_cascata` (tema 3a). Ele
aparece no aviso como "1 indicador continua existindo, sem o vínculo" — a
escolha some, a medida fica.

### O que ficou de fora (além dos cortes já listados)

- **A trava por GRUPO na Coleta** é indexada pela chave de agrupamento
  (`agrupado_em_id || id`), a mesma de `montarGrupos`, porque a guarda do
  servidor olha a caixa inteira. É um mapa montado numa passada por carga da
  lista — perguntar por cartão faria uma varredura por botão.
- **Cenário e Indicador não ganharam trava**, só frase: nenhum dos dois tem
  recusa no servidor. O indicador ganhou a contagem dos vínculos com a cascata,
  que é o que ninguém espera perder.

---

## 9. Levar qualquer item ao plano de ação (e mover entre análises)

### Veredito: **CONSTRUIR** (esforço P por fatia) — **A, B e C ENTREGUES**

O pedido do cliente: *"poder levar qualquer item do Porter, PESTEL, análise de
cenário para o plano de ação, ou até mesmo mover o item para outra análise"*.
São **três coisas diferentes** num pedido só, e a ordem importa porque a segunda
depende da primeira ter fixado o vocabulário.

| Fatia | O quê | Estado |
|---|---|---|
| **A** | PESTEL e Porter vão **direto** ao plano de ação | **ENTREGUE** |
| **B** | Item da **Análise de Cenário** vai ao plano de ação | **ENTREGUE** |
| **C** | **Mover** um fator entre PESTEL ⇄ Porter ⇄ SWOT | **ENTREGUE** |
| **C-bis** | Mover entre TABELAS (Cenário ⇄ fator) | a fazer |

### Fatia A — a regra de método que caiu

Até 2026-08 o servidor recusava com *"só fatores da SWOT vão ao plano de ação"*,
e a razão era boa: PESTEL e Porter **descrevem o ambiente**, e obrigá-los a
passar pela promoção a um quadrante forçava a síntese que a SWOT existe para
fazer. Agir sobre um item do PESTEL sem dizer se ele é oportunidade ou ameaça é
agir sem ter lido o próprio diagnóstico.

**A regra foi revogada por decisão do cliente (2026-08-31.)** O argumento de
quem usa: há fator do PESTEL e do Porter que **já nasce com dono e prazo** — uma
mudança de lei com data marcada, um fornecedor que avisou que vai sair — e
mandar inventar um quadrante só para poder agir produzia **SWOT de fachada**,
quadrantes preenchidos por obrigação processual, que sujam a análise em vez de
enriquecê-la. Entre uma SWOT honesta e menor e uma SWOT completa e falsa, a
primeira é melhor.

**O que a revogação NÃO tocou**, e isso é o que impede a mudança de virar
regressão:

- A **promoção continua existindo** e continua sendo o caminho recomendado
  quando o fator precisa de síntese. Ela deixou de ser obrigatória, não de ser
  a boa prática.
- A **ação órfã continua proibida**. Um fator que virou ação segue travado para
  exclusão (`Fatores::acoesQuePrendem`), e desmarcar o encaminhamento depois de
  a ação existir continua recusado. Foi tentador tratar isso como parte da
  mesma regra — não é: uma é de método, a outra é de integridade.

### Como ficou

**Um lugar só decide o rótulo.** As três etapas são a **mesma tabela** e fecham
o vínculo pelo **mesmo campo** (`fator_id`); o que muda é o catálogo do nome da
categoria — a SWOT tem quadrantes (`Diag.QUADRANTES`), PESTEL e Porter têm
tuplas (`Diag.CATEGORIAS_ETAPA`). Ler os dois formatos ficou em
`SecaoProjetos.categoriaDoFator`, e a fila e o modal de conversão só perguntam.
Espalhar um `if (origem === 'SWOT')` por tela é exatamente como a Coleta acabou
mostrando rótulos com outra caixa dos que a seção mostrava.

**A fila passou a declarar a etapa.** `FatorController::aguardandoAcao` devolve
`origem = f.etapa` em vez do literal `'SWOT'`, e o selo escreve "PESTEL · Legal"
sem saber de nada.

**O defeito que quase passou em silêncio** foi o terceiro lugar, não os dois
óbvios. Tirar a recusa do `planoAcao` e generalizar a fila deixava o fator
aparecer, ser encaminhado e virar ação — mas o `ProjetoController` fechava o
vínculo com um `AND etapa = 'SWOT'` no WHERE. A ação nascia **sem fechar o
vínculo**: o fator ficava "aguardando" para sempre numa fila da qual já tinha
saído, e o "Virou ação ↗" apontava para lugar nenhum. Sem erro, sem vermelho.

**O selo é um só, em duas telas.** `Diag.seloPlanoAcao` (três estados: →
Plano de ação · Aguardando ação · Virou ação ↗) e `Diag.ligarPlanoAcao` (os três
ouvintes) passaram a ser chamados tanto por `carregarEtapa` (PESTEL/Porter)
quanto pela SWOT, que antes tinha a sua cópia.

A bateria prova a corrente inteira (`provasPlanoDiretoAnalise`), incluindo as
duas recusas que **não** mudaram — porque num tema cuja entrega é "tirar uma
guarda", o que precisa de prova é o que continuou de pé.

### Fatia B — o item de cenário

O cenário **não é fator**: `cenario_item` é outra tabela, e por isso ganhou as
suas próprias `acao_em`/`acao_por`/`desdobramento_id` (migrate,
`garantirColuna` + `garantirFk` com SET NULL), `planoAcao` + `aguardandoAcao`
no `CenarioController`, as rotas, o `cenario_item_id` como **quarto** campo de
vínculo no `salvarDesdobramento` e a guarda de exclusão. Mesmo desenho, **não o
mesmo código** — e é por isso que a fatia A veio antes: ela fixou o vocabulário
que a B copiou.

**Três colunas repetidas numa quarta tabela é a decisão do tema**, e é
deliberada. A alternativa — uma tabela de encaminhamentos polimórfica
(`origem_tipo`, `origem_id`, `acao_em`, …) — trocaria três colunas por uma
junção a mais em **toda leitura das quatro telas**, e por uma FK a menos: hoje
o `ON DELETE SET NULL` é o que devolve a origem à fila sozinha quando a ação é
apagada, sem uma linha de PHP. Par polimórfico não tem FK que o carregue junto
— é exatamente o que obriga a ideia da Coleta a ser limpa à mão em
`excluirDesdobramento`, e o defeito que essa limpeza corrige (a ideia sumindo
da fila para sempre) é o argumento mais forte contra estender o padrão.

**O que quase deu errado** foi a chave da fila, não o servidor. As quatro
origens numeram separado, e a fila as junta numa lista só: sem prefixo por
origem (`c…`/`f…`/`x…`/`n…`), um item de cenário e um fator de mesmo id
ocupariam a mesma linha e o "Transformar em ação" abriria a pendência errada —
sem erro, sem vermelho. A bateria prova a chave (`data-virar-acao="n<id>"`),
não só a rota.

**Uma limpeza que o tema tornou barata:** os quatro rótulos que o modal de
conversão muda por origem (título, nome do campo, pergunta do destino e barra
colorida) estavam em ternários aninhados, repetidos em quatro lugares do
formulário. Com a quarta origem eles teriam de ser encaixados em todos, na
mesma ordem — viraram um objeto `falas`, uma chave por origem. Os tipos do
cenário (rótulo e cor) viraram `SecaoCenario.TIPOS`, que já eliminou uma
duplicação existente: o modal de "aceitar sugestão da sala" escolhia o par
`'#8f3b3b'`/`'Tendência'` à mão.

### Fatia C — mover entre análises (fator ⇄ fator **ENTREGUE**)

A parte com mais armadilha do pedido. Mover um fator de PESTEL para Porter (ou
para a SWOT) troca a **etapa** e obriga a **remapear a categoria** — as listas
não se correspondem. E o fator pode já estar preso: avaliado na GUT, citado num
cruzamento, promovido, ou já virado ação.

**A categoria é a metade do movimento, não um detalhe.** `LEGAL` não existe no
Porter, `RIVALIDADE` não existe na SWOT. Herdar a antiga produziria um fator que
some das DUAS telas — a SWOT filtra por categoria dela, o PESTEL por etapa — e
vira órfão invisível segurando vozes da sala que ninguém mais consegue
desvincular. É literalmente o defeito que o `salvar()` já teve de corrigir por
outro caminho (aceitar `etapa` do corpo na edição), e por isso ele veio primeiro
na cabeça de quem escreveu isto e primeiro na bateria.

**As quatro amarras RECUSAM**, cada uma dizendo o que desfazer primeiro:

| Amarra | Por que recusa |
|---|---|
| já virou ação | mudaria a origem da ação no relatório |
| promoção (nos **dois** sentidos) | mover a origem deixaria o promovido apontando para outra análise; mover o promovido o tiraria da SWOT sem tirar a marca |
| nota na Matriz GUT | a GUT é da SWOT — sair de lá levaria a nota para uma tela onde ela não existe |
| citado num cruzamento | o par escolhe um fator INTERNO e um EXTERNO por quadrante, e mover pode inverter o lado |

Cada uma delas é uma decisão de **processo**, não de código (decisões 13 a 15
abaixo). Enquanto não houver resposta, recusar é a saída segura: um movimento
recusado é um aborrecimento, um movimento que apaga a nota da GUT ou invalida um
cruzamento em silêncio é dado perdido que ninguém nota a tempo. Quando as
respostas vierem, cada linha da tabela vira um comportamento — e a bateria já
tem o teste do estado atual para virar do avesso.

**A trava da ação NÃO ganhou consulta nova.** Ela é a mesma
`Fatores::acoesQuePrendem` da exclusão — "esta linha sustenta uma ação no
plano?" é uma pergunta só, e respondê-la de dois jeitos faria a tela liberar um
gesto e o servidor recusar o outro sem motivo aparente. As outras três moram em
`FatorController::travasDeMover`, que alimenta a recusa **e** o `mover_trava` da
listagem, do mesmo jeito e pela mesma razão do tema 8. `mover_trava` é um
**array**: as amarras se acumulam, e um fator promovido *e* citado num cruzamento
tem duas coisas a desfazer — mostrar só a primeira faria a segunda parecer um
erro novo depois de o usuário já ter trabalhado.

**Promover ≠ mover**, e as duas continuam existindo lado a lado: promover
**copia** (a origem fica no PESTEL, o par visível nas duas telas), mover
**transfere**. São gestos diferentes e ambos legítimos; o que não pode é fazer os
dois no mesmo fator — daí a promoção travar o `⇄`.

### Fatia C-bis — mover ENTRE TABELAS (a fazer)

O que ficou de fora: mover um item da **Análise de Cenário** para PESTEL/Porter/
SWOT, e o inverso. Não é o mesmo trabalho — `cenario_item` e `fator` são tabelas
distintas, então "mover" ali é criar-e-apagar, carregando à mão o que o id
sustenta: as vozes da Coleta (`destino_tipo`/`destino_id`, par polimórfico, sem
FK), a redação guardada para a sala, e o encaminhamento ao plano. Um id que
morre com vínculos pendurados é exatamente o beco sem saída que o
`excluirDesdobramento` teve de aprender a evitar. Vale fazer depois, com o
mesmo padrão de recusas, e **não** aproveitando o `mover` atual: o `UPDATE
fator SET etapa` de hoje é seguro justamente por não mexer em id nenhum.

---

## Ordem de implementação recomendada

O critério é: **o que faz as reuniões de acompanhamento acontecerem primeiro**, e
depois o que se alimenta delas. Construir conteúdo (matriz, mapa, coleta) antes de
existir um fórum que consome esse conteúdo é como o sistema morre.

> **Estado da fila.** Os passos 1, 2, 2-bis, 3, 3-bis e 5 abaixo foram entregues
> (registro de reunião, `projeto.cascata_id`, o **Dossiê do plano**, a **Matriz
> de Execução**, o **aviso na exclusão com vínculo** e a Coleta & Triagem).
>
> **Os passos 0 e 0-bis saíram da fila:** o cliente confirmou em 2026-09-01 que
> o backup e o envio de e-mail estão configurados em produção. Eles eram o que
> restava de *operação*, e eram o argumento de "adiar não é mais escolher entre
> trabalhos". Não são mais pendência — viraram **conferência periódica**, com o
> que olhar registrado no tema 6.
>
> O que resta como *código* é o passo 4 (Matriz de Impacto, **travada na decisão
> 1**), a síntese dos Cruzamentos (4c) e o mover entre tabelas (9d). Com a
> operação de pé, a fila volta a ser escolha entre trabalhos — e a única trava
> que sobrou é de **decisão**, não de esforço.

**0. Ligar o que já existe (horas, zero código).** SMTP + cron diário do Railway
para `cli/notificar.php`. Um módulo pronto que não roda é a melhor relação
valor/esforço do backlog inteiro.

**0-bis. Ligar o backup (horas, zero código).** Volume + cron para
`cli/backup.sh` (§6). Entrou depois desta lista ser escrita e vai junto com o
passo 0 — é a mesma visita ao Railway, e este tem prioridade sobre aquele: um
aviso que não sai custa um lembrete; um banco sem cópia custa o sistema.

**1. Ritual de acompanhamento — fatia 1 (P). ✔ ENTREGUE.** Registro de reunião no
Relatório de Status. É o menor código do backlog e é a pré-condição de valor de
tudo o que vem depois: sem reunião mensal, a matriz não é lida e a matriz de
execução não é discutida.

**2. Micro-entrega da Matriz de Execução (P, ~15 linhas). ✔ ENTREGUE.** Reanimar
`projeto.cascata_id` no modal de projeto e em `ProjetoController::salvar`. O JOIN
e a exibição já existiam; era a correção mais barata do repositório e é
pré-requisito da coluna "Iniciativas".

**2-bis. Dossiê do plano — as abas em sequência (M). ✔ ENTREGUE.** Tema 7. Não
nasceu desta lista e não era pré-requisito de nada: entrou por pedido urgente do
cliente, e por ser o custo que se pagava toda vez que alguém montava a pasta de
uma reunião à mão.

**3. Matriz de Execução — resto (P). ✔ ENTREGUE.** `indicador_cascata` + a lista
marcável no modal de indicador + a aba na Cascata. O passo 2 já estava feito,
então a coluna de iniciativas já tinha de onde sair; é a leitura que a direção
pede no trimestral.

**3-bis. Aviso na exclusão com vínculo (P). ✔ ENTREGUE.** Tema 8. Dizer no
`confirm()` o que sai junto, e desabilitar o × onde o servidor vai recusar.

**4. Matriz de Impacto por Negócio (P).** Independente de tudo, mas depende de a
SWOT/GUT **corporativa** do ano estar preenchida — por isso vem depois de o ciclo
de reuniões já estar consumindo o diagnóstico. Abre a reunião do gestor com
"o que o corporativo diz que me impacta". **Travada na decisão 1** (acesso do
gestor ao diagnóstico corporativo).

**5. Coleta & Triagem (M) — condicionada ao calendário. ✔ ENTREGUE** (antecipada
justamente por isso: era sazonal, e a janela da oficina não espera o backlog).

**Nunca (nesta forma):** o Mapa Estratégico com raias, caixas próprias
(`objetivo_estrategico`) e setas de causa-e-efeito. Reavaliar só se, depois de a
Matriz de Execução estar em uso, aparecer a necessidade concreta de um objetivo
que atravessa eixos ou que não nasce de nenhuma escolha da cascata.

---

## Leitura impacto × esforço

Cruzamento dos temas da tabela-resumo, para quem quiser conferir a fila por
outro ângulo.
O esforço e o veredito vêm da tabela abaixo; **a posição no eixo de impacto é
leitura derivada dos argumentos de cada seção** — este documento não atribui
nota de impacto, e não convém passar a atribuir.

| | **Esforço pequeno (P)** | **Esforço médio/alto (M, G)** |
|---|---|---|
| **Impacto alto** | **Fazer agora** — 1 Matriz de Impacto (travada na decisão 1) | **Planejar** — 4c Cruzamentos: a síntese · 9d mover entre tabelas |
| **Impacto baixo** | — | **Descartar** — 3c Mapa BSC · 2b rodadas e roteiro da coleta |

Saíram do quadro por estarem entregues: 5 (registro de reunião), 3a (Matriz de
Execução), 8 (aviso na exclusão), 3b (vínculo com a Cascata), 2 e 2.1 (Coleta e Tempestade), as fatias
1–3 dos Cruzamentos com o relatório (§7), o ⤓ Relatório da Cascata, o 7 (Dossiê
do plano) e o 9a–9c (ir ao plano de ação e mover entre análises). Saíram por
estarem **ligados**: 0 (SMTP+cron) e 6 (backup).

**O "fazer agora" ficou com um item só, e ele não espera esforço — espera
resposta.** Enquanto a operação estava desligada, ela era o argumento da fila:
havia trabalho de valor alto que ninguém precisava decidir, só executar. Com o 0
e o 6 ligados (2026-09-01), sobrou o 1 — e ele está parado na **decisão 1**, não
por falta de braço. É a primeira vez neste backlog que a fila está travada em
processo, e não em construção.

**O 7 esteve no “planejar” e mesmo assim foi o primeiro da fila** — a contradição
aparente que mostra o limite deste quadro: a leitura por esforço não sabe que um
item foi pedido como urgente. Quando o quadrante e a fila discordarem, vale a
fila, e o motivo fica escrito ao lado dela.

**O que essa leitura mostra — e o que ela não decide.** O quadro esvaziou por
dois motivos diferentes, e vale não confundi-los: a maior parte saiu por ter
sido **entregue**, e o resto do "fazer agora" saiu por ter sido **ligado**. O que
sobra é um backlog já podado, em que o trabalho caro e duvidoso foi cortado antes
de entrar na lista — e o que restou depende de gente, não de tempo.

Por isso a coluna "Ordem" da tabela-resumo **não** sai desse cruzamento — ele
empataria os itens que sobraram. Ela sai da **dependência**: o que precisa
existir antes para o item seguinte valer alguma coisa. Quem quiser reordenar a
fila deve discutir a dependência, não o quadrante.

## Tabela-resumo

| # | Tema | Veredito | Esforço | Ordem |
|---|------|----------|---------|-------|
| 1 | Matriz de Impacto por Negócio | Construir simplificado | P | 1 (trava: decisão 1) |
| 4c | Cruzamentos da SWOT — a síntese (fatia 4, §6) e a sala (5) | Construir | P–M | 2 (ver `docs/CRUZAMENTOS-SWOT.md`) |
| 9d | Mover entre TABELAS (Cenário ⇄ fator) | Construir | M | 3 |
| 9c | Mover um fator entre PESTEL ⇄ Porter ⇄ SWOT | **Entregue** | P | ✔ (amarras recusam; decisões 13–15 em aberto) |
| 9b | Item da Análise de Cenário ao plano de ação | **Entregue** | P | ✔ |
| 9a | PESTEL e Porter **direto** ao plano de ação | **Entregue** | P | ✔ (decisão do cliente) |
| 8 | Excluir o que já está amarrado noutra tela (aviso antes do clique) | **Entregue** | P | ✔ |
| 6 | Backup do banco no Railway (Volume + cron) | **Ligado** | — | ✔ (cliente, 2026-09-01) |
| 0 | SMTP + cron dos avisos | **Ligado** | — | ✔ (cliente, 2026-09-01) |
| 3a | Matriz de Execução (`indicador_cascata` + aba na Cascata) | **Entregue** | P | ✔ |
| 7 | **Dossiê do plano: as abas em sequência, por negócio** | **Entregue** | M | ✔ (urgente, antecipado) |
| 4b | Cruzamentos da SWOT — a ponte (fatia 3) e o ⤓ Relatório (§7) | **Entregue** | M | ✔ |
| 4a | Cruzamentos da SWOT — tabela, API, tela, cadastro (fatias 1–2) | **Entregue** | M | ✔ |
| 7a | ⤓ Relatório na Cascata de Escolhas (Word + papel do preenchido) | **Entregue** | P | ✔ |
| 3b | Reanimar `projeto.cascata_id` | **Entregue** | P (micro) | ✔ |
| 5 | Ritual de acompanhamento (registro de reunião) | **Entregue** | P | ✔ |
| 2 | Coleta & Triagem (tratativa item a item) | **Entregue** | M | ✔ (antecipada) |
| 2.1 | Tempestade: quiz por PIN/QR, condução ao vivo e matriz | **Entregue** | M | ✔ |
| 3c | Mapa Estratégico BSC: raias, `objetivo_estrategico`, setas | **Não construir** | G | — |
| 2b | Rodadas, roteiro de perguntas e participantes da coleta | **Não construir** | M | — |

**Por que 3a veio antes de 1 — e por que fechou rápido.** Não era impacto, era
dependência e trava: a Matriz de Impacto (item 1) esbarra na **decisão 1**
abaixo — se o GESTOR pode ler descrições do diagnóstico corporativo —, que é
mudança do modelo de acesso e não detalhe de controller. A Matriz de Execução não
dependia de decisão nenhuma e, com o 3b já entregue, metade da fonte de dados
(`projeto.cascata_id`) estava preenchível: sobrou uma tabela, um campo e uma aba.
**Entregue.**

**Por que o 7 furou a fila — e como terminou.** Foi a primeira vez que a ordem
não saiu da dependência: o dossiê não era pré-requisito de nada e nada dependia
dele. Entrou na frente por pedido urgente do cliente, e porque o custo de não
tê-lo era pago toda vez que alguém preparava uma reunião. **Entregue**, e os
demais voltaram ao degrau em que estavam. Quem for reordenar a fila de novo deve
tratar um caso desses pelo que ele é: prioridade de *uso*, não de arquitetura.

**Por que o 8 depois do 3a.** É melhoria de aviso, não correção de defeito: as
regras de exclusão que recusam já recusam, e as que cascateiam fazem o que
devem. Ninguém perde dado hoje por causa disso — perde-se entendimento. Se
aparecer um relato de exclusão que surpreendeu alguém, ele sobe.

**Nota de manutenção.** Esta tabela ficou desatualizada uma vez (o 3b constava
como "Construir" já estando entregue, e por pouco não guiou a escolha do
trabalho seguinte). Ao entregar um tema, marque-o aqui **no mesmo commit** — é
o índice que se consulta para decidir o que vem depois.

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
3. ~~Quando é a próxima oficina de diagnóstico?~~ **Deixou de travar a fila:** a
   Coleta & Triagem foi antecipada e entregue justamente por ser sazonal. A
   pergunta continua valendo para o calendário do ritual (item 5), não para a
   ordem do backlog.
4. **As 4 perspectivas do BSC são inegociáveis para a diretoria**, ou os 6 eixos
   já cadastrados servem como raia? A primeira resposta custa uma coluna
   (`eixo.perspectiva`); a segunda custa zero.
5. **Cadência oficial do ritual:** mensal por negócio + trimestral com a direção,
   como proposto? Quem conduz? A reunião mensal é por negócio ou agrupa negócios?
6. **Existe (ou existirá) real com granularidade mensal vindo do Qlik?** Se sim,
   meta × real deixa de ser assunto só anual e muda a pauta do ritual.
7. **Quem é o dono da Matriz de Impacto:** a controladoria preenche a grade inteira,
   ou cada gestor preenche a coluna dele? A versão proposta assume a primeira
   (gestor só lê) — é o mais simples e o mais defensável, mas é decisão de processo.
8. ~~SMTP, cron e backup já estão configurados em produção?~~ **Respondida pelo
   cliente (2026-09-01): os dois estão ligados.** Era a única "decisão" que não
   dependia de opinião, e a resposta tirou os passos 0 e 0-bis da fila. O que
   fica no lugar dela é **conferência periódica**, não pendência: as três
   verificações silenciosas do backup (o arquivo sobrevive ao contêiner? há
   cópia fora do provedor? alguém já restaurou?) e, no e-mail, qual caminho de
   envio está de fato em uso — no Railway as portas de SMTP são bloqueadas e
   quem envia é a API (`EMAIL_API_CHAVE`), então o bloco `SMTP_*` pode estar
   completo sem que nada saia. Tudo tabelado no tema 6; o comando que responde é
   `php cli/notificar.php diagnostico`, rodado de dentro do contêiner que envia.
9. ~~Quem responde ao quiz da tempestade?~~ **Respondido e entregue:** qualquer
   pessoa, por QR ou link, sem cadastro — modelo do Quiz Copérdia. A revisão de
   segurança exigida (tema 2.1, decisão A) **foi executada** antes de subir
   (commit `22e6f31`).
10. ~~"Separar as palavras" é dividir ou nuvem?~~ **Respondido:** é a tela de
   condução ao vivo, com nuvem de um lado e bancada do outro (decisão B).
11. ~~As três perguntas dos Cruzamentos da SWOT~~ (carga inicial dos 12, nome da
   seção no menu, conduzir em oficina) — **respondidas** e registradas no §9 de
   `docs/CRUZAMENTOS-SWOT.md`: sem carga, menu "Cruzamentos", sala adiada.
12. ~~PESTEL e Porter vão ao plano de ação pela SWOT ou direto?~~ **Respondida
   pelo cliente (2026-08-31): direto.** Registrada no tema 9, fatia A, com o que
   a revogação não tocou. A promoção continua sendo a recomendação de método —
   deixou de ser obrigação do sistema.
13. **Mover um fator entre etapas: o que acontece com a nota da GUT?** A GUT é da
   SWOT. Um fator que sai da SWOT leva a nota para uma tela onde ela não existe,
   ou a nota se perde? **Hoje o sistema recusa o movimento** e manda limpar a
   nota antes — não trava mais a entrega, mas responder isto transformaria uma
   recusa em comportamento.
14. **Mover um fator citado num cruzamento: o par sobrevive?** Um cruzamento
   escolhe um fator INTERNO e um EXTERNO; mover o fator pode inverter o lado e
   deixar o par sem sentido. Apagar o cruzamento, recusar o movimento, ou deixar
   o par inválido visível para alguém decidir? **Hoje recusa.**
15. **Mover a ORIGEM de um fator promovido move o promovido junto?** E mover um
   fator que **já virou ação** — a origem da ação muda no relatório, ou o
   movimento é recusado? **Hoje recusa os dois**, que era a proposta: mover
   fator limpo é fácil, mover fator amarrado é o tema inteiro. Cada resposta que
   vier vira uma linha da tabela de amarras na fatia 9c.

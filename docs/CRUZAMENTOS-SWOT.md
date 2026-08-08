# Cruzamentos da SWOT (TOWS) — plano

Plano para trazer ao sistema o material “Cruzando os quadrantes: as quatro
estratégias” e a síntese “O que a SWOT diz ao planejamento”.

> **Situação: fatias 1 e 2 ENTREGUES** (tabela, API, tela das quatro colunas,
> cadastro, edição e a cascata de exclusão a partir do fator). Faltam a ponte
> (§5), a síntese e o relatório (§6 e §7) e a sala (§8, fatia 5). O §8 traz o
> que mudou em relação ao planejado e o §9, as decisões já tomadas.

> A frase que fecha o material é a especificação inteira em uma linha:
> **“Uma boa SWOT não descreve a empresa — descreve o que ela precisa decidir.”**
> Se a tela nova não terminar num destino (uma célula da cascata, uma iniciativa,
> um risco, uma trava do envelope), ela não fez o trabalho.

---

## 1. O que o material pede

O material tem duas partes, e elas pedem coisas diferentes.

**A primeira é estrutura**: quatro blocos, cada um com uma lista de linhas de
duas colunas — o *cruzamento* (“Pecuária + proteína”, “BI × volatilidade de
insumos”) e a *estratégia* (o parágrafo que diz o que fazer). Isto é registro,
tem dono, muda com o ano e precisa de tela.

| Bloco | Verbo | O par |
|---|---|---|
| Forças × Oportunidades | **atacar** | força + oportunidade |
| Forças × Ameaças | **defender** | força + ameaça |
| Fraquezas × Oportunidades | **reforçar** | fraqueza + oportunidade |
| Fraquezas × Ameaças | **proteger** | fraqueza + ameaça |

**A segunda é leitura** (“a força principal é operacional; a fraqueza principal é
de rentabilidade”). Isso é síntese de quem conduz, não campo de formulário —
ver §6.

---

## 2. Por que isto encaixa aqui

O sistema já tem tudo de que a etapa precisa:

- Os fatores da SWOT já existem, por ano, com categoria (`fator`, etapa `SWOT`).
- A promoção PESTEL/Porter → SWOT já resolve *quais* fatores merecem o quadrante.
- A ponte para a execução já tem padrão: a Coleta encaminha por
  `destino_tipo`/`destino_id`, com selo de ida e volta e o “desmarcar” que
  desfaz. O cruzamento usa o **mesmo padrão**, não um novo.
- A tela é a quinta análise: `RelatorioAnalise.canvas`, `.cabecalho-coluna`,
  o ⓘ de orientação, o 🎤 da sala e o ⤓ Relatório vêm de graça.

O que **não** existe é o par: hoje um fator não sabe conversar com outro.

---

## 3. Modelo de dados

```sql
CREATE TABLE IF NOT EXISTS swot_cruzamento (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  planejamento_id  INT NOT NULL,
  ano              SMALLINT NOT NULL,
  fator_interno_id INT NOT NULL,   -- FORCA ou FRAQUEZA
  fator_externo_id INT NOT NULL,   -- OPORTUNIDADE ou AMEACA
  tipo             ENUM('ATACAR','DEFENDER','REFORCAR','PROTEGER') NOT NULL,
  rotulo           VARCHAR(120) NOT NULL,   -- "Pecuária + proteína"
  estrategia       TEXT NOT NULL,           -- o parágrafo do material
  destino_tipo     ENUM('CASCATA','PROJETO','ACAO','INVESTIMENTO') NULL,
  destino_id       INT NULL,
  criado_por       INT NOT NULL,
  criado_em        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_par (planejamento_id, ano, fator_interno_id, fator_externo_id),
  ...
);
```

Quatro decisões que o modelo carrega:

1. **O `tipo` é derivado do par, nunca escolhido.** Força + oportunidade só pode
   ser `ATACAR`. Deixar o usuário escolher abriria a porta para uma linha
   gravada no bloco errado — o mesmo defeito que a etapa/ano do fator já custou
   (ver CLAUDE.md, “na edição, etapa e ano saem da LINHA”). O servidor **calcula**
   o tipo a partir das categorias dos dois fatores e recusa par impossível
   (dois internos, dois externos, fator de outro ano, fator de outro
   planejamento).
2. **O par é único.** Sem a chave, o mesmo cruzamento entraria duas vezes com
   redações diferentes e o bloco viraria uma discussão em vez de uma decisão.
3. **`ano`, como toda análise de diagnóstico.** Os horizontes são do ciclo; a
   SWOT é anual, e o cruzamento é leitura da SWOT daquele ano.
4. **`destino_tipo`/`destino_id` polimórfico e anulável**, igual à Coleta: o
   cruzamento nasce sem destino e ganha um quando vira decisão.

**Exclusão de fator**: `FatorController::excluir` já derruba o promovido e a
linha da GUT; passa a derrubar também os cruzamentos que citam o fator. Se o
cruzamento já tiver destino, a exclusão é **recusada** — a mesma regra que
protege o fator que já virou ação.

---

## 4. A tela — quinta análise do diagnóstico

`Diagnóstico → Cruzamentos (SWOT)`, depois da SWOT e antes da Cascata. Quatro
colunas, uma por bloco, nas cores dos quadrantes do material (verde, azul, ouro,
vermelho). Cada cartão:

```
┌──────────────────────────────────────────────┐
│ Pecuária + proteína                          │
│ [Força: Portfólio diversificado…]            │
│ [Oportunidade: Recompor margem…]             │
│ Aproveitar a janela de proteína para expandir│
│ a Fábrica de Rações, usando a força do maior │
│ negócio como âncora…                         │
│ ────────────────────────────────────────     │
│ ↳ Cascata · H1 · Como Vencer      ✎  ×      │
└──────────────────────────────────────────────┘
```

Os dois selos são clicáveis e levam ao fator na SWOT — o caminho de volta que a
Coleta já tem.

**O cadastro** (`+ Novo cruzamento`) é o modal declarativo de sempre, com dois
`selecao_livre` alimentados pela SWOT do ano: “o que temos/nos falta” (internos)
e “o que o ambiente oferece/ameaça” (externos). Escolhido o par, um campo `info`
mostra na hora **que bloco vai nascer** (“Forças × Oportunidades — atacar”):
sem isso, o usuário só descobre onde a linha caiu depois de salvar.

O `rotulo` é obrigatório e curto — é ele que aparece no relatório e nos selos.

**Não construir:** uma grade 4×4 clicável (força × oportunidade em matriz). Com
seis fatores por quadrante são 36 células por bloco, e o material mostra que na
prática se escolhem três. A grade convidaria a preencher tudo.

---

## 5. A ponte com as cascatas

É a parte do material que dá sentido a tudo (“A ponte com as cascatas”), e o
mapa dela é literal:

| Bloco | Destino natural | Onde |
|---|---|---|
| Forças (F×O, F×A) | vantagem no driver **Como Vencer** | célula da cascata |
| Fraquezas × Oportunidades | decisão por negócio | célula da cascata |
| Oportunidades (F×O) | iniciativa estruturante | projeto/iniciativa |
| Ameaças (F×A, Fr×A) | risco declarado / trava do Envelope | investimento |

Na tela isso é **um botão por cartão**, com o mesmo gesto do “Plano de ação” da
Coleta: escolher o destino abre o formulário do destino já preenchido com o
`rotulo` e a `estrategia`, e o vínculo fecha nos dois sentidos.

O destino **sugerido** vem do tipo; o condutor pode escolher outro. Forçar o
mapa transformaria uma orientação boa numa camisa de força — e há cruzamento de
força que vira investimento.

---

## 6. A síntese (a segunda imagem)

Um campo de texto por bloco (“o que este bloco diz ao planejamento”), mais um
campo geral, guardados junto ao planejamento e ao ano. Aparecem **acima** das
colunas na tela e **antes** dos blocos no relatório.

Não vira campo estruturado: a segunda imagem é argumento em prosa, e picá-la em
campos (“força principal”, “fraqueza principal”) produziria formulário
preenchido por obrigação. É o mesmo julgamento que manteve a orientação das
análises dentro do ⓘ em vez de virar parágrafo fixo.

---

## 7. Relatório

O ⤓ Relatório da etapa sai de graça: `RelatorioAnalise` já entrega Word e PDF, e
a paginação já repete o cabeçalho do bloco em toda folha. O que precisa ser
escrito é o `montar()` da seção — quatro blocos, cada item com rótulo, o par de
fatores e a estratégia, e a síntese antes deles.

No papel o cruzamento deve sair **em tabela de duas colunas**, como no material
(cruzamento | estratégia): é o formato que o cliente já usa e reconhece.

---

## 8. Ordem de construção

Cada fatia é entregável sozinha:

1. ✅ **Tabela + API + tela de leitura** — modelo, `CruzamentoController`
   (listar/salvar/excluir com o tipo derivado no servidor), as quatro colunas.
2. ✅ **Cadastro e edição** — o modal com o par, o `info` que antecipa o bloco, a
   cascata de exclusão a partir do fator.
3. **A ponte** — botão de destino, vínculo nos dois sentidos, selos, “desmarcar”.
4. **Síntese e relatório** — os campos de texto e o `montar()` da etapa.
5. **A sala** — 🎤 por bloco, para a oficina propor cruzamentos pelo celular
   (reaproveita `QuizSala` inteiro; o alvo novo entra em `Quiz::PERGUNTA_CATEGORIA`).

A fatia 5 é opcional e depende de o cliente querer conduzir esta etapa em
oficina — as quatro primeiras não dependem dela.

### O que mudou na execução das fatias 1 e 2

- **`destino_tipo`/`destino_id` NÃO entraram na tabela.** Eles são da fatia 3, e
  coluna que nada escreve é regra que ninguém testa: a guarda “recusa excluir o
  fator cujo cruzamento já tem destino” não teria como ser provada. Entram por
  `garantirColuna` junto com a ponte, com a guarda e o teste no mesmo commit.
- **A exclusão em cascata é da FK**, não do controller: as duas FKs de fator são
  `ON DELETE CASCADE`. O que o código acrescenta é o AVISO — `FatorController::
  listar` devolve `cruzamentos` (a contagem dos dois lados) e a confirmação da
  SWOT diz o que vai junto, item por item.
- **Na edição o PAR sai da linha**, como a etapa e o ano do fator: ele é a
  identidade do cruzamento (é o que a chave única guarda). Para outro par, outro
  cruzamento — e o formulário de edição mostra o par como bloco de leitura.
- **Duas peças novas no `Modal`**, ambas nascidas aqui e reaproveitáveis:
  `aoMudar(dados, raiz)`, que roda a cada mudança de campo (o `visivelSe` não
  servia — ele olha UM campo, e o bloco depende de dois); e `lista_marcavel`
  com `unico: true`, que é o controle certo quando o item precisa ser LIDO
  antes de escolhido (um `select` com um parágrafo dentro obriga a abrir a
  lista para descobrir o que existe).
- **Sem grade 4×4**, como planejado. O “+” de cada bloco já chega com as listas
  filtradas nas categorias daquele bloco; o “+ Novo cruzamento” do cabeçalho
  abre as listas inteiras e anuncia o bloco enquanto o par é escolhido.

---

## 9. O que foi decidido

- **Carga inicial dos 12 cruzamentos: NÃO construir.** Digitar 12 linhas na tela
  é meia hora da controladoria; a carga seria código permanente casando fatores
  **pelo texto**, que é frágil. O padrão se pagou na cascata (42 células) — em
  12 linhas, não se paga. Se um dia entrar, segue a regra da cascata: valida
  tudo antes do primeiro INSERT e **adia** (não marca) quando um texto não
  resolve.
- **Nome da seção no menu: “Cruzamentos”.** “Estratégias” disputaria sentido com
  o que já existe (as escolhas da cascata, as iniciativas, o próprio nome do
  sistema). Os verbos do material — atacar, defender, reforçar, proteger — são o
  subtítulo de cada coluna, que é o lugar deles.
- **Fatia 5 (sala): adiada.** A oficina produz *fatores*; o cruzamento é síntese
  de quem conduz, entre encontros. O `QuizSala` inteiro continua disponível se a
  decisão mudar.

## 10. Fatia 3 — entregue: o cruzamento vira ação

**A decisão foi ir direto ao plano de ação, não à cascata.** Pedido do cliente,
e ela dissolve as duas perguntas que este capítulo listava como pendentes: não
há texto de célula para sobrescrever, e não há costura entre o ano do
cruzamento e o horizonte da célula. O cruzamento **já é** a estratégia que
nasce do par; a cascata decide outra coisa (em que horizonte cada driver
aposta). Fazê-lo virar célula primeiro obrigaria a traduzir uma decisão que já
está tomada.

O caminho é o **mesmo do fator da SWOT**, e isso é a maior parte do valor: as
três colunas (`acao_em`, `acao_por`, `desdobramento_id`), a fila única de
“Aguardando plano de ação” em Projetos, o modal de conversão e os três estados
do selo (`→ Plano de ação` · `Aguardando ação` · `Virou ação ↗`) já existiam.
A fatia acrescentou a terceira origem, não um segundo mecanismo.

O que precisou de cuidado próprio:

- **A guarda contra ação órfã ganhou um caminho novo.** As FKs do cruzamento
  para os dois fatores são `ON DELETE CASCADE`: apagar um fator leva junto o
  cruzamento que o cita — e, se esse cruzamento já virou ação, a ação ficaria no
  plano sem origem nenhuma. `Fatores::exigirSemAcao` passou a olhar também os
  cruzamentos dos fatores pedidos **e dos promovidos a partir deles**.
- **`desdobramento_id` é `ON DELETE SET NULL`**, como no fator: apagada a ação,
  o cruzamento volta sozinho para a fila em vez de apontar para o que não
  existe. É o que faz o desfazer ser do lado da ação, não deste.
- **Excluir e “tirar da fila” são recusados depois que a ação existe.** As duas
  recusas são do servidor; a tela só deixa de desenhar o botão.
- **No selo da fila vai o BLOCO** (atacar, defender…), não o rótulo do par: é o
  bloco que diz de que leitura da SWOT a estratégia nasceu, e o rótulo já vai no
  texto. E o **nome do projeto novo** é o rótulo, não a estratégia — o rótulo
  tem duas ou três palavras, a estratégia é um parágrafo.

Provas em `testes/funcional.sh` §9b: encaminhar, aparecer na fila, tirar da
fila, virar ação, as três recusas, e o retorno à fila quando a ação é apagada.

## 11. O que ficou fora

A ponte para a **cascata** continua sem existir, e agora por decisão: quem quer
rastrear qual leitura justificou uma escolha do horizonte ainda faz isso pelo
texto. Se um dia ela entrar, as duas perguntas deste capítulo voltam a valer —
o texto da célula segue a regra do quiz (`comUso()` / `Quiz::guardarRedacao`:
composto automaticamente é recomposto, reescrito à mão só muda com confirmação)
e a costura ano × horizonte precisa ser decidida antes, não no meio.

# Refatoração GTD da Coleta — ENTREGUE

Reestruturação do fluxo da tempestade de ideias segundo o método GTD
(**capturar → esclarecer → organizar**), pedida pelo cliente e **entregue nas
cinco fatias** (commits `2a0908e` → `7fce6cd`).

> **Princípio que guia tudo:** a matriz é ferramenta de **organização**, nunca de
> captura. Ninguém interrompe o pensamento criativo para decidir prioridade.
> A sensação-alvo é a de trabalhar com post-its numa parede.

---

## 1. O problema que existia

Havia **duas matrizes** mostrando a mesma informação — uma no painel de
prioridade (topo) e outra dentro da bancada — e a de baixo obrigava o condutor a
decidir impacto × esforço **no momento em que refinava o texto**.

Antes: `nova ideia → editar → escolher quadrante → salvar → atualiza as duas matrizes`
Agora: `capturar → esclarecer → arrastar para a matriz` (o quadrante **é** a classificação)

---

## 2. Como ficou

### Capturar — a bancada é um editor
Tocar numa ideia abre só a bancada:

```
BANCADA
Ana e mais 1 · ★ 3 voto(s)
┌─────────────────────────────┐
│ Foco no atendimento         │
└─────────────────────────────┘
[Salvar texto] [Dividir] [Desagrupar] [Tratar depois]
```

Saíram dali o bloco **PRIORIDADE** (a matriz duplicada), os botões de **DESTINO**
e o **Rejeitar** — descartar passou a ser pôr no quadrante *Descartar*.

### Esclarecer — sem mudanças
Editar, dividir, agrupar (automático por texto equivalente e manual por arraste),
salvar, tratar depois. O agrupamento continua como sempre foi.

### Organizar — a matriz única
O condutor **arrasta o cartão da fila para o quadrante**. O quadrante já é a
classificação:

| Quadrante | Impacto | Esforço |
|---|---|---|
| Fazer agora | Alto | Baixo |
| Planejar | Alto | Alto |
| Encaixar | Baixo | Baixo |
| Descartar | Baixo | Alto |

Reclassificar é arrastar de um quadrante para outro. Sem popup, sem tela
intermediária. Há também o caminho por toque (tocar a ideia, tocar o quadrante),
que é o que funciona quando a matriz já está à vista.

### O menu da pílula
Tocar numa pílula da matriz abre o menu flutuante:

```
ENCAMINHAR PARA
  Cenário
  FRAMEWORK
  SWOT · PESTEL · Porter
  RESULTADOS
  Plano de ação
  ──────────────────────
  Desmarcar <destino>        (só quando há etiqueta)
  Remover do quadrante
```

Escolher um destino abre o modal que já existia, com os campos daquele destino.

### A ideia encaminhada permanece na matriz
Com a etiqueta do destino (`Cenário` · `PESTEL` · `Porter` · `SWOT` ·
`Plano de ação`, este último com `· aguardando` enquanto não virou ação num
projeto). Três saídas, todas explícitas:

| Ação | O que acontece |
|---|---|
| **Mover de quadrante** | muda só a posição; destino e etiqueta continuam |
| **Desmarcar \<destino\>** | sai da análise (o fator/item de cenário é apagado), perde a etiqueta e **continua no quadrante** |
| **Remover do quadrante** | volta para a fila **com a etiqueta**; pergunta antes se deve sair também da análise |

Remover da SWOT apaga o fator — por isso nunca acontece em silêncio.

---

## 3. O que mudou, arquivo por arquivo

### `public/assets/js/secoes/coleta.js`
| Função | Mudança |
|---|---|
| `bancada()` | perdeu a matriz, os destinos e o "Rejeitar" — virou editor |
| `painelPrioridade()` | quadrantes com `data-solta-quadrante` (alvo permanente do arraste) e `data-quadrante` (toque, só com ideia em foco); dica que ensina o gesto |
| `fichaPrio()` | pílula com etiqueta, arrastável, abrindo o menu flutuante |
| `aplicarQuadrante()` | caminho único de clique e soltura, com trava de reentrância |
| `ligarArraste()` | precedência do quadrante sobre a ficha + auto-scroll |
| `montarGrupos()` | inclui `ACEITO` (a encaminhada fica na matriz) |
| `nuvem()` | fila = o que não tem quadrante |
| `liderDe` / `encaminhado` / `rotuloDestino` | helpers novos |
| `telaConducao()` | matriz no topo; `fila \| bancada` lado a lado |

### `app/Controllers/ColetaController.php`
- `listar()`: **`LEFT JOIN fator`** para trazer a etapa do destino — sem ela o
  payload só diz `FATOR` e a etiqueta não teria como escrever "SWOT".
- `priorizar()`: aceita `ACEITO` (move sem tocar em situação/destino) e `limpar`
  para tirar do quadrante.
- `reabrir()`: trata o plano de ação pendente, recusa o que já virou ação num
  projeto e aplica ao grupo inteiro.
- `grupo()`: passou a incluir `ACEITO`.

### `public/assets/css/app.css`
`.celula-prio.clicavel/.escolhido/.alvo-solta`, `.ficha-prio` (+ `.encaminhada`),
`.fp-tag`, `.fp-menu` e itens, e o bloco compacto do celular. Saiu o CSS órfão da
matriz antiga (`.matriz-quad`, `.quadrante-prio`, `.grade-matriz`, `.mq-*`).

---

## 4. Decisões do cliente (todas aplicadas)

1. **O "Rejeitar" saiu da bancada** — descartar é pôr no quadrante *Descartar*.
2. **Auto-scroll durante o arraste** no celular: perto das bordas a tela rola
   sozinha e o alvo é recalculado a cada quadro.
3. **Fila e bancada lado a lado** no computador; empilhadas no celular, com a
   fila antes da bancada (arraste mais curto até a matriz).
4. **A pílula encaminhada pode mudar de quadrante.**

Ajustes pedidos depois do primeiro protótipo, também aplicados: o ✕ solto virou
item **dentro** do menu; as pílulas e quadrantes ficaram **compactos no celular**;
e a ideia removida do quadrante volta à fila **com a etiqueta**.

---

## 5. Defeitos encontrados na validação — e corrigidos

| Defeito | Por que acontecia |
|---|---|
| **Arraste curto desclassificava** | pegar uma pílula e largá-la no próprio quadrante chegava como *clique*, e clique no quadrante já escolhido desmarca. Todo gesto iniciado numa ficha passou a engolir o clique seguinte |
| **Grupo ficava para trás** | `grupo()` filtrava `NOVO/SELECIONADO`: numa caixa-mãe encaminhada, mover ou desmarcar mexia só no líder e deixava as demais apontando para um fator apagado |
| **`reabrir` recusava plano de ação** | exigia `destino_id`, que o plano pendente não tem |
| **Pílula selecionada desmarcava sozinha** | o toggle da fila travava a reclassificação: dentro da matriz, tocar sempre seleciona |
| **Texto quebrando letra a letra** | os botões na mesma linha espremiam a pílula — resolvido movendo tudo para o menu |
| **Voz nova sumia com a caixa** | o balde do quadrante usava o representante, não o líder |

---

## 6. Como foi validado

Playwright dirigindo o app real em **1500×800** e **390×844**, com rodada aberta,
polling ligado e asserções no banco:

- classificar arrastando da fila (os quatro quadrantes gravam o par certo);
- reclassificar entre quadrantes; soltar no mesmo quadrante **não** desfaz;
- agrupar na fila continua funcionando (ficha sobre ficha);
- caixa-mãe vai inteira e volta inteira;
- **celular**: com a matriz fora da vista, segurar o cartão junto ao topo rola a
  página sozinha, o quadrante realça sob o dedo e a soltura classifica;
- menu com a hierarquia exata, `aria-expanded`, Esc fecha, sobrevive a dois
  ciclos de polling, e SWOT abre o modal com "Quadrante da SWOT";
- etiqueta correta por destino; mover preserva destino e etiqueta;
- desmarcar apaga o fator (1 → 0) e mantém a pílula no quadrante;
- remover devolve à fila com a etiqueta, preservando ou apagando o fator conforme
  a resposta;
- sem overflow horizontal e sem erros de console em nenhum cenário.

---

## 7. O que ficou de fora

- **Abrir o menu ao passar o mouse** (hover). O cliente citou como alternativa ao
  toque; ficou só o toque, que funciona nas duas telas — menu abrindo a cada
  passagem do mouse numa grade cheia de pílulas seria ruído.
- **Pesquisa e filtros na fila**, citados na arquitetura do pedido. A fila da
  oficina é curta e a ordenação (mais repetidas, mais votadas) já resolve; entra
  quando alguém sentir falta.
- **Desfazer o plano de ação já convertido** em ação de projeto: é recusado de
  propósito — desfazer ali deixaria a ação órfã, sem rastro de onde veio.

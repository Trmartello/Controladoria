# Refatoração GTD da Coleta — plano

Reestruturação do fluxo da tempestade de ideias segundo o método GTD
(**capturar → esclarecer → organizar**), pedida pelo cliente. Este documento é
**plano**, não registro de entrega: nada aqui foi implementado.

> **Princípio que guia tudo:** a matriz é ferramenta de **organização**, nunca de
> captura. Ninguém interrompe o pensamento criativo para decidir prioridade.
> A sensação-alvo é a de trabalhar com post-its numa parede.

---

## 1. O problema, em uma frase

Hoje existem **duas matrizes** mostrando a mesma informação — uma no painel de
prioridade (topo) e outra dentro da bancada — e a de baixo obriga o condutor a
decidir impacto × esforço **no momento em que refina o texto**, no meio do fluxo
criativo.

Fluxo atual: `nova ideia → editar → escolher quadrante → salvar → atualiza as
duas matrizes`.

Fluxo proposto: `capturar → esclarecer → arrastar para a matriz` (o quadrante
**é** a classificação).

---

## 2. O que já existe (e não precisa ser construído)

Boa notícia: a maior parte do "novo" já está no ar com outro nome.

| Conceito GTD | O que já existe hoje |
|---|---|
| **Inbox** (capturar) | "+ Nova ideia" + as ideias que chegam pelo PIN/QR |
| **Queue** (esclarecer) | **A nuvem da tempestade já É a fila**: `nuvem()` devolve exatamente os grupos *sem* quadrante |
| Agrupamento automático | `PublicoController::liderEquivalente` (texto equivalente) + arraste manual → caixa-mãe |
| Dividir / tratar depois | `dividir`, `adiar` — prontos |
| **Priority Matrix** | `painelPrioridade()` já monta a grade 2×2 e recebe as classificadas |
| Classificação | `priorizar()` já grava impacto+esforço no grupo inteiro e já aceita `limpar` |

**O trabalho real é**: (a) tirar a matriz da bancada, (b) fazer a matriz do topo
aceitar arraste, (c) o menu de encaminhamento, (d) o item destinado permanecer
na matriz com tag.

---

## 3. Etapa 1 — Capturar

Ao criar/tocar uma ideia, abre **só a bancada**, como editor puro:

```
BANCADA
Taylor Martello
┌─────────────────────────────┐
│ Meta                        │
└─────────────────────────────┘
[Salvar texto] [Dividir] [Desagrupar] [Tratar depois]
```

**Sai da bancada:** o bloco "PRIORIDADE" (matriz 2×2 + a legenda do Descartar) e
o bloco "DESTINO" (os cinco botões + "Rejeitar"). Nada mais muda ali.

O "Desagrupar" fica: é operação de *esclarecer*, não de organizar.

## 4. Etapa 2 — Esclarecer

Sem mudança de comportamento — editar, dividir, agrupar (automático e por
arraste), salvar, tratar depois. O agrupamento continua exatamente como está:

```
Meta vendas + Meta faturamento + Meta orçamento  →  caixa "Meta (3)"
```

## 5. Etapa 3 — Organizar

A ideia salva fica na **fila** (a nuvem de hoje). O condutor **arrasta o cartão
da fila para o quadrante** e pronto — o quadrante já é a classificação:

| Quadrante | Impacto | Esforço |
|---|---|---|
| Fazer agora | Alto | Baixo |
| Planejar | Alto | Alto |
| Encaixar | Baixo | Baixo |
| Descartar | Baixo | Alto |

**Nenhum popup, nenhuma tela intermediária.** Reclassificar = arrastar de um
quadrante para outro.

---

## 6. Encaminhar — menu hierárquico na pílula

Na matriz, a pílula ganha o menu pedido:

```
📂 Encaminhar para →
   Cenário
   Framework →
      SWOT
      PESTEL
      PORTER
   Resultados →
      Plano de Ação
```

Escolhido o destino, abre o modal que **já existe** (`modalEncaminhar`), com os
campos daquele destino (SWOT pede o quadrante, PESTEL/Porter a categoria, etc.).

**Como abre:** o menu é acionado por um **botão explícito na própria pílula**
(um `⋯`), e não só por `hover` — no celular não existe passar o mouse, e o toque
na pílula já é "levar à bancada". No desktop o `⋯` pode aparecer no hover; no
celular fica sempre visível.

## 7. O item destinado permanece na matriz, com tag

Hoje o encaminhamento marca o grupo como `ACEITO` e ele **some** do painel —
porque `montarGrupos()` só inclui `NOVO`/`SELECIONADO`. Passa a permanecer:

```
┌ Fazer agora ─────────────────┐
│ ( Baixa margem  ×2  [SWOT] ) │
│ ( Custo do frete )           │
└──────────────────────────────┘
```

**A tag exige mudança no backend** (verificado): `ColetaController::listar`
devolve `destino_tipo` (`CENARIO`/`FATOR`/`ACAO`) e `destino_id`, mas **não a
etapa do fator** — sem ela é impossível escrever "SWOT" em vez de um genérico
"Fator". Solução: um `LEFT JOIN fator` no `listar` trazendo a etapa.

Rótulos: `Cenário` · `PESTEL` · `Porter` · `SWOT` · `Plano de ação` (e
`Plano de ação (aguardando)` enquanto `destino_id` for nulo).

---

## 8. Mudanças por arquivo

### `public/assets/js/secoes/coleta.js`
| Função | O que muda |
|---|---|
| `bancada()` | Remove o bloco PRIORIDADE (matriz + legenda) e o bloco DESTINO (botões + Rejeitar). Vira editor puro. |
| `painelPrioridade()` | Quadrantes viram **alvos de soltura** (`data-solta-quadrante`). Pílula ganha o botão `⋯` e a tag do destino. |
| `ligarArraste()` | Precedência no soltar: **quadrante** (classificar) → **ficha da fila** (agrupar). Dentro do painel nunca agrupa. |
| `montarGrupos()` | Passa a incluir `ACEITO` (para o destinado continuar na matriz). |
| `nuvem()` | Continua excluindo quem tem quadrante — a fila só mostra o não classificado. |
| **novo** menu | Menu hierárquico ancorado na pílula, estado em `SecaoColeta` (padrão `caixaAberta`), fecha com Esc/clique fora. |
| `telaConducao()` | Nova ordem: matriz (topo) → bancada → fila. |

### `app/Controllers/ColetaController.php`
- `listar()`: `LEFT JOIN fator` para devolver a **etapa** do destino (a tag).
- `priorizar()`: hoje recusa `ACEITO` ("Esta ideia já foi tratada"). Para
  reposicionar uma pílula **já destinada**, precisa aceitar `ACEITO` alterando
  **apenas** impacto/esforço — sem tocar em destino.

### `public/assets/css/app.css`
- `.celula-prio.alvo-solta` (realce do quadrante sob o dedo), `.fp-menu`
  (o `⋯` e o painel do menu), `.fp-tag` (a etiqueta do destino).

---

## 9. Riscos — e como evitar

| Risco | Por quê | Prevenção |
|---|---|---|
| **Arrastar da fila até a matriz no celular** | Em 390px a matriz fica no topo e a fila abaixo da bancada: o gesto atravessa uma rolagem, e hoje não há auto-scroll durante o arraste | **É o maior risco do plano.** Ou (a) auto-scroll durante o arraste, ou (b) manter um caminho por toque (tocar a ficha → tocar o quadrante), ou (c) no celular pôr a fila logo abaixo da matriz. Ver decisão nº 2 |
| Soltar sobre uma pílula: agrupa ou classifica? | Hoje ficha-sobre-ficha agrupa | Dentro do painel **sempre classifica**; agrupar só dentro da fila |
| Guardas recusam `ACEITO` | `priorizar`/`complementar`/`descartar` respondem "já foi tratada" | Afrouxar **só** `priorizar` (impacto/esforço), mantendo as demais |
| Voz nova igual a uma ideia já destinada | `liderEquivalente` varre só `NOVO`/`SELECIONADO` | Ela nasce como ficha nova na fila — comportamento correto (o grupo antigo já seguiu adiante), mas precisa ser decisão consciente |
| Soltar em "Descartar" | Hoje abre modal pedindo o motivo | Manter o motivo (é o que dá legitimidade), mas abrir o modal **depois** do redesenho |
| Tag genérica ("Fator") | O payload não traz a etapa | O `LEFT JOIN` do item 8 — sem ele a tag não cumpre o pedido |
| Menu aberto sumir no polling | O relógio de 3 s reescreve o HTML inteiro | Estado no objeto da seção, como `caixaAberta`/`depoisAberto` |

---

## 10. Decisões — fechadas pelo cliente

1. **O "Rejeitar" sai da bancada.** Descartar passa a ser arrastar o cartão para
   o quadrante *Descartar*, que já pede o motivo. A bancada fica só com
   Salvar / Dividir / Desagrupar / Tratar depois.
2. **No celular: auto-scroll durante o arraste.** Ao arrastar perto da borda
   superior, a tela rola sozinha até a matriz — o gesto continua sendo um só em
   qualquer tela. É a peça mais delicada do plano (ver riscos) e deve ser a
   primeira coisa validada no celular real da fatia 2.
3. **No computador: fila e bancada lado a lado**, como hoje — matriz em largura
   cheia no topo, e abaixo a linha `fila | bancada`. No celular, empilha.
4. **A pílula já encaminhada pode mudar de quadrante**: arrastar altera só
   impacto/esforço; o destino e a tag continuam. Exige afrouxar `priorizar()`
   para aceitar `ACEITO` **sem** tocar em `destino_tipo`/`destino_id`.

---

## 11. Fatiamento e esforço — **G** no total

| Fatia | Conteúdo | Esforço | Entrega valor sozinha? |
|---|---|---|---|
| 1 | Bancada vira editor puro (sai a matriz e o destino de lá) | P | Sim — some a duplicidade na hora |
| 2 | Matriz aceita arraste da fila + reclassificação entre quadrantes | M | Sim — é o coração do GTD |
| 3 | Layout GTD (matriz → bancada → fila) | P | Sim |
| 4 | Item destinado permanece com tag (inclui o `LEFT JOIN`) | M | Sim |
| 5 | Menu hierárquico "Encaminhar para" na pílula | M | Sim — fecha o fluxo |

Recomendo executar **nesta ordem**: a fatia 1 já elimina a duplicidade que mais
incomoda, e a 2 entrega a sensação de post-it. As fatias 4 e 5 dependem uma da
outra para o painel virar a "fonte única da verdade".

---

## 12. Como validar

Playwright em **1500×800** e **390×844**, com rodada aberta e polling ligado:

1. Bancada sem matriz e sem botões de destino.
2. Arrastar da fila para cada um dos 4 quadrantes grava o par impacto/esforço
   correto (conferir no banco).
3. Arrastar entre quadrantes reclassifica; a pílula não duplica.
4. Soltar sobre uma pílula **classifica** (não agrupa); soltar ficha sobre ficha
   **na fila** ainda agrupa.
5. Caixa-mãe arrastada leva o grupo inteiro.
6. Encaminhar pelo menu: a pílula **continua** no quadrante, com a tag certa
   (SWOT/PESTEL/Porter/Cenário/Plano de ação).
7. Pílula destinada muda de quadrante sem perder o destino.
8. Menu abre no toque, fecha com Esc/clique fora e **sobrevive a dois ciclos de
   polling**.
9. Celular: o gesto de classificar funciona de ponta a ponta (conforme a decisão
   nº 2), sem overflow horizontal.

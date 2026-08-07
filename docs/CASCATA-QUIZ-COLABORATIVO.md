# Quiz colaborativo do planejamento

Plano de implementação, em duas partes.

**Parte I (seções 1–10)** — o quiz da Cascata de Escolhas: doze decisões
fechadas com o cliente e **entregue** nas Fases 1 e 2 (commits `e4549df` e
`3767aba`).

**Parte II (seções 11–16)** — a revisão de escopo pedida depois: a sala deixa de
ser da tela e passa a ser **do projeto**, um PIN só para todas as análises.
**Ainda não construída** — tem decisões em aberto na seção 16.

## 1. O que se quer

Hoje a Cascata de Escolhas é preenchida por uma pessoa, célula a célula, num
modal com dois campos (escolha e renúncia). São **126 células** por planejamento
(18 sínteses + 108 aberturas). Preencher sozinho é lento e, pior, perde o
principal: escolha estratégica é decisão de grupo, e a discussão é o produto.

O pedido, nas palavras do cliente:

> Clico no quadrante, abre as opções pra preenchimento; as pessoas fazem as
> sugestões através do quiz; depois a gente junta as ideias, arrasta para o
> quadrante a ideia já formatada, e se precisar edita de novo lá dentro do
> próprio quadrante. Que as pessoas também possam eleger todas as opções
> sugeridas dando uma estrelinha na melhor ideia.

E, na segunda rodada de refinamento:

> O usuário escolhe se vai responder a renúncia ou a pergunta selecionando o
> botão de seleção. Os usuários poderão enviar uma ou mais respostas e uma ou
> mais renúncias; todas ficam em dois quadrantes — um de respostas e um de
> renúncias — onde poderei unificar as respostas, assim como já fazemos na
> tempestade de ideias, antes de arrastar para o quadrante final, onde a
> resposta vai ser oficializada. Todas as respostas devem ficar salvas ao trocar
> de pergunta: posso navegar entre as perguntas e, ao voltar para uma que já foi
> respondida, aparecem todas as opções que os usuários deram.

Ou seja: o mesmo ritual da Tempestade de Ideias — sala com PIN, celular na mão,
nuvem ao vivo, votação, condutor tratando —, só que **dirigido a uma célula da
cascata** em vez de a um tema aberto.

## 2. Decisão central: reaproveitar a tempestade, não construir ao lado

A Tempestade já tem, funcionando e endurecido por defeito real: PIN de 6
dígitos, emissão e validação de token de participante, trava de força bruta,
teto de envios dentro do INSERT, agrupamento automático e manual de vozes
iguais, votação com teto, consulta periódica que não redesenha com campo em
foco, arraste por eventos de ponteiro que funciona no toque, e a tela do
participante sem sessão.

Construir uma segunda sala, com um segundo PIN e um segundo participante, seria
escrever **a segunda cópia de regras que já custaram depuração** — exatamente o
erro que o projeto evitou em `QlikSync::NEGOCIOS_FONTE` e em
`App\Services\CargaConteudo`. Duas cópias divergem na primeira correção, e aqui
a divergência é de segurança: as rotas públicas são as únicas de escrita sem
autenticação do sistema.

**Portanto: a rodada ganha um modo.** `coleta_rodada.modo` passa a ser
`TEMPESTADE` ou `CASCATA`. Tudo o que é sala (PIN, token, tentativa, teto,
polling, voto) continua sendo o mesmo código. O que muda é **o que se pergunta**
e **para onde a resposta vai**.

## 3. O quiz é uma sequência, não uma pergunta

Um PIN por célula obrigaria a sala a digitar um PIN novo a cada quadrante — 126
vezes no limite. Não é oficina, é castigo.

O desenho é o de uma sessão de quiz: **um PIN para o encontro inteiro**, e
dentro dele o condutor abre uma pergunta por vez. Quem está na sala vê a
pergunta ativa mudar sozinha na tela, responde, e espera a próxima. É o
comportamento que as pessoas já conhecem de Kahoot e Mentimeter, e é o que a
palavra "quiz" no pedido descreve.

Isso pede uma tabela nova pequena — o **roteiro** da sessão:

```
cascata_pergunta
  id, rodada_id, horizonte_id, driver_id, eixo_id (NULL = síntese),
  ordem, situacao ENUM('PENDENTE','ATIVA','ENCERRADA'), aberta_em
  UNIQUE (rodada_id, horizonte_id, driver_id, eixo_chave)
```

O condutor monta o roteiro antes (ex.: "as 6 aberturas de *Como Vencer* no H1")
ou acrescenta perguntas na hora. `UNIQUE` impede a mesma célula entrar duas
vezes no mesmo encontro.

O ciclo de vida da pergunta é `PENDENTE → ATIVA → ENCERRADA`, e **reabrir a
devolve para `ATIVA`** — não é um quarto estado. Encerrada, a pergunta continua
inteira na tela do condutor; o que ela para de aceitar é envio novo.

## 4. Modelo de dados

Mudanças, todas aditivas e por `garantirColuna`/`garantirFk`:

| Tabela | Coluna | Para quê |
|---|---|---|
| `coleta_rodada` | `modo ENUM('TEMPESTADE','CASCATA')` default `TEMPESTADE` | separa os dois ritos sem separar o código |
| `coleta_item` | `pergunta_id INT NULL` | a qual pergunta a sugestão responde |
| `coleta_item` | `tipo_resposta ENUM('ESCOLHA','RENUNCIA') NULL` | se a sugestão é a decisão ou o que se abre mão |
| `coleta_item.destino_tipo` | + `'CASCATA'` | a sugestão virou a escolha de uma célula |
| `cascata_pergunta` | tabela nova | o roteiro do encontro |

*(Desvio da Fase 1, para melhor: o plano previa `pergunta_ativa_id` na rodada,
mas a fonte da verdade da pergunta ativa é `cascata_pergunta.situacao='ATIVA'`
— dois lugares dizendo "qual é a ativa" dessincronizariam na primeira corrida,
e a coluna criaria FK circular entre rodada e pergunta.)*

`coleta_item.destino_id` passa a poder apontar para `cascata_escolha.id`, como
já aponta para `fator`, `cenario_item` e `desdobramento`. O vínculo continua
valendo nos dois sentidos: o selo "Coleta · Fulano" aparece na célula, e a ideia
mostra "Virou escolha ↗".

Como uma célula é preenchida pelos **dois lados** (a escolha e a renúncia),
`destino_id` sozinho não diz de qual lado a sugestão fala — quem responde isso é
`tipo_resposta`, que a sugestão já carrega.

### Vincular é muitos-para-um, e isso não custa tabela nenhuma

Várias sugestões podem ser vinculadas à mesma célula: `destino_id` é
muitos-para-um por natureza, e a Coleta já usa isso — quando a oficina agrupa
vozes iguais, N ideias apontam para UM destino. Nenhuma tabela nova é
necessária.

É o mesmo formato do `cascata_fator`, que hoje amarra vários fatores da SWOT a
uma célula como evidência. A diferença é o que cada coisa guarda:

- **os vínculos** são muitos — todas as sugestões que fundamentam aquela
  decisão, cada uma com autor, estrelas e tipo;
- **o texto da célula** é um só de cada lado — `escolha` e `renuncia` são
  `TEXT` únicos, e quem os redige é o condutor.

Ou seja: vincular cinco fichas não escreve cinco escolhas. Escreve **uma**
escolha, sustentada por cinco vozes que ficam registradas e visíveis na célula.

**O que NÃO muda:** `coleta_participante`, `coleta_voto`, `coleta_tentativa` e
`login_tentativa` seguem como estão. Nenhuma tabela nova de sala, nenhum token
novo.

## 5. Fluxo

### A tela do condutor tem três áreas

O painel ao vivo da pergunta ativa é dividido assim:

```
┌─ Respostas (escolha) ────┐  ┌─ Renúncias ──────────────┐
│  ficha  ficha  ficha     │  │  ficha  ficha            │
│  grupo(3)  ficha         │  │  grupo(2)                │
└──────────────────────────┘  └──────────────────────────┘
┌─ A célula ─ Como Vencer × H1 · Mercado ─────────────────┐
│  Escolha:  "Vencer por eficiência…"   ← 3 vozes ✕ ✕ ✕   │
│  Renúncia: — vazia —                                    │
└─────────────────────────────────────────────────────────┘
```

As duas primeiras são as **áreas de coleta**: tudo o que a sala mandou, cada
ficha com o autor e as estrelas. A terceira é o **destino final** — a célula de
verdade, com os dois campos que hoje já existem no modal, e embaixo de cada um
as fichas que o sustentam.

### Condutor (tela da Cascata)

1. Clica na célula → o detalhe abre como hoje, e ganha um botão
   **"Perguntar à sala"**.
2. Sem sessão aberta, ele cria uma (tema = o nome do encontro) e recebe o PIN +
   QR. Com uma sessão já aberta, a célula só entra no roteiro.
3. A pergunta fica **ativa**: a sala inteira vê aquela célula.
4. As fichas chegam ao vivo, cada uma na sua área conforme o tipo, com o número
   de estrelas.
5. Ele **unifica** vozes iguais dentro de cada área, arrastando uma ficha sobre
   a outra — o mesmo gesto e o mesmo mecanismo da Tempestade (`agrupado_em_id`,
   com o líder do grupo carregando o texto tratado).
6. Arrasta **uma ou mais** fichas (ou caixas de grupo) para a célula. Cada
   arraste **acrescenta** um vínculo, não substitui o anterior: dá para
   sustentar a decisão em três vozes diferentes.
7. O modal abre com o texto das fichas vinculadas daquele lado, uma por linha,
   como matéria-prima — o condutor redige **uma** escolha (ou **uma** renúncia)
   a partir delas. É aqui que "a ideia já formatada" do pedido acontece: a
   redação final é dele, as vozes ficam registradas embaixo.
8. Repete para o outro lado. A célula fica completa quando escolha e renúncia
   foram preenchidas — por arraste, digitação ou uma mistura das duas.
9. Navega para outra pergunta do roteiro, à vontade, para frente e para trás.

Desvincular é explícito: um ✕ na ficha listada dentro da célula tira só ela,
sem mexer no texto já redigido — quem decide se o texto muda é quem escreveu.

### Navegar é uma coisa; reabrir é outra

Trocar de pergunta **não encerra nem apaga nada**. Ao voltar para uma pergunta
já respondida, reaparecem todas as sugestões daquela célula, os grupos que já
tinham sido unificados, as estrelas e o que já foi vinculado — tudo continua
preso ao `pergunta_id`.

Mas **navegar não mexe na sala**. São duas coisas separadas, e essa é a
distinção que faz o encontro funcionar:

| | O que faz | Quem vê |
|---|---|---|
| **Navegar** (condutor) | põe a pergunta em foco na tela dele | só ele |
| **Ativar / Reabrir** | muda o que a sala está respondendo | todo mundo |

O condutor pode revisitar a pergunta 3 enquanto a sala ainda responde a 5, sem
que ninguém no celular perceba. Quando ele quer voltar a discutir a 3, aperta
**"Reabrir para a sala"** e aí sim os celulares mudam.

Reabrir serve para **refinar**: escolher outras opções, receber sugestões novas
de quem pensou melhor no assunto e reavaliar o que estava vinculado. As
sugestões antigas continuam lá — o que muda é que a pergunta volta a aceitar
envio.

Sem isso, o desenho anterior (voltar = reabrir automaticamente) tinha um efeito
ruim: o condutor não conseguiria **conferir** uma célula já tratada sem
bagunçar a tela de quem está na sala.

### O condutor pode excluir uma sugestão

Cada ficha, nos dois quadrantes, tem um ✕. Serve para o que sempre aparece numa
oficina: teste, duplicata óbvia, coisa fora de contexto, ofensa. Vale para
respostas e para renúncias, e é do condutor — passa pela mesma autorização da
triagem (`Auth::exigirTriagemColeta`), nunca do participante.

Excluir tem três consequências que o código precisa tratar, todas já resolvidas
na Tempestade e que aqui valem igual:

- a ficha **vinculada à célula** precisa soltar o vínculo antes de sumir, senão
  a célula mostra uma voz que não existe mais;
- a ficha que é **líder de um grupo** promove o próximo do grupo, e quem sai
  volta ao próprio texto;
- os **votos** dela caem junto (`coleta_voto` já é `ON DELETE CASCADE`), e as
  estrelas dos outros não podem ser recontadas para menos por causa disso.

### Participante (`/entrar/{pin}`, sem login)

1. Entra com o PIN e o nome, como hoje.
2. Vê a pergunta ativa **com o contexto que a torna respondível**: o horizonte
   (H1 · 2027–2029 · "Recuperação" + o objetivo), a linha base (Como Vencer) e
   o eixo (Mercado). Sem esse contexto a pergunta é abstrata demais para uma
   resposta útil.
3. Escolhe, num **par de botões**, se o que vai escrever é uma **Escolha** ou
   uma **Renúncia** — é uma pergunta só por célula, e o participante decide de
   qual lado quer contribuir. O padrão é *Escolha*.
4. Escreve a sugestão, em até **255 caracteres**, com o **contador visível**
   ("128/255") atualizando enquanto digita. O ditado por voz continua valendo.
5. Pode mandar **mais de uma de cada tipo**, até o teto: várias escolhas e
   várias renúncias para a mesma pergunta. A lista das próprias respostas mostra
   as duas famílias separadas, com o selo do tipo.
6. Quando o condutor abre a votação, dá a **estrela** nas melhores.
7. A pergunta muda sozinha quando o condutor avança — ou volta, se ele voltar.

## 6. Telas

- **Cascata** (`cascata.js`): botão "Perguntar à sala" no detalhe da célula;
  faixa de sessão ativa no topo (PIN, quantos entraram, pergunta atual); painel
  ao vivo das sugestões da pergunta ativa, com arraste até a célula.
- **Participante** (`participante.js`): um bloco novo para o modo CASCATA —
  cabeçalho com a célula, o par de botões Escolha/Renúncia, o campo de sugestão
  com contador de caracteres, a lista das próprias respostas (cada uma com o
  selo do tipo) e a votação por estrela. O bloco da tempestade continua intacto.

  O par de botões usa o tipo `botoes` do modal, que já existe — e vale o alerta
  registrado no `CLAUDE.md`: em `botoes` o id fica na **div** que agrupa os
  rádios, e ler `.value` dela devolve `undefined`. Aqui a tela é a do
  participante, que não usa a fábrica de modais, então o par é escrito à mão;
  ainda assim, o valor tem de sair do rádio marcado, não do contêiner.

  O contador de caracteres atualiza no `input` do próprio campo. Ele **não pode
  disparar redesenho**: a regra do polling — nunca redesenhar com campo em foco
  ou texto digitado — vale igual, e um contador que reconstrói o bloco fecharia
  o teclado no meio da frase.
- **Coleta**: sem mudança. As duas salas convivem porque a rodada declara o modo.

## 7. Regras que não podem ser afrouxadas

Herdadas da tempestade, e valem igual aqui (ver `CLAUDE.md`):

- o token do participante é **registrado** em `coleta_participante`, nunca
  auto-emitido; o nome vem do registro, jamais do corpo do pedido;
- tetos de sugestões e de votos vão **dentro do INSERT**
  (`INSERT ... SELECT ... WHERE (SELECT COUNT ...) < max`), senão dois envios
  simultâneos furam a contagem;
- PIN errado conta contra a origem em `coleta_tentativa`;
- exige `Content-Type` JSON — é o que obriga preflight e barra escrita
  cross-site;
- `/entrar` e `/api/publico/*` **não iniciam sessão**;
- a isenção de CSRF é **lista explícita**, nunca prefixo — cada rota pública
  nova precisa entrar na lista à mão, e essa é a armadilha mais fácil de cair
  nesta implementação;
- consulta periódica, **nunca SSE** (`php -S` é single-threaded);
- o polling **não redesenha com campo em foco ou texto digitado** — no celular
  isso fecha o teclado no meio da frase.

## 8. Pontos de atenção próprios desta funcionalidade

Coisas que a tempestade não tem e que vão morder se passarem batido:

1. **O teto de envios é por PERGUNTA e por TIPO.** Hoje o cap conta
   `WHERE rodada_id = ? AND participante_token = ?`. Mantido assim, o
   participante gasta as 5 sugestões na primeira pergunta e fica mudo pelo resto
   do encontro. Tem de contar `pergunta_id` **e** `tipo_resposta`: como cada
   pessoa pode mandar várias escolhas e várias renúncias, um teto único por
   pergunta deixaria quem escreveu cinco escolhas sem poder propor nenhuma
   renúncia. A contagem continua **dentro do INSERT**, como hoje.
2. **O teto de votos é por PERGUNTA, e só por pergunta.** Cada pessoa tem N
   estrelas naquela célula e gasta onde quiser — todas em escolhas, todas em
   renúncias ou divididas. Diferente do teto de envios, que é por
   (pergunta, tipo): ali a separação existe para ninguém ficar sem poder
   contribuir de um dos lados; aqui, dividir por tipo tiraria da pessoa a
   liberdade de dizer que, naquela célula, o que importa é a renúncia.
3. **Agrupar não pode cruzar os tipos.** O arraste de unificação vale dentro da
   mesma área: juntar uma escolha com uma renúncia produziria um grupo cujo
   líder não pertence a lado nenhum. A validação é do servidor, não da tela —
   o gesto pode ser bloqueado no arraste, mas quem recusa de verdade é o
   `agrupar`, comparando `tipo_resposta` dos dois.
4. **Muitos vínculos, um texto por lado.** Vincular acumula e nunca sobrescreve
   — é registro de origem. O que é único é o **texto** da célula: `escolha` e
   `renuncia` são um só cada. Trocar esse texto por cima de algo já escrito pede
   confirmação mostrando o atual, venha do quiz ou da mão de alguém (mesma regra
   da carga de conteúdo: ninguém perde uma decisão sem ver).
5. **Vincular e redigir são operações separadas.** Arrastar uma ficha não
   reescreve a célula sozinho; ele acrescenta o vínculo e **oferece** o texto no
   modal. Se as duas coisas virassem uma, o segundo arraste apagaria a redação
   que o condutor acabou de ajustar — e ele arrasta a segunda ficha justamente
   para somar, não para recomeçar.
6. **O envio valida a pergunta ATIVA no servidor**, não a que estava na tela do
   celular: entre o participante começar a digitar e apertar enviar, o condutor
   pode ter avançado, encerrado ou reaberto outra. A resposta pertence à
   pergunta que estava ativa **no momento do envio** — e é isso que o servidor
   grava, não o que o corpo do pedido afirma. Pergunta `ENCERRADA` recusa envio,
   com a mensagem dizendo que ela foi fechada, e não um erro genérico.
7. **Excluir uma sugestão é do condutor, nunca do participante.** O participante
   já pode corrigir a própria resposta enquanto ela está `NOVO` (o escopo do
   UPDATE por token é a guarda); apagar a de outra pessoa é ato de condução e
   passa por `Auth::exigirTriagemColeta`.
8. **Excluir uma ficha vinculada solta o vínculo primeiro.** Senão a célula
   passa a listar uma voz que não existe mais — o mesmo defeito que a Coleta já
   teve quando apagar o destino deixava a ideia apontando para um id morto.
9. **O limite de 255 caracteres é do servidor, não da tela.** O `maxlength` do
   campo é conforto; o corte que vale é o do `PublicoController`, que hoje usa
   `MAX_TEXTO = 400` para a tempestade. São dois limites diferentes convivendo:
   400 na tempestade, 255 no quiz da cascata.
10. **O tipo da resposta vem do participante e precisa ser validado.**
   `tipo_resposta` só aceita `ESCOLHA` ou `RENUNCIA`, e qualquer outra coisa
   vira `ESCOLHA` — nunca um valor livre vindo do corpo do pedido, pela mesma
   razão que o nome do participante vem do registro e não do corpo.
11. **Voto do próprio autor.** Na tempestade isso não é travado. Vale decidir se
   aqui continua livre.
12. **Sessão órfã.** Rodada de cascata aberta e esquecida trava a criação de uma
   tempestade (hoje há a guarda "já existe rodada aberta"). Com dois modos, a
   guarda precisa ser por modo, ou a mensagem precisa dizer qual sala está
   aberta.

## 9. Fases de entrega

Cada fase é útil sozinha e pode ser validada antes da seguinte.

**Fase 1 — o quiz de uma célula, ponta a ponta.** ✅ *entregue em 06/08/2026;
além do combinado, entraram: trocar o alvo da pergunta (síntese ↔ eixos) pelo
próprio botão "Perguntar à sala", a exclusão de sugestão pelo condutor, o
desvincular pelo ✕ da voz na célula, e o "✓ usada" na tela do participante.*

Modelo (`modo`, `cascata_pergunta`, `pergunta_id`, `tipo_resposta`), abrir
sessão a partir da célula, tela do participante respondendo com o par
Escolha/Renúncia e o contador de 255, as duas áreas de coleta no painel do
condutor, e vincular uma ou mais sugestões ao lado certo da célula — por botão
("usar esta resposta"), ainda sem arraste, com o texto oferecido no modal. Sem estrela, sem agrupamento,
sem roteiro. *É a menor coisa que já muda o jeito de trabalhar.*

**Fase 2 — navegar entre perguntas.** ✅ *entregue em 06/08/2026: roteiro com
alvos em lote (síntese + eixos numa marcação só), "Ver" como navegação local
(decisão 11 — a sala não percebe), abrir/reabrir/encerrar explícitos por
pergunta, "Próxima pergunta →", progresso "Pergunta N de M" na faixa e no
celular, e remoção de pendente sem sugestões.*

Acrescentar células ao encontro, trocar a pergunta ativa para frente e para
trás, e a garantia que o cliente pediu por escrito: **voltar mostra tudo o que
já foi respondido**. Progresso ("pergunta 4 de 12") e o roteiro montado antes do
encontro entram aqui. É a fase que transforma o quiz de uma célula em reunião de
verdade — por isso vem antes da estrela e do arraste.

**Fase 3 — a estrela.**
Reaproveita a votação da tempestade, com o teto por pergunta. Ordena as fichas
por estrela dentro de cada área.

**Fase 4 — unificar e arrastar.**
Agrupamento automático (texto equivalente) e manual (ficha sobre ficha, sem
cruzar tipos), e o arraste da ficha ou da caixa do grupo até a célula, com o
modal abrindo já preenchido. É a fase mais cara: reaproveita o arraste por
eventos de ponteiro da Tempestade, que tem regras próprias (passos de 8px,
listeners no `document`, gesto que engole o clique seguinte).

## 10. Decisões tomadas (06/08/2026)

1. **Escopo da sessão** — **um PIN para o encontro inteiro**, com o condutor
   trocando a pergunta ativa. É o que justifica a tabela de roteiro.
2. **Renúncia** — **uma pergunta por célula**, e o participante escolhe num par
   de botões se responde a *Escolha* ou a *Renúncia*. Resposta de até **255
   caracteres**, com contador visível enquanto digita. Não são duas perguntas
   por célula: é uma, com dois lados possíveis.
3. **Estrela** — **N estrelas**, reaproveitando o `max_votos` da tempestade,
   contado por pergunta.
4. **Sobrescrever o texto da célula** — **pedir confirmação**, mostrando o
   texto atual antes de substituir.
5. **Quantas respostas por pessoa** — **várias de cada tipo**: uma ou mais
   escolhas e uma ou mais renúncias na mesma pergunta, até o teto, que passa a
   contar por (pergunta, tipo).
6. **Duas áreas de coleta**, uma de Respostas e uma de Renúncias, com
   unificação de vozes iguais dentro de cada uma — o mesmo gesto da Tempestade —
   antes de a ficha ser levada para a célula, que é onde a resposta se
   oficializa.
7. **Navegar entre perguntas não perde nada.** Voltar a uma pergunta já
   respondida mostra todas as sugestões, os grupos, as estrelas e o que já foi
   oficializado. Não há "encerrar pergunta" nesta versão.
8. **A estrela é por pergunta.** Cada pessoa tem N estrelas na célula e as gasta
   onde quiser, entre respostas e renúncias, sem cota separada por lado.
9. **Vincular aceita uma ou mais sugestões por lado.** Muitos vínculos
   registrados, um texto redigido por lado. Nenhuma tabela nova: `destino_id`
   já é muitos-para-um, como a Coleta faz com as vozes agrupadas.
10. **Quem responde** — **qualquer pessoa com o PIN**, sem cadastro, igual à
    Tempestade de Ideias. A sala é aberta, e as guardas continuam sendo o token
    do participante, o teto por INSERT e a trava de força bruta do PIN.
11. **Navegar ≠ reabrir.** O condutor circula pelas perguntas sem mexer na tela
    de ninguém; a sala só muda quando ele **ativa ou reabre**. Reabrir devolve a
    pergunta para `ATIVA` e serve para refinar: trocar as opções vinculadas e
    receber sugestões novas.
12. **O condutor exclui sugestões** — respostas e renúncias —, com o ✕ na
    ficha. Passa por `exigirTriagemColeta`; o participante segue podendo apenas
    corrigir a própria, enquanto ela não foi tratada.

Nada permanece em aberto no desenho. As perguntas que sobram são de
implementação e aparecem na hora de construir cada fase.

---

# Parte II — A sala é do PROJETO, não da tela

Revisão de escopo pedida em 06/08/2026, depois das Fases 1 e 2 entregues, com
as decisões fechadas em 07/08/2026 (seção 16). **Nada desta parte foi
construído**: ela existe para a decisão vir antes do código.

## 11. O que se quer agora

> Vamos ter vários quiz durante o projeto, em cada tela — por exemplo definição
> de cenários, análise Porter, PESTEL: todas essas análises vão ter um quiz.
> Porém o quiz deve ser único para o projeto todo: o usuário escaneia o QR code
> uma vez e as interações serão com base no quiz que estiver aberto. E só pode
> ter um quiz aberto: se eu abrir um sem ter fechado o outro, ele pede para
> fechar o que está aberto, para dar sequência ao planejamento.

Três exigências, e vale separá-las porque **duas já estão prontas e uma não**:

| Exigência | Situação |
|---|---|
| Um quiz aberto por vez, com aviso para fechar o outro | ⚠️ existe como **recusa**; vira **confirmação que encerra a anterior** (seção 16) |
| Uma pergunta ativa por vez, mudando sozinha no celular | ✅ já existe (Fases 1 e 2) |
| **Um PIN só para o projeto todo, valendo em todas as telas** | ❌ é o que falta |

## 12. O que trava hoje: a sala tem um rito

`coleta_rodada.modo` (`TEMPESTADE` ou `CASCATA`) diz o rito da **sala inteira**.
Isso foi a decisão certa quando existiam dois ritos, mas é exatamente o que
impede o PIN único: para perguntar uma célula da cascata e depois um fator do
PESTEL seriam **duas rodadas**, dois PINs, e o participante escaneando de novo
no meio do encontro.

O acoplamento está em três lugares:

1. **`coleta_rodada.modo`** — `PublicoController` decide pelo modo da rodada o
   que devolver (`rodada()`, linhas 68-69) e o que aceitar (`resposta()` recusa
   TEMPESTADE, `ideia()`/`votar()` recusam CASCATA).
2. **`cascata_pergunta`** só sabe apontar para uma célula da cascata
   (`horizonte_id`, `driver_id`, `eixo_id`). Não há onde dizer "esta pergunta é
   sobre o quadrante Ameaças da SWOT de 2026".
3. **`tipo_resposta IS NULL`** é a marca que isola os dois ritos (o `listar()` e
   o `exigirItem()` da Coleta). Ela funciona porque hoje **toda** resposta de
   quiz tem lado (ESCOLHA/RENUNCIA). Uma pergunta de PESTEL não tem lado — a
   resposta viria com `tipo_resposta` nulo e **vazaria para a tela da Coleta**,
   tratável pela triagem da tempestade. É o mesmo defeito que a revisão da Fase
   1 pegou em `ideia()`, por outro caminho.

## 13. O que já está pronto e não precisa ser refeito

Antes do que muda, o que se aproveita inteiro — e é a maior parte:

- **O vínculo já é polimórfico.** `coleta_item.destino_tipo` já é
  `ENUM('CENARIO','FATOR','ACAO','CASCATA')`: amarrar uma sugestão a um item de
  cenário ou a um fator do PESTEL **já tem casa no banco**, com o mesmo par
  `destino_tipo`/`destino_id` que a Coleta usa desde sempre.
- **O roteiro inteiro** (ordem, situação, pergunta ativa, foco, progresso,
  reabrir, encerrar, remover pendente) é mecânica de sequência — ela não sabe
  nada sobre cascata além de para onde a pergunta aponta.
- **A sala**: PIN, token registrado, tetos dentro do INSERT, trava de força
  bruta, polling que não redesenha digitando, `/entrar/{pin}`.
- **A guarda de um aberto por vez**, com a mensagem já dizendo qual rito está
  aberto.

## 14. O modelo novo

Três mudanças, todas aditivas.

**(a) A pergunta ganha um ALVO polimórfico.** `cascata_pergunta` passa a
`quiz_pergunta` (`RENAME TABLE` guardado por `information_schema`, como o
migrate já faz em tudo):

```
quiz_pergunta
  id, rodada_id, ordem, situacao, aberta_em
  alvo_tipo ENUM('CASCATA','CENARIO','FATOR','LIVRE')
  enunciado VARCHAR(255) NULL      -- a pergunta nas palavras do condutor
  -- CASCATA: a célula (como hoje)
  horizonte_id, driver_id, eixo_id
  -- CENARIO e FATOR: a análise é anual
  ano SMALLINT NULL
  -- FATOR: qual coluna do PESTEL/Porter/SWOT
  etapa ENUM('PESTEL','PORTER','SWOT') NULL
  categoria VARCHAR(40) NULL
  -- LIVRE: nada. O alvo é a própria fila da Coleta.
```

Colunas nulas por tipo, e não uma `alvo_id` genérica: a célula da cascata
precisa de **três** ids, que não cabem num só. É o mesmo formato de
`diario_bordo` (`ref_tipo`/`ref_id`) levado ao caso que tem chave composta.

`LIVRE` é a **Tempestade de Ideias** dentro do roteiro (decisão 1 da seção 16):
a pergunta não aponta para lugar nenhum, a resposta cai na fila da Coleta e
segue o rito de sempre — matriz de prioridade, agrupar, triagem, encaminhar.
Ela é o único alvo cujas respostas nascem com `origem='TEMPESTADE'`; é
justamente por isso que a marca de isolamento precisa ser explícita e não
derivada de `pergunta_id` ser nulo.

**(b) A marca de isolamento sai de `tipo_resposta` e vira explícita.**

```
coleta_item.origem ENUM('TEMPESTADE','QUIZ') NOT NULL DEFAULT 'TEMPESTADE'
```

Nunca solta, independente de FK — a lição que já está no `CLAUDE.md` sobre não
usar `pergunta_id` (FK SET NULL) para isso. `tipo_resposta` fica livre para ser
o **lado da resposta**, que só alguns alvos têm.

**(c) `coleta_rodada.modo` deixa de decidir o rito.** Ele continua distinguindo
a tempestade clássica (matriz de prioridade, triagem, votação) da sessão de
quiz, mas **dentro** da sessão de quiz quem manda é o alvo da pergunta ativa.
`PublicoController` passa a olhar a pergunta, não a rodada.

### O lado da resposta, por alvo

| Alvo | A sala escolhe um lado? | Vira |
|---|---|---|
| Cascata | **Sim**: Escolha \| Renúncia | `cascata_escolha.escolha` / `.renuncia` |
| Cenário | **Sim**: Situação atual \| Tendência | `cenario_item` do tipo escolhido |
| PESTEL / Porter / SWOT | **Não** — a categoria É a pergunta | `fator` daquela etapa e categoria |
| Livre (Tempestade) | **Não** | `coleta_item` na fila, como hoje |

Ou seja, o par de botões que já existe no celular serve aos dois primeiros sem
mudança nenhuma de conceito: muda só o rótulo. Para PESTEL/Porter/SWOT o
condutor pergunta **uma categoria por vez** ("me deem ameaças"), o que é melhor
para a discussão e dispensa seletor no celular.

A lista branca do `tipo_resposta` deixa de ser um ENUM fixo e passa a ser
**derivada do alvo pelo servidor** — de constantes que já existem
(`FatorController::CATEGORIAS`, os tipos do `CenarioController`). O corpo do
pedido continua sem poder inventar valor.

## 15. O que muda no que já foi entregue

Honestamente, para você dimensionar: **nada se perde, e a maior parte só troca
de nome**.

| Entregue | O que acontece |
|---|---|
| `cascata_pergunta` (Fase 1/2) | vira `quiz_pergunta` com `alvo_tipo='CASCATA'`; as linhas existentes migram com um UPDATE |
| Roteiro, foco, progresso, reabrir (Fase 2) | intactos — não sabem o que é uma célula |
| Vínculo à célula (Fase 1) | intacto; ganha irmãos em `CenarioController` e `FatorController` |
| `CascataQuizController` | vira `QuizController` (a sala é do projeto) |
| Guardas de modo no `PublicoController` | passam a olhar o alvo da pergunta ativa |
| Tela do participante | o cabeçalho vira genérico; o par de botões passa a ter rótulos por alvo, e some no alvo que não tem lado |
| Faixa da sessão em `cascata.js` | **precisa virar componente compartilhado** — cinco telas reescrevendo o painel divergem na primeira mudança, como já aconteceu com `panorama()` e `camposAcao` |
| `RodadaController::abrir` (Tempestade) | passa a **enfileirar uma pergunta `LIVRE`** na sala do projeto em vez de criar uma rodada própria |
| Guarda de "uma rodada aberta" | de recusa para **confirmação**: com `confirmar_encerrar=1`, encerra a sala anterior e abre a nova (seção 16, decisão 1) |

## 16. Decisões tomadas

Fechadas em 07/08/2026. Nada da Parte II fica em aberto.

**1. A Tempestade entra na mesma sala, como pergunta `LIVRE`.** O PIN serve
para tudo. Não existe mais "a rodada da tempestade" separada da "rodada do
quiz": existe **a sala do projeto**, e a Tempestade é um item do roteiro que
não aponta para célula nenhuma — a resposta cai na fila da Coleta e segue o
rito de sempre.

**2. Abrir e fechar é por tela, e a colisão vira pergunta, não recusa.** Cada
tela (Cenário, PESTEL, Porter, SWOT, Cascata, Tempestade) tem seu botão de
abrir e fechar a sala. Ao abrir com outra tela ainda aberta — o caso real de
quem esqueceu de fechar —, o sistema **pergunta se deseja encerrar a discussão
anterior** para liberar a tela atual, e ao confirmar faz as duas coisas num
pedido só. O enunciado do aviso é montado com a tela aberta e a tela pedida
("A sala está aberta em *Análise PESTEL*. Encerrar aquela discussão e abrir em
*Cascata de Escolhas*?"), porque um texto genérico não diz onde a sala ficou.

Consequências no back-end, e é onde mora o risco:

- `POST /api/coleta/rodadas` e o `abrir()` do quiz passam a aceitar
  `confirmar_encerrar`. **Sem ele a recusa continua** — 409 com o nome da tela
  aberta no corpo, para a tela poder perguntar. Abrir encerrando calado seria
  derrubar a discussão de outra pessoa por um clique distraído.
- O encerra-e-abre é **um pedido**, não dois: dois pedidos deixam a janela em
  que a sala está fechada e ninguém consegue responder, e o segundo pode falhar
  depois de o primeiro ter encerrado.
- **A pergunta muda conforme a tela ativa**: abrir na tela X enfileira (ou
  reativa) a pergunta cujo alvo é X e a torna `ATIVA`. Quem está com o celular
  na mão vê o enunciado trocar sozinho, sem escanear nada — é exatamente o
  mecanismo da Fase 2, agora disparado pela navegação do condutor.
- Reabrir uma tela já discutida **não apaga o que ela recebeu**: as sugestões,
  os grupos e as estrelas daquela pergunta continuam lá (decisão 7 da Parte I).

**3. PESTEL / Porter / SWOT: uma categoria por pergunta.** O condutor pergunta
"me deem ameaças" e a sala responde só aquilo. Sem seletor de quadrante no
celular, e sem par de botões — esse alvo não tem lado.

**4. O condutor redige ao aceitar.** Vale para todos os alvos, como já vale na
cascata: as vozes da sala são matéria-prima, o texto que vira `fator` ou
`cenario_item` é redigido por quem conduz, com os vínculos registrados em
`destino_tipo`/`destino_id`. Não há bancada de triagem intermediária para os
alvos de análise — a bancada continua sendo coisa da Tempestade (`LIVRE`), que
é justamente onde o rito dela faz sentido.

**5. Ordem de entrega: Cenário primeiro.** É a tela mais simples (dois lados,
um texto) e serve de gabarito para as outras. Depois PESTEL, Porter e SWOT, que
compartilham o mesmo alvo `FATOR` e saem quase juntas. A Cascata já está pronta
e só troca de nome; a Tempestade entra por último, porque é a que mais mexe em
código que já roda em produção.

### Fase 3 — o recorte

Com isso fechado, a Fase 3 é: `quiz_pergunta` com alvo polimórfico,
`coleta_item.origem`, a faixa da sessão como componente compartilhado, o
encerra-e-abre confirmado, e a tela de Cenário como primeira consumidora.
PESTEL/Porter/SWOT e a migração da Tempestade vêm na Fase 4.

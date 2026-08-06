# Cascata colaborativa — quiz de preenchimento das células

Plano de implementação. **Nada foi construído ainda**: este documento existe para
a decisão vir antes do código, e tem uma seção de decisões em aberto no fim.

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

## 4. Modelo de dados

Mudanças, todas aditivas e por `garantirColuna`/`garantirFk`:

| Tabela | Coluna | Para quê |
|---|---|---|
| `coleta_rodada` | `modo ENUM('TEMPESTADE','CASCATA')` default `TEMPESTADE` | separa os dois ritos sem separar o código |
| `coleta_rodada` | `pergunta_ativa_id INT NULL` | qual célula a sala está respondendo agora |
| `coleta_item` | `pergunta_id INT NULL` | a qual pergunta a sugestão responde |
| `coleta_item` | `tipo_resposta ENUM('ESCOLHA','RENUNCIA') NULL` | se a sugestão é a decisão ou o que se abre mão |
| `coleta_item.destino_tipo` | + `'CASCATA'` | a sugestão virou a escolha de uma célula |
| `cascata_pergunta` | tabela nova | o roteiro do encontro |

`coleta_item.destino_id` passa a poder apontar para `cascata_escolha.id`, como
já aponta para `fator`, `cenario_item` e `desdobramento`. O vínculo continua
valendo nos dois sentidos: o selo "Coleta · Fulano" aparece na célula, e a ideia
mostra "Virou escolha ↗".

Como uma célula é preenchida por **duas** sugestões (a escolha e a renúncia),
`destino_id` sozinho não diz qual das duas é — quem responde isso é
`tipo_resposta`, que a sugestão já carrega.

**O que NÃO muda:** `coleta_participante`, `coleta_voto`, `coleta_tentativa` e
`login_tentativa` seguem como estão. Nenhuma tabela nova de sala, nenhum token
novo.

## 5. Fluxo

### Condutor (tela da Cascata)

1. Clica na célula → o detalhe abre como hoje, e ganha um botão
   **"Perguntar à sala"**.
2. Sem sessão aberta, ele cria uma (tema = o nome do encontro) e recebe o PIN +
   QR. Com uma sessão já aberta, a célula só entra no roteiro.
3. A pergunta fica **ativa**: a sala inteira vê aquela célula.
4. A tela mostra as sugestões chegando ao vivo, **em duas colunas** — o que a
   sala propôs como escolha e o que propôs como renúncia —, cada ficha com o
   número de estrelas.
5. Ele agrupa vozes iguais (arrastar uma ficha sobre a outra, como hoje).
6. Arrasta a ficha vencedora **para a célula** → abre o modal já preenchido no
   campo correspondente (escolha ou renúncia, conforme o tipo da ficha), para
   ajustar a redação. A célula fica completa quando as duas foram preenchidas —
   por arraste, digitação ou uma mistura das duas.
7. Avança para a próxima pergunta do roteiro.

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
5. Quando o condutor abre a votação, dá a **estrela** nas melhores.
6. A pergunta muda sozinha quando o condutor avança.

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

1. **O teto de envios é por PERGUNTA, não por rodada.** Hoje o cap conta
   `WHERE rodada_id = ? AND participante_token = ?`. Mantido assim, o
   participante gasta as 5 sugestões na primeira pergunta e fica mudo pelo resto
   do encontro. Tem de contar `pergunta_id`.
2. **O teto de votos também.** `max_votos` passa a valer por pergunta, pela
   mesma razão.
3. **Uma célula tem uma decisão só, de cada lado.** Diferente da SWOT, onde
   muitos fatores coexistem, aqui a sugestão aceita **substitui** o campo
   correspondente da célula. Sobrescrever pede confirmação mostrando o texto
   atual — e vale tanto para o que veio do quiz quanto para o que alguém
   escreveu à mão (é a mesma regra da carga de conteúdo: ninguém perde uma
   decisão sem ver).
4. **Pergunta encerrada não aceita resposta atrasada.** O envio valida a
   pergunta ativa no servidor, não a que estava na tela do celular.
5. **O limite de 255 caracteres é do servidor, não da tela.** O `maxlength` do
   campo é conforto; o corte que vale é o do `PublicoController`, que hoje usa
   `MAX_TEXTO = 400` para a tempestade. São dois limites diferentes convivendo:
   400 na tempestade, 255 no quiz da cascata.
6. **O tipo da resposta vem do participante e precisa ser validado.**
   `tipo_resposta` só aceita `ESCOLHA` ou `RENUNCIA`, e qualquer outra coisa
   vira `ESCOLHA` — nunca um valor livre vindo do corpo do pedido, pela mesma
   razão que o nome do participante vem do registro e não do corpo.
7. **Voto do próprio autor.** Na tempestade isso não é travado. Vale decidir se
   aqui continua livre.
8. **Sessão órfã.** Rodada de cascata aberta e esquecida trava a criação de uma
   tempestade (hoje há a guarda "já existe rodada aberta"). Com dois modos, a
   guarda precisa ser por modo, ou a mensagem precisa dizer qual sala está
   aberta.

## 9. Fases de entrega

Cada fase é útil sozinha e pode ser validada antes da seguinte.

**Fase 1 — o quiz de uma célula, ponta a ponta.**
Modelo (`modo`, `cascata_pergunta`, `pergunta_id`, `tipo_resposta`), abrir
sessão a partir da célula, tela do participante respondendo com o par
Escolha/Renúncia e o contador de 255, painel ao vivo do condutor nas duas
colunas, e aceitar uma sugestão para dentro do campo certo da célula. Sem
estrela, sem arraste, sem roteiro. *É a menor coisa que já muda o jeito de
trabalhar.*

**Fase 2 — a estrela.**
Reaproveita a votação da tempestade, com o teto por pergunta. Ordena as
sugestões por estrela no painel.

**Fase 3 — agrupar e arrastar.**
Agrupamento automático (texto equivalente) e manual (ficha sobre ficha), e o
arraste da ficha até a célula, com o modal abrindo já preenchido.

**Fase 4 — o roteiro.**
Montar a lista de células antes do encontro, avançar/voltar, barra de progresso
("pergunta 4 de 12") e o resumo do encontro no fim.

## 10. Decisões tomadas (06/08/2026)

1. **Escopo da sessão** — **um PIN para o encontro inteiro**, com o condutor
   trocando a pergunta ativa. É o que justifica a tabela de roteiro.
2. **Renúncia** — **uma pergunta por célula**, e o participante escolhe num par
   de botões se responde a *Escolha* ou a *Renúncia*. Resposta de até **255
   caracteres**, com contador visível enquanto digita. Não são duas perguntas
   por célula: é uma, com dois lados possíveis.
3. **Estrela** — **N estrelas**, reaproveitando o `max_votos` da tempestade,
   contado por pergunta.
4. **Sobrescrever célula preenchida** — **pedir confirmação**, mostrando o texto
   atual antes de substituir.

Permanece em aberto, com o padrão da tempestade valendo até alguém decidir
diferente: **quem responde** é qualquer pessoa com o PIN, sem cadastro — igual à
Tempestade de Ideias. Se a cascata precisar de sala fechada (só usuários
cadastrados), é uma mudança de autorização e vale decidir antes da Fase 1, não
depois.

# CLAUDE.md — SVL (Sistema de Validação de Lançamentos)

> Este arquivo é lido automaticamente pelo Claude Code em toda sessão.
> Contém **invariantes**. Não contém tarefas. Tarefas ficam em `docs/specs/`.

---

## 1. Contexto do projeto

**Organização:** Copérdia — cooperativa agroindustrial (Concórdia/SC), ~20.000 associados.
**Área:** Controladoria Estratégica.
**Objetivo do sistema:** validar, de forma automatizada e auditável, se cada lançamento
contábil é **pertinente ao centro de custo (CC)** em que foi alocado, considerando
(a) elegibilidade estrutural conta × perfil de CC e (b) aderência ao histórico e à
sazonalidade daquele par.

**Exemplo canônico do problema:** um CC cujo perfil é exclusivamente "Pessoal
Administrativo" não deve receber lançamento em conta de Frete/Logística. Hoje isso
só é descoberto no fechamento, por inspeção manual.

**O sistema entrega:** um score por lançamento (IAL, 0–100), um semáforo
(verde/amarelo/vermelho) e uma fila de exceções com trilha de auditoria.

---

## 2. Princípios arquiteturais (NÃO NEGOCIÁVEIS)

1. **Determinístico antes de estatístico; estatístico antes de LLM.**
   A Camada 1 (matriz de elegibilidade) é regra explícita e curada. A Camada 2
   (histórico/sazonalidade) é estatística sobre o próprio razão. A Camada 3 (LLM)
   só atua em casos AMARELOS e só **sugere** — nunca decide, nunca bloqueia.

2. **Toda decisão precisa ser explicável em uma frase para o Conselho.**
   Se o motor reprova um lançamento, a saída deve dizer *por quê* em linguagem
   contábil, não em jargão estatístico. Nada de "score baixo" sem motivo textual.

3. **Shadow mode por padrão.** O sistema nasce DETECTIVO (roda em D+1, observa e
   reporta). Modo PREVENTIVO (bloqueio no ato) só é ativado por flag de configuração,
   após calibragem homologada. Nunca implemente bloqueio como comportamento default.

4. **Trilha de auditoria imutável.** Toda exceção, toda aprovação, toda alteração de
   regra grava: quem, quando, valor anterior, valor novo, justificativa. Nada é
   deletado — apenas versionado com `vigencia_inicio` / `vigencia_fim`.

5. **Qlik calcula, o SVL opera.** Quando o indicador já existir como master measure
   no Qlik, o SVL consome — não reconstrói o cálculo a partir de campos crus.
   O SVL é dono apenas da lógica de validação e da matriz de elegibilidade.

6. **Rateios automáticos ficam FORA do motor.** Lançamentos com
   `origem_lancamento = 'RATEIO'` não são pontuados — o CC é consequência de uma
   regra de rateio, não de uma decisão humana. Pontuá-los gera ruído puro.

---

## 3. Regras de negócio obrigatórias (Copérdia)

Aplicar SEMPRE em qualquer extração ou consulta ao DRE-Gerencial:

- `%ParticipacaoSocietaria = 'Sem Part. Societária'` (expurgar participações societárias)
- Excluir o negócio **18 — ÁREA APOIO AOPER**
- Excluir o negócio **20 — ÁREA ADM MATRIZ**
- Considerar dados **a partir de janeiro** para comparação entre períodos

Filiais que são infraestrutura (silo/CD), não varejo — `FlagPerfilFaturamento = 'Apenas SILO'`,
excluir de análises de varejo/giro:

- 80 — SILO IRINEOPOLIS
- 99 — ITAIOPOLIS LUCENA
- 116 — CD SEVERIANO DE ALMEIDA
- 122 — SILO PAPANDUVA

**Qlik — app de referência:**
- DRE-Gerencial: appId `0a23b6b3-59c8-4b8e-aa48-10b24410bcba`
- `Valor Saldo Gerencial` em Mês/Ano = M é o **saldo de ABERTURA de M** (= fechamento de M-1).
  Para obter o fechamento de junho/2026, consultar "jul 2026".
- `Valor Realizado` **não** tem offset.
- Meses são abreviações minúsculas em português: jan, fev, mar … dez.
- `FlagLancamento={1}` esteve despopulado em recargas recentes — não confiar sem validar.
- **Janeiro/2025 tem transação crua mas zero em Valor Realizado** (falha de carga).
  Tratar como lacuna conhecida ao montar a base histórica — não interpretar como
  "mês sem despesa". Excluir jan/2025 das estatísticas ou imputar com marcação explícita.
- O conector MCP do Qlik precisa ser ligado manualmente a cada sessão. Ausência de
  resultado = conector desligado, não "sem dados".

---

## 4. Stack e convenções

| Camada | Tecnologia |
|---|---|
| Motor de validação | Python 3.11 + pandas + numpy |
| Persistência | MySQL 8 |
| API | Node.js + Express |
| Front (fila de exceções) | React + Vite |
| Extração | Qlik (QVD/MCP) → parquet em `data/` |

**Convenções:**
- Código, nomes de tabela e nomes de coluna em **snake_case, português**
  (`centro_custo`, `conta_contabil`, `valor_lancamento`). O usuário final é contábil.
- Comentários e docstrings em **português**.
- Nenhum número mágico no código. Todo threshold vive em `config/parametros.yaml`
  e é versionado.
- Valores monetários: `DECIMAL(18,2)` no MySQL. **Nunca `float` para dinheiro.**
- Datas: sempre `date`/`datetime` nativos, nunca string.
- Nada de `data/` no git (`.gitignore`) — contém razão contábil real.

---

## 5. Vocabulário do domínio (usar exatamente estes termos)

| Termo | Significado |
|---|---|
| **IAL** | Índice de Aderência do Lançamento (0–100). Score composto final. |
| **Perfil de CC** | Classe funcional do centro de custo (Pessoal, Produtivo, Logístico, Comercial, Infraestrutura, Lavoura). |
| **Par** | Combinação (conta_contabil, centro_custo). Unidade de análise estatística. |
| **Par inédito** | Par sem ocorrência nos últimos 36 meses. Alerta máximo. |
| **Matriz de elegibilidade** | Tabela conta × perfil com veredicto PERMITIDO / JUSTIFICAR / BLOQUEADO. |
| **Carência** | Período em que um CC novo herda o comportamento do seu perfil por não ter histórico próprio. |
| **Shadow mode** | Motor roda e pontua, mas não bloqueia nada. |

---

## 6. Método de trabalho

- **Um PR por item de spec.** Uma sessão por PR. `/clear` entre sessões.
- Antes de escrever código, leia o spec correspondente em `docs/specs/`.
- Ao concluir um PR, atualize o checkbox correspondente em `docs/ROADMAP.md`.
- Se um spec estiver ambíguo, **pergunte antes de assumir**. Regra contábil errada
  assumida silenciosamente é pior que atraso.
- Teste unitário obrigatório para: cálculo de MAD, índice sazonal, composição do IAL
  e regra de carência. São as quatro peças onde erro passa despercebido.

---

## 7. O que NUNCA fazer

- Nunca usar **média + desvio-padrão** para faixa de valor esperada. Dado contábil tem
  cauda longa; um único lançamento grande sequestra a média. Usar **mediana + MAD**.
- Nunca deletar registro de exceção ou de regra. Versionar.
- Nunca ativar bloqueio preventivo sem flag explícita em config.
- Nunca deixar o LLM alterar a matriz de elegibilidade. O LLM sugere; humano aprova.
- Nunca commitar dados reais do razão, CPF/CNPJ de associado ou credencial.
- Nunca tratar CC novo como anomalia — ver regra de carência.

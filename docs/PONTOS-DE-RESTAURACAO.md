# Pontos de restauração — um por PR mesclado

Pedido do cliente (2026-11-27): "a cada PR que subir, registre uma posição atual do sistema
para podermos resgatar se houver a necessidade". Cada linha abaixo é um PR mesclado no `main`
(= produção na Railway), com o **estado do sistema logo antes dele** e o SHA da mescla.

O ponto de restauração é o **SHA da coluna "Estado antes"**: um commit do `main`, que fica no
histórico para sempre (o `main` não é reescrito). A linha de um PR entra no próprio PR, com o
"antes" já conhecido; o SHA da mescla é completado no PR seguinte.

Etiquetas git (`restauracao/pr-NNN`) seriam o atalho natural, mas o proxy das sessões do Claude
recusa enviar etiqueta ao GitHub (HTTP 403, só a branch de trabalho passa). Se quiser tê-las,
quem tem acesso ao repositório cria todas de uma vez com o script no fim deste documento.

## Como voltar

**Desfazer UM PR, mantendo os que vieram depois** (o caminho normal):

```bash
git fetch origin main && git checkout -B volta-pr-NNN origin/main
git revert -m 1 <SHA da mescla do PR NNN>     # cria um commit que desfaz só aquele PR
# rodar as suítes, abrir PR "Reverte #NNN", mesclar
```

Foi assim que o #153 e o #154 foram desfeitos no #155.

**Voltar o sistema inteiro para um ponto** (descarta tudo o que veio depois — só em emergência):
abrir na Railway › Deployments o deploy daquele ponto e usar *Redeploy* (volta a tela em
minutos, sem mexer no git); depois, com calma, reverter no git os PRs posteriores, do mais
novo para o mais velho, para o `main` voltar a bater com o que está no ar.

**O banco não volta junto com o código.** O `database/migrate.php` só ACRESCENTA (cria tabela,
coluna, carga) e nunca apaga lançamento — regra do cliente de 2026-11-26, provada por
`testes/lancamentos_intocados.php`. Voltar o código deixa as tabelas novas sem uso, o que é
inofensivo. A coluna "Banco" diz quais PRs mexeram em `database/`: ao reverter um deles,
confira se a carga/estrutura precisa de algum ajuste manual (nenhum dado lançado é apagado).

## Tabela

| PR | Data | O que entrou | Estado antes (restaurar para) | Mescla (reverter) | Banco (`database/`) |
|---|---|---|---|---|---|
| #169 | 2026-11-27 | Padrão visual das tabelas da Projeção (cores da marca, fonte grande, zebra, borda no hover) e "Comparar a proposta com" à direita | `62dcf36f5b` | (no PR seguinte) | — |
| #168 | 2026-11-27 | "Diretrizes de pessoal" (simulador + pessoas e benefícios num cartão, custo por diretriz) e cabeçalhos com fundo no Corporativo | `4de0a11697` | `62dcf36f5b` | — |
| #167 | 2026-11-27 | Cartão do desafio: "Comparar a proposta com" (desafio, real + realista, real + plan.), variação em %, rateios em itálico | `fbb0bd2c77` | `4de0a11697` | — |
| #166 | 2026-11-27 | Quadro do ano e cartões do desafio = reflexo da DRE (receita bruta sem PIS/COFINS, colunas de 2026, rateios abertos, Corporativo em blocos, fonte grande) | `e6dc74bdc7` | `fbb0bd2c77` | — |
| #165 | 2026-11-27 | Pontos de restauração sem etiqueta (o proxy recusa) e memória da sessão — só documentação | `87d26713f7` | `e6dc74bdc7` | — |
| #164 | 2026-11-27 | Quadro do ano: Resultado do negócio (14) no lugar do após impostos (11); registro dos pontos de restauração | `7395b88fca` | `87d26713f7` | — |
| #163 | 2026-11-26 | Desafio no modo apresentação, somado no Corporativo e com risquinho de efeito | `d8d5b97a6b` | `7395b88fca` | — |
| #162 | 2026-11-26 | Simulador: impacto também em quem digitou o pessoal, e o detalhe de pessoal | `541476513f` | `d8d5b97a6b` | — |
| #161 | 2026-11-26 | Simulador de diretrizes, PPR fixo e diretriz editável pelo administrador | `9615cfaea0` | `541476513f` | schema.sql |
| #160 | 2026-11-26 | Quadro do ano: "Comparar com: Desafio 2027", com a Proposta e a diferença | `b21af45118` | `9615cfaea0` | — |
| #159 | 2026-11-26 | Comparar com a Proposta 2027: só ADMIN, e só sem o modo apresentação | `d12796d524` | `b21af45118` | — |
| #158 | 2026-11-26 | Quadro do ano: comparar 2026 com a Proposta 2027, em todos os indicadores | `6b5185b632` | `d12796d524` | — |
| #157 | 2026-11-26 | Modo apresentação também esconde a coluna Desafio da DRE | `3024bca8a1` | `6b5185b632` | — |
| #156 | 2026-11-26 | Agrupamento de negócios para todo perfil com dois ou mais negócios | `c3eab884d8` | `3024bca8a1` | — |
| #155 | 2026-11-26 | Reverte #153 e #154; regra e prova: atualização não muda número lançado | `3c6300af1a` | `c3eab884d8` | — |
| #154 | 2026-11-26 | CLAUDE.md: perguntas do modo apresentação e da Cascata respondidas | `819efa390d` | `3c6300af1a` | — |
| #153 | 2026-11-26 | DRE: Ctrl + clique marca linhas para chamar a atenção | `2837bad977` | `819efa390d` | — |
| #152 | 2026-11-26 | Plano de ação expansível e Diagnóstico em modo apresentação | `ffd2dcccde` | `2837bad977` | schema.sql |
| #151 | 2026-11-26 | Matriz de Execução: a escolha em primeiro plano | `aece72656c` | `ffd2dcccde` | — |
| #150 | 2026-11-26 | DRE compacta e zebrada: cabe na tela de baixa resolução | `fde7d152d8` | `aece72656c` | — |
| #149 | 2026-11-25 | DRE: Margem Bruta, Desp./ROL e Margem Líquida (%) com a Proposta | `f9134e4dd5` | `fde7d152d8` | — |
| #148 | 2026-11-25 | Rateio corporativo negócio de 2027 com a planilha de 25/11 (−R$ 37,68 mi) | `ab0bbb0b73` | `f9134e4dd5` | rateio_negocios_2027.php |
| #147 | 2026-11-25 | Rateio corporativo administrativo de 2027 com a planilha pronta (−R$ 37,01 mi) | `5c69a80d85` | `ab0bbb0b73` | rateio_adm_2027.php |
| #146 | 2026-11-25 | Depreciação (8.10) editável em todos os negócios | `e3c2958c8f` | `5c69a80d85` | — |
| #145 | 2026-11-25 | Desafio da administração conta a conta, na coluna Desafio da DRE (só ADMIN) | `49cd462632` | `e3c2958c8f` | schema.sql |
| #144 | 2026-11-25 | Pessoal 2027: base sem a 3419, a 3398 e a 3339, projetadas para o ano | `fba6362931` | `49cd462632` | — |
| #143 | 2026-11-25 | Desafio: a coluna Proposta acompanha a DRE em tempo real, faturamento sem a CBS | `1cd6923b81` | `fba6362931` | — |
| #142 | 2026-11-25 | Projeção: atalho "Digitar no total do negócio" no aviso do modo por filial | `51070ef5aa` | `1cd6923b81` | — |
| #141 | 2026-11-25 | Rateio corporativo administrativo de 2027 com a planilha nova (−R$ 37,7 mi) | `f16eaeb63f` | `51070ef5aa` | rateio_adm_2027.php |
| #140 | 2026-11-25 | DRE: aviso de líquido negativo também no Corporativo e moldura verde dos cartões | `6ad916516a` | `f16eaeb63f` | — |
| #139 | 2026-11-25 | Cabeçalho da DRE grudado também no celular | `4ea9d7b3ce` | `6ad916516a` | — |
| #138 | 2026-11-25 | Resultado negativo destacado dentro da DRE; cabeçalho verde-claro / vermelho-claro | `7832743441` | `4ea9d7b3ce` | — |
| #137 | 2026-11-25 | Oportunidades em jan–corte × ano anterior, % de margem bruta no desafio, topos fixos e pre | `381f88b33a` | `7832743441` | — |
| #136 | 2026-11-24 | %; campos do desafio extra em amarelo/Quadro do ano com seletor de linha e R$ / %; campos  | `94c21dd271` | `381f88b33a` | — |
| #135 | 2026-11-24 | Rateio Matriz/Central zerado no planejado, recarga de outubro do Qlik e planilhas dos rate | `0a326a0def` | `94c21dd271` | dre_2026_fechamento_10_r2.php, dre_filiais_acerto_2026_10.php, migrate.php |
| #134 | 2026-11-24 | DRE em três níveis, antes × depois da reforma, desafio extra dos administradores e notas m | `ca9e8e7d85` | `0a326a0def` | schema.sql |
| #133 | 2026-11-24 | Rateios de 2027 travados (16.1, 14.3, 14.2), seletor de negócios com caixas e depreciação  | `925732b08e` | `ca9e8e7d85` | rateio_adm_2027.php, rateio_financeiro_2027.php, rateio_negocios_2027.php |
| #132 | 2026-11-23 | Corporativo: agrupar negócios (só leitura); totais nos questionamentos e nos juros | `83be304b2b` | `925732b08e` | — |
| #131 | 2026-11-23 | Oportunidades: Considerar/Desconsiderar em todas as linhas, sem o selo, com os períodos | `ee52ddb01d` | `83be304b2b` | — |
| #130 | 2026-11-23 | Desafio: despesas operacionais acompanham a receita (só para cima) | `7edfcde066` | `ee52ddb01d` | desafio_2027.php |
| #129 | 2026-11-23 | Depreciação 2027 regravada sem o grupo contábil 1272 dos bens | `a24f839ff9` | `7edfcde066` | depreciacao_2027.php |
| #128 | 2026-11-23 | Depreciação 2027 carregada na 8.10 (conta 3128), por negócio, filial e mês, sem edição | `8d85b02358` | `a24f839ff9` | depreciacao_2027.php, migrate.php, schema.sql |
| #127 | 2026-11-23 | Pessoal 2027: a conta reduzida 3227 (acerto do PPR) sai da base, no negócio e nas filiais | `68008ebd08` | `8d85b02358` | — |
| #126 | 2026-11-23 | CLAUDE.md: resumo do que vale hoje na Projeção 2027 | `364214872f` | `68008ebd08` | — |
| #125 | 2026-11-23 | Projeção volta a jan–out realizado + nov–dez planejado; Leite responde às premissas | `08f8157d75` | `364214872f` | desafio_2027.php |
| #124 | 2026-11-23 | Projeção: base de 12 meses (nov/25–out/26) e desafio 2027 calculado com drivers | `13f022021f` | `08f8157d75` | desafio_2027.php, dre_2025_11_12.php, migrate.php |
| #123 | 2026-11-23 | Projeção: confirma o sinal invertido; Postos de leite a R$ 0,055/L | `0ac1f686d1` | `13f022021f` | — |
| #122 | 2026-11-23 | Oportunidades em 12 meses e avaliação Considerar/Desconsiderar dos itens | `ae990535ea` | `0ac1f686d1` | dre_eventos_2025_11_12.php, dre_nivel3_12m_2026_10.php, migrate.php … |
| #121 | 2026-11-23 | PPR com teto da diretriz e questionamentos 2025 × 2026 nas oportunidades | `ebd481dac2` | `ae990535ea` | dre_nivel3_2025_10.php, migrate.php |
| #120 | 2026-11-23 | Número de salários do PPR vira campo, como os da diretriz | `2e38b96292` | `ebd481dac2` | — |
| #119 | 2026-11-22 | Despesas com Pessoal: lançável com mínimo e ⚠, quadro por filial e PPR à vista | `1eb1f4e37b` | `2e38b96292` | migrate.php, pessoas_2026_11_23.php, schema.sql |
| #118 | 2026-11-22 | Desafio dos Postos de resfriamento pela cota Aurora do Leite × R$ 0,06/L | `4bbd17fafb` | `1eb1f4e37b` | — |
| #117 | 2026-11-21 | Piso do faturamento vale também para o que já estava lançado, em todo negócio e filial | `f627b087ca` | `4bbd17fafb` | — |
| #116 | 2026-11-20 | DRE com 3 níveis, como no Qlik: conta reduzida e oportunidades na língua do Qlik | `d6e0070045` | `f627b087ca` | dre_nivel3_2026_10.php, migrate.php, schema.sql |
| #115 | 2026-11-20 | Pessoal (8.5) calculado, premissas e pessoas num bloco só, piso do faturamento trava | `3b7ce1392b` | `d6e0070045` | — |
| #114 | 2026-11-20 | Cenário: o expansor passa a se chamar "Anexos" e o bloco vazio fica limpo | `a8dcfee0db` | `3b7ce1392b` | — |
| #113 | 2026-11-20 | Anexos: foto ampliada com Fechar de verdade e edição do registro existente | `1b399d6590` | `a8dcfee0db` | — |
| #112 | 2026-11-20 | Cenário: "+" na linha do expansor e foto de borda a borda | `3bb84ea729` | `1b399d6590` | — |
| #111 | 2026-11-20 | Análise de Cenário: anexos e apontamentos em cada item, com a foto inteira no expansor | `08a1bc13bc` | `3bb84ea729` | migrate.php, schema.sql |
| #110 | 2026-11-19 | Pontos de milhar ao digitar a proposta; atingimento sempre realizado ÷ meta e linha % que  | `c5582497c2` | `08a1bc13bc` | — |
| #109 | 2026-11-19 | UTM: plano operacional 2026 e projeto de automação do parque fabril no plano de ação | `4378170485` | `c5582497c2` | migrate.php, plano_acao_utm_2026.php, plano_operacional_racoes_2026.php |
| #108 | 2026-11-19 | Piso do faturamento na proposta: abaixo de IPCA + PIB + crescimento, inconsistência em ver | `67ff87c2f6` | `4378170485` | — |
| #107 | 2026-11-19 | Rações: cascata igual à do Leite e plano operacional 2026 no plano de ação | `b288b7cee5` | `67ff87c2f6` | migrate.php, plano_operacional_racoes_2026.php |
| #106 | 2026-11-19 | Selo "diretriz" no IPCA e no PIB quando o valor é igual ao da diretriz | `a5a077def9` | `b288b7cee5` | migrate.php |
| #105 | 2026-11-19 | Premissas mexem na DRE na hora; crescimento do faturamento aceita negativo | `35043f07d8` | `a5a077def9` | — |
| #104 | 2026-11-19 | Três casas decimais em todos os indicadores, não só nos em % | `d98c97e9bf` | `35043f07d8` | — |
| #103 | 2026-11-19 | Cenário realista da Pecuária, impressão da DRE, destaque das aberturas e três casas em % | `c455eb1e6f` | `d98c97e9bf` | cenario_pecuaria_2027.php, migrate.php, schema.sql |
| #102 | 2026-11-19 | Perdas e acerto de estoque contra a média entre as filiais | `f8b77fbf46` | `c455eb1e6f` | estoque_oportunidade_2026_10.php |
| #101 | 2026-11-18 | Filiais de todos os negócios no corte 10 e totais nas aberturas de lançamentos | `30071c52c7` | `f8b77fbf46` | dre_filiais_agropecuarias_2026_09.php, dre_filiais_agropecuarias_2026_10.php, dre_filiais_cereais_2026_10.php … |
| #100 | 2026-11-18 | Lançamentos por trás do quadro de oportunidades e juros recebidos | `834873cc41` | `30071c52c7` | lancamentos_2026_10.php, migrate.php, schema.sql |
| #99 | 2026-11-18 | Oportunidades do estoque abaixo da DRE e quadro único de oportunidades | `b5e7005443` | `834873cc41` | estoque_oportunidade_2026_10.php, migrate.php, nao_recorrentes_mensal_2026_10.php … |
| #98 | 2026-11-18 | Projeção no formato de 2027 (CBS acima da RB) e eventos pontuais na filial | `b07f0aab35` | `b5e7005443` | eventos_filiais_mensal_2026_10.php, migrate.php, schema.sql |
| #97 | 2026-11-18 | CBS 2027 pelo mesmo valor do PIS/COFINS: sai a estimativa líquida de créditos | `3f5b5112ac` | `b07f0aab35` | — |
| #96 | 2026-11-18 | CBS 2027: a Copérdia optou pelo regime específico das cooperativas | `653c566fb3` | `3f5b5112ac` | — |
| #95 | 2026-11-18 | Projeção 2027: DRE nova, CBS da LC 214, como é × como fica e IPCA/PIB por negócio | `92d98a7a1c` | `653c566fb3` | dre_2026_fechamento_10_nova.php, migrate.php |
| #94 | 2026-11-18 | Projeção: desafio para o negócio com a escada da DRE, PIS/COFINS e oportunidades | `46a486a87a` | `92d98a7a1c` | migrate.php, nao_recorrentes_2026_10.php, schema.sql … |
| #93 | 2026-11-18 | Projeção: diretrizes do planejamento 2027, PPR e trava de meta negativa | `b0a3955e30` | `46a486a87a` | desafio_2027.php, folha_2026_10.php, migrate.php … |
| #92 | 2026-11-18 | Projeção: desafio de 2027, cartões em expansores e um negócio só | `79daf97a7a` | `b0a3955e30` | desafio_2027.php, migrate.php, schema.sql |
| #91 | 2026-11-18 | Projeção: visão realista do ano (só leitura) e A.V. da proposta | `13eccaa6de` | `79daf97a7a` | — |
| #90 | 2026-11-18 | Projeção: botão para recolher e mostrar as subcontas | `235b2bc708` | `13eccaa6de` | — |
| #89 | 2026-11-17 | Percentual de crescimento só aceita valor positivo (0% a 100%) | `12289a30c6` | `235b2bc708` | — |
| #88 | 2026-11-17 | As premissas passam a mover a proposta; vale e abono vão para o painel | `9dc3313d7b` | `12289a30c6` | schema.sql |
| #87 | 2026-11-17 | Pessoas e benefícios por negócio: quadro da DRE, alimentação e abono | `812665be77` | `9dc3313d7b` | migrate.php, pessoas_2026_11.php, schema.sql |
| #86 | 2026-11-17 | Tecla de sinal (−/+) nos campos numéricos, para lançar negativo no celular | `a05e3009c6` | `812665be77` | — |
| #85 | 2026-11-17 | Premissa nova no painel: crescimento das despesas com pessoas | `27163d5d1a` | `a05e3009c6` | schema.sql |
| #84 | 2026-11-17 | Corporativo na Projeção: a soma dos negócios, só para leitura | `9131141396` | `27163d5d1a` | — |
| #83 | 2026-11-17 | DRE de 2026 passa a outubro: realizado jan–out, projetado nov–dez | `8c2dfec103` | `9131141396` | dre_2026_fechamento_10.php, dre_filiais_pecuaria_2026_09.php, dre_filiais_pecuaria_2026_10.php … |
| #82 | 2026-11-17 | IPCA, PIB e crescimento desejado por negócio e por ano | `dcdbe66871` | `8c2dfec103` | migrate.php, schema.sql |
| #81 | 2026-11-17 | IPCA, PIB e meta de crescimento passam a ser do negócio e valem para as filiais | `25f55bdf2f` | `dcdbe66871` | schema.sql |
| #80 | 2026-11-14 | Sai o interruptor "O que considerar" das quatro telas de diagnóstico | `0eefcff3cf` | `25f55bdf2f` | — |
| #79 | 2026-11-13 | Metas de 2027 da Agropecuária: 180 valores dos 15 indicadores | `cd4727c52d` | `0eefcff3cf` | conteudo_metas_agropecuaria_2027.php, migrate.php |
| #78 | 2026-11-12 | SWOT da Pecuária: entram os 21 itens escritos pelos gerentes | `58957c116e` | `cd4727c52d` | conteudo_swot_pecuaria_oficina.php, migrate.php |
| #77 | 2026-11-09 | "Não se repete" sai: a ação de uma data é a lista com uma linha | `b60a4be524` | `58957c116e` | migrate.php, schema.sql |
| #76 | 2026-11-09 | Ação em datas específicas: abril e agosto, e em mais nenhuma data | `1576953a79` | `b60a4be524` | migrate.php, schema.sql |
| #75 | 2026-11-09 | Degrau por coluna na tabela, e o realizado para de mexer na meta do ano | `f2893345bd` | `1576953a79` | — |
| #74 | 2026-11-09 | Atingimento do mês na tabela de Metas | `c0b3218d43` | `f2893345bd` | — |
| #73 | 2026-11-09 | "Mostrar até" passa a valer na Tabela | `7f6b661c5d` | `c0b3218d43` | — |
| #72 | 2026-11-09 | Arrastar para ordenar os indicadores em Metas | `d55dab71a2` | `7f6b661c5d` | migrate.php, schema.sql |
| #71 | 2026-11-06 | Coluna Reajuste: o percentual ganha célula própria | `faa26b0f97` | `d55dab71a2` | — |
| #70 | 2026-11-05 | Carrega Supermercados e Agropecuárias por filial | `318c2e2135` | `faa26b0f97` | dre_filiais_agropecuarias_2026_09.php, dre_filiais_supermercados_2026_09.php, migrate.php |
| #69 | 2026-11-05 | Projeção no Painel e no Dossiê: a fatia 4 fecha o tema 13 | `5aeaaa0977` | `318c2e2135` | — |
| #68 | 2026-11-05 | Projeção por filial: os dois modos de planejar, por negócio (fatia 3) | `25a3ffd6fe` | `5aeaaa0977` | dre_filiais_pecuaria_2026_09.php, migrate.php, schema.sql |
| #67 | 2026-11-05 | Painel de indicadores da Projeção: IPCA e PIB lançados, o resto lido | `174baaf99d` | `25a3ffd6fe` | schema.sql |
| #66 | 2026-11-05 | Projeção de Resultados: a proposta do ano de planejamento (fatia 2) | `28b0073c01` | `174baaf99d` | schema.sql |
| #65 | 2026-11-05 | Projeção de Resultados: a DRE do ano na tela, nos onze negócios | `01a0509b11` | `28b0073c01` | dre_2026_fechamento_09.php, migrate.php, schema.sql |
| #64 | 2026-11-05 | Plano da Projeção de Resultados no backlog (tema 13) | `353004b97e` | `01a0509b11` | — |
| #63 | 2026-11-05 | Negócio 3 (FRUTICULTURA) sai do cadastro | `19b8cda769` | `353004b97e` | migrate.php, seeds.sql |
| #62 | 2026-11-05 | Arrastar para ordenar também no PESTEL, no Porter e na SWOT | `e045f800a4` | `19b8cda769` | migrate.php, schema.sql |
| #61 | 2026-11-04 | Arrastar para ordenar os cartões da Análise de Cenário | `1490c930e6` | `e045f800a4` | — |
| #60 | 2026-11-04 | Diagnóstico da Pecuária: cenário, PESTEL, Porter e SWOT sobre a DRE | `49e5b82157` | `1490c930e6` | conteudo_cenario_pecuaria_2027.php, conteudo_pestel_pecuaria_2027.php, conteudo_porter_pecuaria_2027.php … |
| #59 | 2026-11-03 | Menu sem "Impacto por Negócio" e formulário que pergunta antes de sair | `adb479c567` | `49e5b82157` | — |
| #58 | 2026-11-03 | Avisos por e-mail: o gestor do negócio entra no disparo | `6b14291032` | `adb479c567` | — |
| #57 | 2026-11-03 | Tabela do plano, acumulado até o mês e repetição com data de início | `81b1cebf16` | `6b14291032` | — |
| #56 | 2026-11-02 | Tabela do plano: contraste dos botões e, no celular, a situação sobre o "o quê" | `374e2487f8` | `81b1cebf16` | — |
| #55 | 2026-11-02 | Rodapé do menu diz qual versão está no ar | `e18202f226` | `374e2487f8` | — |
| #54 | 2026-11-02 | Bateria: prova que desvincular não apaga o que foi lançado | `6c99f4cc09` | `e18202f226` | — |
| #53 | 2026-11-02 | Plano de ação e Metas: seis pedidos do cliente, e o menu reagrupado | `021f493a44` | `6c99f4cc09` | — |
| #52 | 2026-10-28 | Plano de ação: filtro por projeto (um ou mais) e a frente editável pela tabela | `b88c534a66` | `021f493a44` | — |
| #51 | 2026-10-28 | Tabela do plano de ação: o total começa onde a coluna começa | `20703b9d8e` | `b88c534a66` | — |
| #50 | 2026-10-28 | Tabela do plano de ação: o total da frente e do projeto centrado na coluna | `3c150ef2a2` | `20703b9d8e` | — |
| #49 | 2026-10-28 | Tabela do plano de ação: o percentual dos três níveis na mesma coluna | `3756a8e2c7` | `3c150ef2a2` | — |
| #48 | 2026-10-28 | Plano de ação — arraste no toque, e a tabela com hierarquia, totais e progresso ajustável | `6330dcc7df` | `3756a8e2c7` | — |
| #47 | 2026-10-28 | A mesma informação, três leituras, num seletor no cabeçalho com a escolha guardada por pes | `dad256ad39` | `6330dcc7df` | — |
| #46 | 2026-10-26 | Os botões "+ Iniciativa" e "+ Ação" passam para a linha do título do projeto, à direita, e | `623537bcfb` | `dad256ad39` | — |
| #45 | 2026-10-26 | Metas — o par meta × real passa a ser o do ano em execução | `2cec165397` | `623537bcfb` | — |
| #44 | 2026-10-26 | Cascata — a escolha passa a ser da FRENTE, não do projeto | `2224b6510d` | `2cec165397` | migrate.php, schema.sql |
| #43 | 2026-10-26 | Metas — o "⟳ Revisar" não alcança mais o ano em execução | `1faa04b2a6` | `2224b6510d` | — |
| #42 | 2026-10-26 | Metas — 2027 como ano em execução, e o restauro da bateria que o apagava | `db166070ff` | `1faa04b2a6` | migrate.php |
| #41 | 2026-10-26 | Metas — reclassificar uma vez os percentuais que ficaram somando | `873cb53e4c` | `db166070ff` | migrate.php |
| #40 | 2026-10-26 | Metas — a agregação do ano à vista, e o aviso de quem soma o que não se soma | `dbc157511b` | `873cb53e4c` | — |
| #39 | 2026-10-26 | Metas — um caminho de lançamento por ano | `11f83b9ae4` | `dbc157511b` | — |
| #38 | 2026-10-26 | Metas — meta e realizado na mesma tela, e o ano em execução mês a mês | `de49688f1c` | `11f83b9ae4` | migrate.php, schema.sql |
| #37 | 2026-10-14 | Navegação — a tela e o negócio sobrevivem à recarga | `d3817cc2fb` | `de49688f1c` | — |
| #36 | 2026-10-07 | Diagnóstico: interruptor "Texto completo" que abre todos os cartões e fica marcado | `a171331a0b` | `d3817cc2fb` | — |
| #35 | 2026-10-01 | Modal: sai o aviso "mais campos abaixo ↓" | `ce02cee6f9` | `a171331a0b` | — |
| #34 | 2026-10-01 | Cascata: o formulário da célula perde a lista de fatores da SWOT | `b6c37624df` | `ce02cee6f9` | — |
| #33 | 2026-10-01 | Cascata: o rótulo da célula passa a dizer a linha, não a tabela | `88f418fe54` | `b6c37624df` | — |
| #32 | 2026-09-29 | Comentários: o botão fechado conta o que há atrás dele | `208c504157` | `88f418fe54` | — |
| #31 | 2026-09-23 | Formulário: a janela também muda de tamanho, puxada pelas bordas | `d528052f71` | `208c504157` | — |
| #30 | 2026-09-23 | Formulário: no computador a janela alarga, sai do meio e clareia o fundo | `515866d554` | `d528052f71` | — |
| #29 | 2026-09-22 | Diagnóstico: interruptor "O que considerar" que abre tudo e fica marcado | `73eee7beeb` | `515866d554` | — |
| #28 | 2026-09-21 | Diagnóstico de 2027: a carga passa a rodar no deploy | `ebd74d9e1a` | `73eee7beeb` | conteudo_cascata_h1_2027.php, conteudo_cenario_2027.php, conteudo_pestel_2027.php … |
| #26 | 2026-09-21 | Carga do diagnóstico de 2027 a partir do dossiê, e duas CLIs que não subiam | `47eacebb1a` | `ebd74d9e1a` | conteudo_cascata_h1_2027.php, conteudo_cenario_2027.php, conteudo_pestel_2027.php … |
| #25 | 2026-09-18 | Bancada: o campo de texto cresce até a ideia inteira caber | `b324e72b34` | `47eacebb1a` | — |
| #24 | 2026-09-04 | Questionário prévio: o ✎ corrige o texto da pergunta | `b8b4bc252b` | `b324e72b34` | — |
| #23 | 2026-09-04 | Fila da Coleta: cada resposta diz de quem é | `1a9564a5fd` | `b8b4bc252b` | — |
| #22 | 2026-09-04 | Questionário prévio: excluir pergunta, com o destino das respostas | `68ac5f6a49` | `1a9564a5fd` | — |
| #21 | 2026-09-04 | CLAUDE.md: receita local, linha de base das baterias e o deploy do PR #20 | `b6fea6731e` | `68ac5f6a49` | — |
| #20 | 2026-09-04 | Revisão pelo time de agentes: 40 achados corrigidos em segurança, corretude, infra, front- | `e2d7cb3b9b` | `b6fea6731e` | migrate.php, schema.sql |
| #19 | 2026-09-03 | Questionário: estrelas depois de concluir, fila da Coleta em blocos por pergunta e filtro  | `e32deed8ac` | `e2d7cb3b9b` | — |
| #18 | 2026-09-03 | Matriz sem o card "Classificando"; a bancada abre pela pergunta que a ideia respondeu | `643f2c120c` | `e32deed8ac` | — |
| #17 | 2026-09-03 | Questionário prévio na Tempestade de ideias: perguntas em ordem, respondidas pelo celular  | `a3c3fa151a` | `643f2c120c` | migrate.php, schema.sql |
| #16 | 2026-09-03 | Excluir ação, frente ou projeto pergunta o destino das origens; a fila ganha o × de tirar  | `372cb4a19a` | `a3c3fa151a` | — |
| #15 | 2026-09-03 | Painel da análise inteira: grade única em ordem de chegada, com a etiqueta do quadrante | `5f1835a6a5` | `372cb4a19a` | — |
| #14 | 2026-09-03 | Celular da análise inteira sem o bloco do topo | `9487544ea3` | `5f1835a6a5` | — |
| #13 | 2026-09-03 | O 🎤 da etapa inteira: o celular escolhe a categoria e lê a orientação | `7146ecb38e` | `9487544ea3` | migrate.php, schema.sql |
| #12 | 2026-09-03 | Painel da sala fica à vista enquanto a análise rola | `89d2db23d1` | `7146ecb38e` | — |
| #11 | 2026-09-02 | Ano da análise abre em 2027, o primeiro ano planejado do ciclo | `7df8fb1d1c` | `89d2db23d1` | — |
| #10 | 2026-09-02 | Análise de Cenário: fotografia de setembro/2026 revisada no lugar, com oito assuntos novos | `2ec9a3d8b7` | `7df8fb1d1c` | conteudo_cenario_macro.php |
| #9 | 2026-09-02 | Comando para zerar o plano de ação: conta, faz backup, confirma e apaga numa transação | `eefc3b9e24` | `2ec9a3d8b7` | — |
| #8 | 2026-09-02 | Menu lateral: um tópico aberto por vez | `93a31b35ce` | `eefc3b9e24` | — |
| #7 | 2026-09-02 | Menu lateral com tópicos recolhíveis, no padrão do CRM Agro | `7a50a29e0f` | `93a31b35ce` | — |
| #6 | 2026-09-02 | Coleta: "Tratar" puxa qualquer ideia da lista para a fila, fora da ordem | `132a1e4f54` | `7a50a29e0f` | — |
| #5 | 2026-09-02 | Excluir um registro nascido da sala apaga a voz do quiz, em vez de devolvê-la | `69eb128fbe` | `132a1e4f54` | — |
| #4 | 2026-09-02 | Claude/acesso planejamento controladoria 9pe28s | `9916d7e7d4` | `69eb128fbe` | migrate.php, schema.sql |

## Criar as etiquetas (opcional, fora da sessão do Claude)

```bash
git fetch origin main
git log origin/main --merges --first-parent --format='%H %P %s' |
  sed -nE 's/^([0-9a-f]+) ([0-9a-f]+) [0-9a-f]+ Merge pull request #([0-9]+).*/\3 \2/p' |
  while read pr antes; do git tag -f "restauracao/pr-$pr" "$antes"; done
git push origin 'refs/tags/restauracao/*'
```

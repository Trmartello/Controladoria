# Spec 03 — Perfilagem dos centros de custo

## Objetivo
Atribuir a cada CC um **perfil funcional**, derivado dos dados e curado por humano.

## Método
1. Vetorizar cada CC: participação % do valor por `grupo_conta` (36 meses).
2. Normalizar e rodar clusterização hierárquica (Ward, distância euclidiana).
3. Testar k de 4 a 12; escolher por silhueta + interpretabilidade contábil.
4. Nomear os clusters conforme o vocabulário do CLAUDE.md
   (Pessoal, Produtivo Industrial, Logístico, Comercial/Loja, Infraestrutura, Lavoura).
5. Exportar `outputs/perfilagem_cc.xlsx` para curadoria manual da Controladoria.

## Saída obrigatória adicional
Lista de **CCs outliers do próprio cluster** (distância ao centroide acima do
percentil 95). Esses já nascem marcados como suspeitos de má estruturação.

## Persistência
Tabela `dim_centro_custo_perfil` com `centro_custo`, `perfil`, `origem`
(AUTOMATICA/CURADA), `vigencia_inicio`, `vigencia_fim`, `usuario`, `justificativa`.

## Critério de conclusão
Planilha curada e reimportada. Perfil definido para 100% dos CCs ativos.

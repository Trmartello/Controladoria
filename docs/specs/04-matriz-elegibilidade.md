# Spec 04 — Matriz de elegibilidade (Camada 1)

## Objetivo
Regra explícita, determinística e auditável: dada uma conta e um perfil de CC,
o lançamento é PERMITIDO, exige JUSTIFICAR, ou é BLOQUEADO.

## Modelo de dados — `matriz_elegibilidade`
| Coluna | Tipo |
|---|---|
| `id` | bigint PK |
| `conta_contabil` | varchar |
| `perfil_cc` | varchar |
| `veredicto` | enum('PERMITIDO','JUSTIFICAR','BLOQUEADO') |
| `justificativa_regra` | text |
| `vigencia_inicio` | date |
| `vigencia_fim` | date NULL |
| `usuario_aprovador` | varchar |
| `criado_em` | datetime |

Chave lógica: (`conta_contabil`, `perfil_cc`, vigência ativa) — única.

## Carga inicial (proposta automática, aprovação manual)
- Par com ocorrência em ≥ 24 dos 36 meses → propor **PERMITIDO**
- Par com 3 a 23 ocorrências → propor **JUSTIFICAR**
- Par com 1 a 2 ocorrências ou inexistente → propor **BLOQUEADO**

Estes limiares vivem em `config/parametros.yaml`. A proposta é gerada em planilha;
**nada entra na tabela sem aprovação nominal da Controladoria.**

## Cobertura mínima para ir para produção
95% do **valor** dos lançamentos dos últimos 12 meses deve estar coberto por regra
explícita. Cobertura por *quantidade* de lançamentos é métrica secundária.

## Critério de conclusão
Matriz carregada, versionada, com relatório de cobertura anexo.

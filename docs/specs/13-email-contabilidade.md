# Spec 13 — Minuta de e-mail para a contabilidade

## Objetivo
Gerar automaticamente a **minuta** de e-mail com os ajustes de centro de custo a
solicitar à contabilidade, contendo **somente os itens aprovados (PROCEDE) na
triagem do especialista** (Spec 09).

## Regras
- **Nunca envio automático.** O sistema gera a minuta; um humano revisa e envia.
- Nenhum item entra na minuta sem decisão PROCEDE registrada na trilha de auditoria.
- Motivo de cada item em **linguagem contábil**, uma frase, sem jargão estatístico
  (princípio 2 do CLAUDE.md). Ex.: "Conta de frete lançada em centro de custo de
  perfil Pessoal Administrativo."
- Nenhum dado pessoal de associado (CPF, nome) na minuta.

## Conteúdo da minuta (template em `templates/email_contabilidade.md`)
1. Assunto: `[SVL] Ajustes de centro de custo — competência AAAA-MM`
2. Resumo do período: total de lançamentos avaliados, itens em ajuste, valor total envolvido.
3. Tabela de itens, um por linha:
   | Documento | Data | Conta | CC atual | CC indicado | Valor | Motivo |
4. Orientação: prazo sugerido de ajuste e ponto de contato na Controladoria.
5. Nota de rodapé: gerado pelo SVL em shadow mode, decisões triadas por
   `usuario_triagem` em `data_triagem`.

## Implementação
- Módulo `src/svl/email_contabilidade.py`: recebe a lista de exceções PROCEDE e
  produz a minuta em Markdown/HTML a partir do template.
- Saída em `outputs/email_contabilidade_AAAA-MM.md` (fora do git).
- Registro em `log_auditoria`: quando a minuta foi gerada, por quem, com quais itens.

## Critério de conclusão
Minuta gerada a partir de uma fila triada de teste, revisada e aprovada pela
Controladoria como pronta para envio sem edição manual do conteúdo dos itens.

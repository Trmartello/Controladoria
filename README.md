# SVL — Sistema de Validação de Lançamentos
Controladoria Estratégica · Copérdia

Valida se cada lançamento contábil é pertinente ao centro de custo em que foi
alocado, combinando regra determinística, estatística sobre o histórico e
sazonalidade, com trilha de auditoria completa.

## Como usar este repositório com o Claude Code
1. `CLAUDE.md` é lido automaticamente a cada sessão — contém as invariantes.
2. `docs/ROADMAP.md` é a lista de PRs na ordem de execução.
3. `docs/specs/NN-*.md` contém o detalhe de cada PR.

**Ciclo de trabalho:** uma sessão → um spec → um PR → `/clear`.

Comando de abertura sugerido:
> Leia `CLAUDE.md` e `docs/specs/01-extracao-base-historica.md`.
> Antes de escrever código, liste as premissas que você precisa confirmar comigo.

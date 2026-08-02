# Estado da implementação — registro factual (2026-08-02)

Snapshot verificado do repositório **neste exato momento**, gravado após
reconciliar o branch: o container de execução ficou preso a uma linha local
antiga (`05a28d4`, só "barras de progresso"); o remoto sempre teve a linha
completa. O local foi alinhado ao remoto sem perder nada (a linha antiga é
ancestral da atual).

## Metadados do estado

- **Branch:** `claude/git-repo-overview-d17774`
- **HEAD:** `baa3e66` — "Backlog: marca tema 2.1 (tempestade QR/PIN) como entregue"
- **Sincronia:** local `==` `origin/claude/git-repo-overview-d17774` (0 à frente / 0 atrás); é o head do **PR #1**.
- **`main` no remoto:** `6e3201a`.

## Alteração pendente em `projetos.js` — comparada e resolvida

O working tree trazia uma alteração **não commitada** em
`public/assets/js/secoes/projetos.js` (16+/7−), sobra do container antigo.
Comparada com `baa3e66`: era uma versão **anterior e incompleta** do "mostrar
mais" (colapsar detalhes) dos cartões de ação — recurso que **já está
implementado em `baa3e66`**, e de forma mais completa (os helpers
`botaoMais`/`detalhesAbertos` já se aplicam a projeto, ação e desdobramento).

**Conclusão: redundante, não traz nada novo.** Preservada por segurança em
`stash@{0}` e na tag `estado-2026-08-02-wip`; **não reaplicada** (reaplicar
sobre o arquivo já evoluído só criaria conflito).

## Tempestade de ideias (QR/PIN) — VERIFICADA E NO AR

Verificação claim-a-claim contra o código real (14 agentes: verificação +
refutação adversarial). Resultado: **entregue**, com duas ressalvas.

| Fatia | Conteúdo | Situação | Evidência-chave |
|---|---|---|---|
| 1 | `coleta_rodada` + PIN + rotas públicas | ✅ | `RodadaController.php`, `PublicoController.php`, `public/index.php` (`/entrar/{pin}`, `/api/publico/*`); `database/schema.sql` (`coleta_rodada`, `pin CHAR(6)`) |
| 2 | Tela de condução: nuvem por *polling* + bancada | ✅ | `coleta.js` (polling 3 s sem SSE; bancada; agrupamento por *pointer events*) |
| 3 | Matriz 2×2 + destino pelo quadrante | ⚠️ parcial | `coleta.js` `priorizar` e `encaminhar` são handlers **independentes** (Decisão C não literal) |
| 4 | QR (`qrcode.js` vendorado) + link | ✅ | `public/assets/vendor/qrcode.js`; `views/shell.php`; QR recolhido em `<details>` |
| 5 | Dividir ideia em várias | ✅ | `ColetaController::dividir` + rota `POST /api/coleta/{id}/dividir` |
| 6 | Votação dos participantes | ✅ | `coleta_voto`; `PublicoController::votar` (teto no `INSERT`); `RodadaController::votacao` |

### Ressalva registrada

1. **Fatia 3 parcial** — a bancada tem a matriz 2×2 e os botões de destino, mas
   são ações separadas: o quadrante grava só `impacto`/`esforco`; o
   encaminhamento é escolha manual. A **Decisão C** ("a matriz decide o
   encaminhamento") **não** foi implementada literalmente.

Na página do participante, a **edição da própria ideia** (antes pendente) **foi
entregue** — botão "editar" na lista "Suas ideias", editor inline e rota pública
`POST /api/publico/ideia/{id}`, restrita a ideia `NOVO` da rodada aberta, com a
autoria provada pelo token. Continua fora só o **ditado por voz** (`por_voz` já
era corte reconhecido no tema 2).

### Extras entregues além do escopo do backlog 2.1

Votação com teto; agrupamento manual arrastando fichas (`agrupado_em_id`); caixa
"tratar depois" (`adiado`); reclassificação não-destrutiva (`reabrir`); estado
`DIVIDIDO`; destino "Plano de ação" (`ACAO`) na triagem.

### Segurança

Revisão de segurança da rota pública sem autenticação **executada** — commit
`22e6f31` ("Correções das revisões da tempestade: 9 de segurança e 11 de
corretude"). Travas presentes: CSRF por lista explícita, teto de ideias/votos
dentro do `INSERT`, nome vindo do registro, PIN errado contado em
`coleta_tentativa`, `Content-Type` JSON obrigatório, sem sessão nas rotas
públicas.

## Pontos de restauração disponíveis (tags locais)

- `estado-2026-08-02` → `05a28d4` (estado local antigo; já contido em `baa3e66`).
- `estado-2026-08-02-wip` → inclui o `projetos.js` pendente.
- `stash@{0}` → o `projetos.js` pendente isolado.

> Tags e stash são **locais** (não empurrados). Servem só para reverter em caso
> de necessidade.

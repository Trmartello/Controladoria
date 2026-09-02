# Baterias de validação

Sete baterias, cada uma cobrindo uma camada diferente. Elas não substituem a
leitura do código — cobrem o que a leitura não pega: regressão silenciosa.

| Bateria | O que cobre | Como falha |
|---|---|---|
| `funcional.sh` | Os caminhos de **escrita** de cada módulo, pela própria API | Uma regra de negócio parou de valer, ou passou a valer onde não devia |
| `sistema.js` | As **18 seções** em 1500×700 e 390×844, mais duas sessões no preenchimento simultâneo, no cadeado de edição e na oficina de Cruzamentos (computador + celular) | Uma tela parou de pintar, estourou erro de console ou passou a rolar na horizontal — **nas duas larguras** |
| `participante.js` | A tela **pública** da tempestade no celular | A única superfície de escrita sem login quebrou, ou o polling voltou a fechar o teclado |
| `backup.sh` | O vaivém de `cli/backup.sh` — gerar, verificar, restaurar | O backup deixou de ser restaurável, o anexo binário parou de atravessar, ou arquivo pela metade voltou a passar por bom |
| `email.sh` | O envio por **API** de `App\Core\Email`, o relatório do disparo, e a assimetria botão×cron | O caminho da API parou de ser escolhido, a recusa do serviço deixou de chegar a quem clicou, a chave passou a vazar na mensagem de erro, ou o relatório do admin passou a sair (ou a não sair) na hora errada |
| `backup_remoto.sh` | A cópia fora do provedor, contra um B2 de mentira | O envio parou de subir o arquivo inteiro, o erro do serviço deixou de chegar, a chave vazou, ou a falta de configuração passou a derrubar o backup local |
| `limpar_plano_acao.sh` | O comando que **zera o plano de ação** (`cli/limpar_plano_acao.php`): semeia projeto, iniciativa, ações e o que aponta para elas, conta, apaga e confere | Passou a apagar o que não devia, a deixar ponteiro para registro morto, ou a apagar sem confirmação |

## Antes de rodar

As que precisam da aplicação batem numa instância **local** — nunca aponte para
produção: a `funcional.sh` cria e apaga registros. A `email.sh` e a
`backup_remoto.sh` não precisam de nada de pé (sobem o serviço de mentira elas
mesmas) e a `backup.sh` fala com o banco direto.

```bash
# 1. Banco (MariaDB local; o socket precisa de caminho curto)
mariadbd --user=root --datadir=<dir> --socket=/tmp/ccm.sock --port=33061 &

# 2. Esquema e semente
DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
  ADMIN_SENHA=trocar123 php database/migrate.php

# 3. Servidor — o argumento router (public/index.php) é OBRIGATÓRIO
#    SALA_AUSENTE_SEG encurta a janela de ausência da sala (padrão: 300 s). Sem
#    ela, a funcional pula as duas provas da reentrada pelo NOME em vez de
#    ficar cinco minutos parada esperando o "dono calado".
DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
  SALA_AUSENTE_SEG=6 php -S 127.0.0.1:8099 -t public public/index.php
```

## Rodando

```bash
./testes/rodar.sh                 # as sete, em sequência
./testes/funcional.sh             # só a funcional
SALA_AUSENTE_SEG=6 ./testes/funcional.sh   # inclui a reentrada pelo nome
node testes/sistema.js            # só a de sistema
node testes/participante.js 123456   # precisa do PIN de uma rodada ABERTA
./testes/backup.sh                # backup/restauração (cria e derruba bancos descartáveis)
./testes/email.sh                 # envio por API (não precisa de banco nem de servidor)
./testes/backup_remoto.sh         # cópia fora do provedor (idem)
```

A `backup.sh` é a única que **não** passa pela aplicação: ela fala com o banco
direto. O banco de trabalho é só lido; ela cria `<banco>_bkp1` e `<banco>_bkp2`
para o vaivém e os derruba no fim. Sem um usuário com `CREATE DATABASE` ela é
**pulada**, não reprovada — o usuário que o Railway cria não tem esse direito.

A `email.sh` é a única que não precisa de **nada** de pé — nem banco, nem
aplicação. Ela sobe um servidor local que responde como o serviço de e-mail
real (401 sem chave, 400 com remetente não verificado, 201 no sucesso) e
exercita o `Email` contra ele. Falar com o serviço de verdade exigiria
credencial no repositório, gastaria cota e ficaria vermelho quando ele saísse
do ar — três motivos para a bateria acabar ignorada.

Variáveis, se a instância não estiver no padrão:
`APP_URL` (padrão `http://127.0.0.1:8099`), `APP_EMAIL`, `APP_SENHA`.

Saída: `✓ N passaram` e, se houver, `✗ N FALHARAM` com o motivo. O código de
saída é 0 só quando tudo passa — dá para pendurar num hook ou num CI.

## Armadilhas do ambiente, todas já pagas

Estão resolvidas em `comum.js`, mas ficam registradas porque voltam a morder
quem escrever bateria nova:

- **O Chromium novo removeu o headless antigo.** `chromium.launch()` sem
  `executablePath` não sobe: é preciso apontar para o `headless_shell`. O
  número da build muda a cada atualização da imagem, então `chromiumExec()`
  resolve por glob em vez de fixar a versão.
- **A CSP bloqueia `page.waitForFunction`** — ele avalia string como JS e a
  aplicação não tem `unsafe-eval`. Use `esperar()`, que é um laço com
  `page.evaluate`.
- **O modal do Bootstrap deixa `.show` pendurada** sem o `transitionend`: sem
  `reducedMotion: 'reduce'` no contexto, "o modal fechou" dá falso-negativo.
- **Arraste precisa andar em passos** (`mouse.move` várias vezes): abaixo de
  8px o código trata o gesto como toque, não como arraste.
- **A Web Speech API não existe no headless.** Para o microfone aparecer,
  simule `window.webkitSpeechRecognition` **e** `window.SpeechRecognition` — o
  headless define o nativo (que não fala) e o código prefere ele.

## O que elas ainda NÃO cobrem

Dito de propósito, para ninguém confundir verde com completo:

- A condução ao vivo do quiz e da sala (dois navegadores em paralelo).
- O arraste da matriz de prioridade e o agrupamento por gesto.
- A exportação `.xls` e a folha de impressão do relatório.
- O envio de e-mail (`cli/notificar.php`) e a sincronização com o Qlik.

A concorrência **saiu desta lista**: dois navegadores, com duas contas
diferentes, provam o preenchimento simultâneo (`provasDuasTelas`) e o cadeado de
edição (`provasCadeado`); a seção 9g da `funcional.sh` prova as guardas do
servidor, que são o que de fato impede a sobrescrita.

A **condução ao vivo** também saiu, em parte: `provasCruzamentoNaSala` percorre
uma oficina inteira — o condutor abre o 🎤, um celular de verdade (390×844)
propõe o par, o painel do condutor anda sozinho e o "Usar" vira registro. O que
continua fora são os outros ritos da sala (a tempestade e a matriz de
prioridade) a duas telas.

Uma armadilha própria destas duas: elas dependem de o banco local estar
**limpo**. Massa deixada por uma execução interrompida empurra os projetos da
prova tela abaixo, e as medições de rolagem (o cabeçalho fixo de Projetos)
falham por posição, não por defeito. Antes de investigar um vermelho ali,
confira se sobrou lixo de prova no banco.

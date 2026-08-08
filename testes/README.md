# Baterias de validação

Três baterias, cada uma cobrindo uma camada diferente. Elas não substituem a
leitura do código — cobrem o que a leitura não pega: regressão silenciosa.

| Bateria | O que cobre | Como falha |
|---|---|---|
| `funcional.sh` | Os caminhos de **escrita** de cada módulo, pela própria API | Uma regra de negócio parou de valer, ou passou a valer onde não devia |
| `sistema.js` | As **15 seções** em 1500×900 e 390×844 | Uma tela parou de pintar, estourou erro de console ou passou a rolar na horizontal no celular |
| `participante.js` | A tela **pública** da tempestade no celular | A única superfície de escrita sem login quebrou, ou o polling voltou a fechar o teclado |

## Antes de rodar

As três batem numa instância **local** — nunca aponte para produção: a
`funcional.sh` cria e apaga registros.

```bash
# 1. Banco (MariaDB local; o socket precisa de caminho curto)
mariadbd --user=root --datadir=<dir> --socket=/tmp/ccm.sock --port=33061 &

# 2. Esquema e semente
DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
  ADMIN_SENHA=trocar123 php database/migrate.php

# 3. Servidor — o argumento router (public/index.php) é OBRIGATÓRIO
DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
  php -S 127.0.0.1:8099 -t public public/index.php
```

## Rodando

```bash
./testes/rodar.sh                 # as três, em sequência
./testes/funcional.sh             # só a funcional
node testes/sistema.js            # só a de sistema
node testes/participante.js 123456   # precisa do PIN de uma rodada ABERTA
```

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
- Concorrência de verdade: dois condutores agindo ao mesmo tempo.

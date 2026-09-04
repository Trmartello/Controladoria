<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Json;
use App\Services\Cruzamentos;
use App\Services\Quiz;

/**
 * Entrada do participante na tempestade de ideias — as ÚNICAS rotas de escrita
 * sem autenticação do sistema.
 *
 * Não há sessão, logo não há CSRF a validar: um token só faz sentido contra
 * autoridade ambiente, e aqui não existe nenhuma. As guardas são outras:
 *
 *  - o participante é **registrado** ao entrar (`coleta_participante`); o token
 *    só vale se existir naquela rodada. Sem isso ele seria auto-emitido —
 *    qualquer string hex passaria — e o teto de ideias não valeria nada;
 *  - o nome vem do registro, nunca do corpo do envio, senão daria para assinar
 *    uma ideia com o nome de um colega;
 *  - os tetos de ideias e de votos são aplicados no próprio INSERT, para dois
 *    envios simultâneos não furarem a contagem;
 *  - encerrada a rodada, o tema deixa de ser legível e nada mais é aceito;
 *  - PIN errado é contado por origem e trava a enumeração por força bruta;
 *  - exige Content-Type JSON, o que obriga o navegador a fazer preflight e
 *    impede que um site de terceiro faça os visitantes dele escreverem aqui.
 */
class PublicoController
{
    private const MAX_TEXTO = 400;
    private const MAX_NOME = 60;
    /**
     * Tentativas de PIN inválido toleradas por origem dentro da janela.
     * Generoso de propósito: a sala inteira costuma sair por um IP só (NAT do
     * wi-fi), e errar o PIN digitando é comum. Ainda assim, 40 por 5 minutos
     * deixa uma varredura do espaço de 6 dígitos em escala de anos.
     */
    private const MAX_TENTATIVAS = 40;
    // Teto de PINs errados na instalação inteira dentro da janela: segura o
    // ataque distribuído, que passaria folgado pelo balde por origem. Só alcança
    // quem erra o PIN — quem acerta entra mesmo com os baldes cheios.
    private const MAX_TENTATIVAS_GLOBAL = 300;
    private const JANELA_MIN = 5;
    /**
     * Silêncio que conta como "saiu da sala", na reentrada pelo nome.
     *
     * Faz o papel do `isConnected` do Quiz Copérdia, onde a conexão SSE diz quem
     * está on-line. Aqui a tela consulta de 4 em 4 segundos, então cinco minutos
     * são setenta e cinco consultas perdidas: quem está na sala não é dado como
     * ausente por causa de rede ruim, elevador, celular que bloqueou ou uma
     * chamada que entrou por cima.
     *
     * A janela é conservadora de propósito. Errar para o lado curto entrega o
     * nome de quem ainda está lá a quem chega digitando o mesmo nome; errar para
     * o lado longo só faz quem TROCA de aparelho esperar — e mesmo esse caso é
     * raro, porque o caminho do aparelho devolve a identidade na hora.
     *
     * Ajustável por ambiente (`SALA_AUSENTE_SEG`), em segundos.
     */
    private const AUSENTE_SEG_PADRAO = 300;
    /** Só reescreve `visto_em` quando ele já envelheceu: o polling é de 4 s. */
    private const VISTO_FRESCO_SEG = 10;

    private static function ausenteSeg(): int
    {
        return max(5, (int)(function_exists('env') ? env('SALA_AUSENTE_SEG', null) : null)
            ?: self::AUSENTE_SEG_PADRAO);
    }

    /**
     * Dados públicos da rodada. Encerrada, só devolve que encerrou: o tema é a
     * pergunta estratégica da oficina e não fica legível depois.
     */
    public function rodada(string $pin): void
    {
        $this->exigirOrigemComFolga();
        $r = $this->rodadaPorPin($pin);
        if ($r['situacao'] !== 'ABERTA') {
            Json::ok(['situacao' => 'ENCERRADA']);
        }
        // O rito é da PERGUNTA ATIVA, não da rodada: o mesmo PIN atende a
        // cascata, o cenário e os quadrantes do PESTEL/Porter/SWOT, e o celular
        // acompanha a tela que o condutor abriu. `paraSala` monta o contexto
        // que torna a pergunta respondível — sem ele a célula da cascata é
        // abstrata demais e o quadrante da SWOT vira adivinhação.
        $ativa = $r['modo'] === 'QUIZ' ? Quiz::ativa((int)$r['id']) : null;
        Json::ok([
            'tema' => $r['tema'],
            'situacao' => $r['situacao'],
            'votacao' => $r['votacao'],
            'modo' => $r['modo'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
            'prazo' => $r['prazo'] ?? null,
            // O QUESTIONÁRIO da tempestade: as perguntas em ordem, todas
            // abertas ao mesmo tempo — o celular percorre uma a uma, no ritmo
            // de quem responde. Lista vazia = tempestade de tema único.
            'perguntas' => $r['modo'] === 'TEMPESTADE'
                ? Quiz::perguntasDaTempestade((int)$r['id']) : [],
            'pergunta' => $ativa ? Quiz::paraSala($ativa, (int)$r['planejamento_id']) : null,
            // Progresso enxuto: o roteiro completo é do condutor. Esta rota
            // roda a cada 4s por participante.
            'progresso' => $r['modo'] === 'QUIZ'
                ? Quiz::progressoDaRodada((int)$r['id']) : null,
        ]);
    }

    /**
     * Entra na rodada — ou VOLTA para ela. Três caminhos, na ordem, portados do
     * Quiz Copérdia (`server.js`, `POST /api/rooms/:pin/join`), onde o desenho
     * já rodou em encontro de verdade.
     *
     * O problema que eles resolvem: cunhar um token novo a cada entrada fazia de
     * quem voltava OUTRA pessoa. As estrelas apareciam apagadas, o teto zerava e
     * os votos antigos seguiam contando — a mesma pessoa pesando duas vezes num
     * ranking que existe para priorizar, sem sinal nenhum na tela. Com o
     * encontro à distância, voltar à sala é o gesto comum, não a exceção.
     *
     *  1. **Pelo aparelho.** O navegador guarda um identificador só dele e o
     *     apresenta na entrada. Bate com alguém desta rodada? É essa pessoa. É o
     *     caminho silencioso e sem risco: o identificador é secreto, não se
     *     adivinha e não vale em rodada nenhuma além desta.
     *  2. **Pelo nome, se o dono estiver calado.** Trocar de aparelho (do
     *     celular para o computador) perde o identificador, e aí só resta o
     *     nome. Devolver a identidade por ele é entregar a credencial de alguém,
     *     então vale só quando o dono não fala com o servidor há
     *     `AUSENTE_SEG` — a regra do Quiz: "desconectado é a mesma pessoa
     *     voltando; conectado, não é". Com o dono ativo, o nome é recusado.
     *  3. **Alguém novo.** Token novo, como sempre foi.
     */
    public function entrar(): void
    {
        $d = $this->corpo();
        $this->exigirOrigemComFolga();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $dispositivo = mb_substr(trim(is_string($d['dispositivo'] ?? null) ? $d['dispositivo'] : ''), 0, 80);

        if ($dispositivo !== '') {
            $p = Database::um(
                'SELECT token, nome FROM coleta_participante
                  WHERE rodada_id = ? AND dispositivo = ? ORDER BY id LIMIT 1',
                [(int)$r['id'], $dispositivo]
            );
            if ($p) {
                $this->marcarVisto((int)$r['id'], (string)$p['token'], true);
                Json::ok($this->credencial($r, (string)$p['token'], (string)$p['nome'], true));
            }
        }

        $nome = mb_substr(trim(is_string($d['nome'] ?? null) ? $d['nome'] : ''), 0, self::MAX_NOME);
        if ($nome === '') {
            // Pedido só com o aparelho é uma PERGUNTA ("me conhece?"), feita
            // pela tela antes de mostrar o formulário. Aparelho novo é a
            // resposta "não" — não é falha, e responder com erro encheria o
            // console de todo participante que chega pela primeira vez.
            if ($dispositivo !== '') {
                Json::ok(['conhecido' => false]);
            }
            Json::erro('Digite seu nome para entrar.');
        }

        // A comparação é a da collation da coluna (utf8mb4_unicode_ci): ignora
        // caixa e acento, então "João" e "joao" são a mesma pessoa voltando —
        // que é o que se quer de quem redigita o próprio nome no outro aparelho.
        $homonimo = Database::um(
            'SELECT token, nome,
                    (visto_em IS NOT NULL AND visto_em > (NOW() - INTERVAL ? SECOND)) AS ativo
               FROM coleta_participante
              WHERE rodada_id = ? AND nome = ? ORDER BY id LIMIT 1',
            [self::ausenteSeg(), (int)$r['id'], $nome]
        );
        if ($homonimo) {
            if ((int)$homonimo['ativo']) {
                Json::erro('Já há alguém com esse nome na sala agora. Use outro nome — ou, '
                    . 'se for você em outro aparelho, feche a aba que ficou aberta e tente de novo.', 409);
            }
            // O aparelho novo passa a ser o dela: da próxima vez o caminho 1
            // resolve sozinho, sem depender do nome.
            Database::executar(
                'UPDATE coleta_participante SET dispositivo = ?, visto_em = NOW()
                  WHERE rodada_id = ? AND token = ?',
                [$dispositivo !== '' ? $dispositivo : null, (int)$r['id'], $homonimo['token']]
            );
            Json::ok($this->credencial($r, (string)$homonimo['token'], (string)$homonimo['nome'], true));
        }

        $token = bin2hex(random_bytes(16));
        Database::executar(
            'INSERT INTO coleta_participante (rodada_id, token, nome, dispositivo, visto_em)
             VALUES (?, ?, ?, ?, NOW())',
            [(int)$r['id'], $token, $nome, $dispositivo !== '' ? $dispositivo : null]
        );
        Json::ok($this->credencial($r, $token, $nome, false));
    }

    /**
     * "Não é você?" — solta o aparelho da pessoa anterior para que a próxima a
     * usar esta máquina entre como ela mesma. Sem isto, o caminho 1 devolveria
     * para sempre a primeira identidade no computador do departamento.
     *
     * Não apaga o participante: as ideias e as estrelas dele continuam valendo.
     * Também não exige o token — quem tem o aparelho na mão já provou o que
     * precisava, e o pedido só desfaz um vínculo.
     */
    public function esquecer(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaPorPin((string)($d['pin'] ?? ''));
        $dispositivo = mb_substr(trim(is_string($d['dispositivo'] ?? null) ? $d['dispositivo'] : ''), 0, 80);
        if ($dispositivo !== '') {
            Database::executar(
                'UPDATE coleta_participante SET dispositivo = NULL
                  WHERE rodada_id = ? AND dispositivo = ?',
                [(int)$r['id'], $dispositivo]
            );
        }
        Json::ok();
    }

    /** A resposta da entrada, uma só para os três caminhos. */
    private function credencial(array $r, string $token, string $nome, bool $voltou): array
    {
        return [
            'token' => $token,
            'nome' => $nome,
            'voltou' => $voltou,
            'tema' => $r['tema'],
            'modo' => $r['modo'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
        ];
    }

    /**
     * Registra que a pessoa está viva do outro lado. É o sinal que decide se o
     * nome dela pode ser reaproveitado por quem chega.
     *
     * Só escreve quando o registro já envelheceu: o polling bate de 4 em 4
     * segundos por participante, e reescrever a cada consulta transformaria uma
     * oficina de trinta pessoas num UPDATE a cada 130 ms sem ganho nenhum.
     */
    private function marcarVisto(int $rodadaId, string $token, bool $agora = false): void
    {
        Database::executar(
            'UPDATE coleta_participante SET visto_em = NOW()
              WHERE rodada_id = ? AND token = ?'
              . ($agora ? '' : ' AND (visto_em IS NULL OR visto_em < (NOW() - INTERVAL '
                  . self::VISTO_FRESCO_SEG . ' SECOND))'),
            [$rodadaId, $token]
        );
    }

    /**
     * Resposta do quiz: uma sugestão para a pergunta ATIVA, do lado escolhido
     * quando o alvo tem lados. O participante pode mandar várias de cada lado —
     * o teto (`max_ideias`) conta por (pergunta, tipo), senão quem gastasse
     * tudo num lado ficaria proibido de propor uma única coisa do outro.
     */
    public function resposta(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        if ($r['modo'] !== 'QUIZ') {
            Json::erro('Esta rodada é uma tempestade de ideias — envie pela tela de ideias.');
        }
        $p = $this->participante($r, $d);

        $ativa = Quiz::ativa((int)$r['id']);
        if (!$ativa) {
            Json::erro('A pergunta foi fechada pela condução. Aguarde a próxima.', 409);
        }
        // O corpo declara qual pergunta o participante estava VENDO; a verdade é
        // a ativa. Se divergem (a condução avançou no meio da digitação), a
        // resposta seria gravada num alvo que a pessoa nunca leu — recusar é
        // mais honesto que aceitar em silêncio.
        if ((int)($d['pergunta_id'] ?? 0) !== (int)$ativa['id']) {
            Json::erro('A condução mudou de pergunta. Releia o contexto e envie de novo.', 409);
        }

        // Lista branca DERIVADA DA PERGUNTA, não um ENUM fixo: alvo sem lado
        // (a categoria já é a pergunta) grava NULL, e valor inventado no corpo
        // cai no primeiro lado válido, pela mesma razão que o nome sai do
        // registro e nunca do corpo.
        //
        // A pergunta da ETAPA INTEIRA é a exceção ao "cai no primeiro": ali os
        // lados são as categorias e a escolha é o conteúdo da resposta —
        // gravar "Político" porque a pessoa não escolheu nada mandaria a
        // sugestão para um quadrante que ninguém escolheu. Sem categoria
        // válida, recusa e diz o que falta.
        $alvoTipo = (string)$ativa['alvo_tipo'];
        $lados = [];
        foreach (Quiz::ladosDe($ativa) as $l) {
            $lados[$l['valor']] = $l['rotulo'];
        }
        $pedido = is_string($d['tipo'] ?? null) ? $d['tipo'] : '';
        if (Quiz::escolheCategoria($ativa)) {
            if (!isset($lados[$pedido])) {
                Json::erro('Escolha a categoria da sugestão antes de enviar.');
            }
            $tipo = $pedido;
        } else {
            $tipo = $lados
                ? (isset($lados[$pedido]) ? $pedido : (string)array_key_first($lados))
                : null;
        }
        $limite = Quiz::LIMITE_TEXTO[$alvoTipo] ?? 255;
        $texto = mb_substr(trim(is_string($d['texto'] ?? null) ? $d['texto'] : ''), 0, $limite);
        if ($texto === '') {
            Json::erro('Escreva a sugestão antes de enviar.');
        }

        // O PAR do cruzamento é a única coisa que esta rota aceita que não é
        // texto: dois ids de registro, vindos de uma tela sem login. Três
        // guardas, e nenhuma delas confia no corpo para nada além dos dois
        // números:
        //
        //  1. `Cruzamentos::parValidado` é a MESMA conferência da tela de
        //     dentro — os dois fatores existem, são da SWOT DESTE planejamento
        //     (o da rodada, não o do corpo), são de anos iguais, e formam um
        //     par interno × externo de verdade;
        //  2. o ANO tem de ser o da pergunta ativa, senão a sala responderia a
        //     SWOT de outro exercício sem ninguém perceber;
        //  3. o BLOCO derivado do par tem de ser o bloco PERGUNTADO. Sem esta,
        //     a pergunta "Forças × Oportunidades" aceitaria um par de fraqueza
        //     com ameaça, e o painel do condutor encheria de resposta fora do
        //     assunto — a pergunta viraria decoração.
        $internoId = null;
        $externoId = null;
        if ($alvoTipo === 'CRUZAMENTO') {
            $par = Cruzamentos::parValidado(
                (int)($d['fator_interno_id'] ?? 0),
                (int)($d['fator_externo_id'] ?? 0),
                (int)$r['planejamento_id'],
                (int)$ativa['ano']
            );
            if ($par['tipo'] !== (string)$ativa['categoria']) {
                $bloco = Cruzamentos::BLOCOS[(string)$ativa['categoria']] ?? null;
                Json::erro('Esta pergunta é do bloco '
                    . ($bloco['rotulo'] ?? 'escolhido')
                    . '. Escolha um fator de cada lado deste bloco.');
            }
            $internoId = (int)$par['interno']['id'];
            $externoId = (int)$par['externo']['id'];
        }

        // Teto dentro do INSERT, por (pergunta, tipo): dois envios simultâneos
        // não furam a contagem. O <=> compara com NULL (alvo sem lado), onde o
        // `=` devolveria NULL e a contagem sairia sempre zero — o teto virava
        // decoração justamente nas telas que não têm lado.
        $gravadas = Database::afetadas(
            "INSERT INTO coleta_item (planejamento_id, rodada_id, origem, pergunta_id, tipo_resposta,
               ano, autor_id, autor_nome, participante_token, texto,
               fator_interno_id, fator_externo_id)
             SELECT ?, ?, 'QUIZ', ?, ?, ?, NULL, ?, ?, ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_item x
                              WHERE x.pergunta_id = ? AND x.participante_token = ?
                                AND x.tipo_resposta <=> ?) < ?",
            [
                (int)$r['planejamento_id'], (int)$r['id'], (int)$ativa['id'], $tipo,
                (int)$r['ano'], $p['nome'], $p['token'], $texto, $internoId, $externoId,
                (int)$ativa['id'], $p['token'], $tipo, (int)$r['max_ideias'],
            ]
        );
        if (!$gravadas) {
            $lado = $tipo === null
                ? 'sugestão(ões)'
                : (Quiz::escolheCategoria($ativa)
                    ? "sugestão(ões) em {$lados[$tipo]}"
                    : mb_strtolower($lados[$tipo]) . '(s)');
            Json::erro("Você já enviou {$r['max_ideias']} {$lado} nesta pergunta.");
        }
        Json::ok(['ok' => true]);
    }

    public function ideia(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        // Espelho da guarda de resposta(): sem ela, um participante do quiz
        // (mesmo PIN, mesma tabela) plantava "ideias" de 400 caracteres fora de
        // pergunta nenhuma — invisíveis para o condutor do quiz e caindo direto
        // na fila de triagem da Coleta
        if ($r['modo'] !== 'TEMPESTADE') {
            Json::erro('Esta sala é uma sessão de quiz — responda pela tela da pergunta.');
        }
        $this->exigirSalaRecolhendo($r);
        $p = $this->participante($r, $d);

        $texto = mb_substr(trim(is_string($d['texto'] ?? null) ? $d['texto'] : ''), 0, self::MAX_TEXTO);
        if ($texto === '') {
            Json::erro('Escreva a ideia antes de enviar.');
        }
        $destino = in_array($d['destino_sugerido'] ?? '', ['CENARIO', 'PESTEL', 'PORTER', 'SWOT'], true)
            ? $d['destino_sugerido'] : 'NAO_SEI';
        // No QUESTIONÁRIO (tempestade com perguntas), a ideia responde a UMA
        // pergunta, e o teto conta por pergunta — "cinco por pessoa" vale em
        // cada uma, senão quem gastasse tudo na primeira ficaria calado nas
        // outras. A pergunta tem de ser desta rodada e livre: ideia sem
        // pergunta num questionário cairia na Coleta sem dizer a que respondia,
        // e o corpo forjado não escolhe a pergunta de outra sala.
        $perguntas = Quiz::perguntasDaTempestade((int)$r['id']);
        $perguntaId = null;
        if ($perguntas) {
            $pedida = (int)($d['pergunta_id'] ?? 0);
            foreach ($perguntas as $q) {
                if ((int)$q['id'] === $pedida) {
                    $perguntaId = $pedida;
                }
            }
            if ($perguntaId === null) {
                Json::erro('Escolha a pergunta que a ideia responde.');
            }
        }
        // Texto igual já entra no mesmo grupo: assim o agrupamento automático e
        // o manual (arrastar uma sobre a outra) são o mesmo mecanismo. No
        // questionário, só dentro da MESMA pergunta: "Câmbio" respondido em
        // "Quais riscos?" e em "Quais oportunidades?" são duas ideias.
        $lider = $this->liderEquivalente((int)$r['id'], $texto, null, $perguntaId);

        // O teto vai dentro do próprio INSERT: dois envios ao mesmo tempo não
        // conseguem furar a contagem, como fariam com COUNT + INSERT separados.
        // `<=>` e não `=`: sem questionário a pergunta é NULL, e `=` devolveria
        // NULL — o teto viraria decoração na tempestade de tema único.
        $gravadas = Database::afetadas(
            'INSERT INTO coleta_item (planejamento_id, rodada_id, ano, autor_id, autor_nome,
               participante_token, texto, destino_sugerido, agrupado_em_id, pergunta_id)
             SELECT ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_item x
                              WHERE x.rodada_id = ? AND x.participante_token = ?
                                AND x.pergunta_id <=> ?) < ?',
            [
                (int)$r['planejamento_id'], (int)$r['id'], (int)$r['ano'],
                $p['nome'], $p['token'], $texto, $destino, $lider, $perguntaId,
                (int)$r['id'], $p['token'], $perguntaId, (int)$r['max_ideias'],
            ]
        );
        if (!$gravadas) {
            Json::erro("Você já enviou {$r['max_ideias']} ideia(s) nesta "
                . ($perguntaId ? 'pergunta' : 'rodada') . '.');
        }
        Json::ok(['ok' => true]);
    }

    /**
     * Corrige o texto da própria ideia, enquanto a rodada está aberta e a ideia
     * ainda está `NOVO` (não foi triada). Sem sessão, a autoria é provada pelo
     * token: o escopo do UPDATE (id + rodada + token + NOVO) é a guarda.
     */
    public function editarIdeia(int $id): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        // Sala fechada não recebe escrita nenhuma — nem ideia nova, nem correção
        // de uma já enviada. Enquanto a sala escolhe com ★, o texto está debaixo
        // do voto de outra pessoa: reescrevê-lo mudaria o que já foi votado.
        $this->exigirSalaRecolhendo($r);
        $p = $this->participante($r, $d);

        // A autoria é conferida por SELECT, não pelo número de linhas do UPDATE:
        // o PDO devolve linhas ALTERADAS, e salvar sem ter mudado o texto (abrir
        // o "✎", reler e confirmar) alterava zero linhas — o participante levava
        // "Não dá mais para editar esta ideia" no meio de uma oficina, como se a
        // ideia dele tivesse sido triada. O escopo continua sendo a guarda.
        // (No quiz da cascata, NOVO também significa "ainda não vinculada à
        // célula": o vínculo marca ACEITO, e a voz oficializada congela.)
        $minha = Database::um(
            "SELECT ci.id, ci.origem, ci.pergunta_id, p.alvo_tipo FROM coleta_item ci
             LEFT JOIN quiz_pergunta p ON p.id = ci.pergunta_id
             WHERE ci.id = ? AND ci.rodada_id = ? AND ci.participante_token = ?
               AND ci.situacao = 'NOVO'",
            [$id, (int)$r['id'], $p['token']]
        );
        if (!$minha) {
            // Não é dela, já foi triada, ou a rodada virou: nada a corrigir.
            Json::erro('Não dá mais para editar esta ideia.', 409);
        }

        // O limite acompanha o ALVO, o mesmo que valeu no envio: corrigir não
        // pode ser porta para um texto que a resposta original não aceitaria.
        $eQuiz = $minha['origem'] === 'QUIZ';
        $limite = $eQuiz
            ? (Quiz::LIMITE_TEXTO[(string)$minha['alvo_tipo']] ?? 255)
            : self::MAX_TEXTO;
        $texto = mb_substr(trim(is_string($d['texto'] ?? null) ? $d['texto'] : ''), 0, $limite);
        if ($texto === '') {
            Json::erro('Escreva a ideia antes de salvar.');
        }
        Database::executar(
            "UPDATE coleta_item SET texto = ?
             WHERE id = ? AND rodada_id = ? AND participante_token = ? AND situacao = 'NOVO'",
            [$texto, $id, (int)$r['id'], $p['token']]
        );

        // O agrupamento automático é por texto; mudando o texto, o vínculo pode
        // ter ficado velho. Reavalia só quando esta ideia NÃO lidera um grupo,
        // para não dissolver, sem querer, um grupo que já reuniu ideias de
        // outras pessoas. Resposta de quiz fica de fora: o agrupamento dela é
        // por (pergunta, tipo) e chega na fase própria.
        $lidera = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_item WHERE agrupado_em_id = ?',
            [$id]
        )['n'] ?? 0);
        if (!$lidera && !$eQuiz) {
            Database::executar(
                'UPDATE coleta_item SET agrupado_em_id = ? WHERE id = ?',
                [$this->liderEquivalente((int)$r['id'], $texto, $id,
                    $minha['pergunta_id'] !== null ? (int)$minha['pergunta_id'] : null), $id]
            );
        }

        Json::ok(['ok' => true]);
    }

    /** As próprias ideias, para o participante conferir e corrigir. */
    public function minhas(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $p = $this->participante($r, $_GET);
        // pergunta_id e tipo_resposta servem ao quiz (a tela mostra só as da
        // pergunta ativa, com o selo do lado); na tempestade vêm nulos.
        // `max_texto` vem do ALVO de cada uma: o editor precisa do MESMO limite
        // que valeu no envio, senão oferece espaço que o servidor vai cortar em
        // silêncio — e num alvo sem lado (PESTEL, SWOT) não dá para deduzi-lo
        // pelo tipo_resposta, que ali é nulo.
        $itens = Database::todos(
            'SELECT ci.id, ci.texto, ci.votos, ci.situacao, ci.pergunta_id, ci.tipo_resposta,
                    ci.origem, qp.alvo_tipo
             FROM coleta_item ci
             LEFT JOIN quiz_pergunta qp ON qp.id = ci.pergunta_id
             WHERE ci.rodada_id = ? AND ci.participante_token = ? ORDER BY ci.id',
            [(int)$r['id'], $p['token']]
        );
        foreach ($itens as &$i) {
            $i['max_texto'] = $i['origem'] === 'QUIZ'
                ? (Quiz::LIMITE_TEXTO[(string)$i['alvo_tipo']] ?? 255)
                : self::MAX_TEXTO;
        }
        Json::ok($itens);
    }

    /**
     * As ★ estão liberadas para o participante? Na tempestade de tema único,
     * só quando quem conduz FECHA a sala (`votacao` ABERTA). No QUESTIONÁRIO
     * (pedido do cliente, 2026-09-04) elas ficam liberadas o tempo todo em que
     * a rodada está aberta: cada pessoa conclui as respostas no seu dia e já
     * elege as de maior impacto — sem esperar um condutor que, num prazo de
     * semanas, não está lá para fechar sala nenhuma.
     */
    private function estrelasLiberadas(array $r): bool
    {
        return $r['situacao'] === 'ABERTA' && $r['modo'] === 'TEMPESTADE'
            && ($r['votacao'] === 'ABERTA' || Quiz::temQuestionario((int)$r['id']));
    }

    /** Ideias abertas para votação, quando quem conduz liberar essa fase. */
    public function paraVotar(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $p = $this->participante($r, $_GET);
        // A estrela do quiz chega na fase própria, com teto POR PERGUNTA; a
        // votação da tempestade conta por rodada e furaria a regra
        if (!$this->estrelasLiberadas($r)) {
            Json::ok(['votacao' => 'FECHADA', 'estrelas' => 'FECHADA', 'itens' => [], 'meus_votos' => 0]);
        }
        // `minha` marca as ideias do próprio participante na lista: quem escreve
        // três ideias e vota em três precisa reconhecer as suas para decidir
        // quais defender. `<=>` e não `=`: a ideia cadastrada pela condução tem
        // token NULL, e `=` devolveria NULL (nem verdadeiro nem falso) — o selo
        // sumiria de todo mundo assim que uma dessas entrasse na lista.
        // No questionário a lista vem NA ORDEM DAS PERGUNTAS (e o celular a
        // separa em blocos por pergunta): misturadas pela ordem de chegada,
        // as respostas de cinco perguntas viravam um vaivém entre assuntos.
        $itens = Database::todos(
            "SELECT i.id, i.texto, i.pergunta_id, (v.id IS NOT NULL) AS votei,
                    (i.participante_token <=> ?) AS minha
             FROM coleta_item i
             LEFT JOIN coleta_voto v ON v.item_id = i.id AND v.participante_token = ?
             LEFT JOIN quiz_pergunta qp ON qp.id = i.pergunta_id
             WHERE i.rodada_id = ? AND i.situacao = 'NOVO'
             ORDER BY qp.ordem, i.id",
            [$p['token'], $p['token'], (int)$r['id']]
        );
        // Só contam os votos em ideias ainda na lista: tratada uma ideia, o
        // voto dela sairia da tela mas continuaria consumindo o teto, e não
        // haveria como devolvê-lo (desvotar exige tocar no item)
        $meus = (int)(Database::um(
            "SELECT COUNT(*) AS n FROM coleta_voto v
             JOIN coleta_item i ON i.id = v.item_id AND i.situacao = 'NOVO'
             WHERE v.rodada_id = ? AND v.participante_token = ?",
            [(int)$r['id'], $p['token']]
        )['n'] ?? 0);
        // No questionário o teto é POR PERGUNTA (como o de ideias), e o celular
        // mostra quantas estrelas restam em cada uma
        $porPergunta = [];
        foreach (Database::todos(
            "SELECT i.pergunta_id, COUNT(*) AS n FROM coleta_voto v
             JOIN coleta_item i ON i.id = v.item_id AND i.situacao = 'NOVO'
             WHERE v.rodada_id = ? AND v.participante_token = ? AND i.pergunta_id IS NOT NULL
             GROUP BY i.pergunta_id",
            [(int)$r['id'], $p['token']]
        ) as $l) {
            $porPergunta[(string)$l['pergunta_id']] = (int)$l['n'];
        }
        // `votacao` segue sendo a chave da SALA (fechada = sem campo de escrever);
        // `estrelas` diz se dá para votar — no questionário, as duas divergem.
        Json::ok(['votacao' => $r['votacao'], 'estrelas' => 'ABERTA', 'itens' => $itens,
                  'meus_votos' => $meus, 'max_votos' => (int)$r['max_votos'],
                  'meus_por_pergunta' => (object)$porPergunta]);
    }

    /** Alterna o voto do participante numa ideia, respeitando o teto. */
    public function votar(int $id): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $p = $this->participante($r, $d);
        if (!$this->estrelasLiberadas($r)) {
            Json::erro('A votação não está aberta.');
        }
        // Ideia já tratada não recebe voto: seria gastar um voto em algo que o
        // participante nunca mais vê na lista
        $item = Database::um(
            "SELECT id, pergunta_id FROM coleta_item WHERE id = ? AND rodada_id = ? AND situacao = 'NOVO'",
            [$id, (int)$r['id']]
        );
        if (!$item) {
            Json::erro('Esta ideia não está mais em votação.', 404);
        }

        if (Database::afetadas(
            'DELETE FROM coleta_voto WHERE item_id = ? AND participante_token = ?',
            [$id, $p['token']]
        )) {
            $this->recontar($id);
            Json::ok(['votou' => false]);
        }

        // Teto dentro do INSERT, e IGNORE para o toque duplo no mesmo item não
        // virar erro 500 pela chave única. O `<=>` faz o teto contar POR
        // PERGUNTA no questionário (a ideia tem pergunta) e por rodada na
        // tempestade de tema único (pergunta NULL em todas).
        $perguntaId = $item['pergunta_id'] !== null ? (int)$item['pergunta_id'] : null;
        $gravou = Database::afetadas(
            'INSERT IGNORE INTO coleta_voto (item_id, rodada_id, participante_token)
             SELECT ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_voto v
                              JOIN coleta_item i ON i.id = v.item_id AND i.situacao = \'NOVO\'
                              WHERE v.rodada_id = ? AND v.participante_token = ?
                                AND (i.pergunta_id <=> ?)) < ?',
            [$id, (int)$r['id'], $p['token'], (int)$r['id'], $p['token'], $perguntaId, (int)$r['max_votos']]
        );
        if (!$gravou) {
            Json::erro($perguntaId !== null
                ? "Você já usou suas {$r['max_votos']} estrela(s) nesta pergunta. Toque numa marcada para trocar."
                : "Você já usou seus {$r['max_votos']} voto(s). Toque num que já votou para trocar.");
        }
        $this->recontar($id);
        Json::ok(['votou' => true]);
    }

    /**
     * A fase da ESTRELA do quiz: fechado o 🎤, o celular passa a votar no que a
     * sala acabou de dizer — e SÓ enquanto nenhuma outra pergunta está aberta.
     * Com pergunta ativa, responder é o trabalho; a estrela esperaria a vez e
     * dividiria a atenção de quem está escrevendo.
     *
     * O teto é POR PERGUNTA (o da tempestade conta por rodada): num encontro de
     * dez perguntas, um teto por rodada acabaria na segunda.
     */
    public function estrelas(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $p = $this->participante($r, $_GET);
        $pergunta = $this->perguntaComEstrela($r);
        if (!$pergunta) {
            Json::ok(['fase' => 'FECHADA', 'itens' => []]);
        }
        $itens = Database::todos(
            "SELECT i.id, i.texto, i.tipo_resposta, i.votos, (v.id IS NOT NULL) AS votei
             FROM coleta_item i
             LEFT JOIN coleta_voto v ON v.item_id = i.id AND v.participante_token = ?
             WHERE i.pergunta_id = ? AND i.origem = 'QUIZ' AND i.situacao = 'NOVO'
             ORDER BY i.id",
            [$p['token'], (int)$pergunta['id']]
        );
        Json::ok([
            'fase' => 'ESTRELAS',
            // Sem `planejamento_id`, de propósito: nesta fase já não se responde
            // — as listas do par do cruzamento seriam peso na rede e exposição
            // de conteúdo para nada.
            'pergunta' => Quiz::paraSala($pergunta),
            'itens' => $itens,
            'meus_votos' => $this->estrelasUsadas((int)$pergunta['id'], $p['token']),
            'max_votos' => (int)$r['max_votos'],
        ]);
    }

    /** Alterna a estrela numa resposta do quiz, respeitando o teto da pergunta. */
    public function estrela(int $id): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $p = $this->participante($r, $d);
        $pergunta = $this->perguntaComEstrela($r);
        if (!$pergunta) {
            Json::erro('A votação desta pergunta foi encerrada pela condução.', 409);
        }
        // O item tem de ser DESTA pergunta: sem a guarda, um id de outra
        // pergunta (ou da tempestade) entraria e furaria o teto de todas
        if (!Database::um(
            "SELECT id FROM coleta_item
             WHERE id = ? AND pergunta_id = ? AND origem = 'QUIZ' AND situacao = 'NOVO'",
            [$id, (int)$pergunta['id']]
        )) {
            Json::erro('Esta resposta não está mais em votação.', 404);
        }

        if (Database::afetadas(
            'DELETE FROM coleta_voto WHERE item_id = ? AND participante_token = ?',
            [$id, $p['token']]
        )) {
            $this->recontar($id);
            Json::ok(['votou' => false, 'meus_votos' => $this->estrelasUsadas((int)$pergunta['id'], $p['token'])]);
        }

        // Teto DENTRO do INSERT, como o da tempestade e o dos envios: dois
        // toques simultâneos furariam uma contagem feita antes
        $gravou = Database::afetadas(
            "INSERT IGNORE INTO coleta_voto (item_id, rodada_id, participante_token)
             SELECT ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_voto v
                              JOIN coleta_item i ON i.id = v.item_id
                                AND i.situacao = 'NOVO' AND i.pergunta_id = ?
                              WHERE v.participante_token = ?) < ?",
            [$id, (int)$r['id'], $p['token'], (int)$pergunta['id'], $p['token'], (int)$r['max_votos']]
        );
        if (!$gravou) {
            Json::erro("Você já usou suas {$r['max_votos']} estrela(s) nesta pergunta. "
                . 'Toque numa que já marcou para trocar.');
        }
        $this->recontar($id);
        Json::ok(['votou' => true, 'meus_votos' => $this->estrelasUsadas((int)$pergunta['id'], $p['token'])]);
    }

    /**
     * A pergunta aberta para a estrela — a última fechada, e só com a sala sem
     * pergunta ativa. Uma fonte só para as duas rotas: separadas, a leitura
     * mostraria uma pergunta e a escrita gravaria em outra.
     */
    private function perguntaComEstrela(array $r): ?array
    {
        if ($r['situacao'] !== 'ABERTA' || $r['modo'] !== 'QUIZ') {
            return null;
        }
        if (Quiz::ativa((int)$r['id'])) {
            return null;
        }
        return Quiz::encerradaRecente((int)$r['id']);
    }

    /** Estrelas que esta pessoa já gastou NESTA pergunta. */
    private function estrelasUsadas(int $perguntaId, string $token): int
    {
        return (int)(Database::um(
            "SELECT COUNT(*) AS n FROM coleta_voto v
             JOIN coleta_item i ON i.id = v.item_id AND i.situacao = 'NOVO'
             WHERE i.pergunta_id = ? AND v.participante_token = ?",
            [$perguntaId, $token]
        )['n'] ?? 0);
    }

    /**
     * Minúsculas, sem acento e sem espaço sobrando — a mesma regra do `norm()`
     * de `coleta.js`. Feita com tabela em vez de `Normalizer`: a extensão
     * `intl` não está na imagem (o Dockerfile só instala `pdo_mysql`).
     */
    public static function normalizar(string $t): string
    {
        $de = 'áàâãäéèêëíìîïóòôõöúùûüçÁÀÂÃÄÉÈÊËÍÌÎÏÓÒÔÕÖÚÙÛÜÇ';
        $para = 'aaaaaeeeeiiiiooooouuuucAAAAAEEEEIIIIOOOOOUUUUC';
        $mapa = [];
        $letrasDe = preg_split('//u', $de, -1, PREG_SPLIT_NO_EMPTY);
        $letrasPara = preg_split('//u', $para, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($letrasDe as $k => $letra) {
            $mapa[$letra] = $letrasPara[$k];
        }
        return preg_replace('/\s+/u', ' ', trim(mb_strtolower(strtr($t, $mapa))));
    }

    /**
     * Líder de um grupo cujo texto é equivalente (sem acento, sem caixa) ao
     * que está chegando. A comparação é em PHP: numa oficina são dezenas de
     * itens, e assim a regra é a mesma do agrupamento manual.
     */
    private function liderEquivalente(int $rodadaId, string $texto, ?int $excetoId = null, ?int $perguntaId = null): ?int
    {
        $alvo = self::normalizar($texto);
        // Só ideias da tempestade: o agrupamento da resposta de quiz é por
        // (pergunta, lado) e chega na fase própria — juntar as duas famílias
        // faria a ideia da fila liderar uma voz que pertence a outro rito.
        // E só da MESMA pergunta do questionário (`<=>`: sem questionário as
        // duas são NULL e a rodada inteira é um assunto só).
        foreach (Database::todos(
            "SELECT id, agrupado_em_id, texto FROM coleta_item
             WHERE rodada_id = ? AND origem = 'TEMPESTADE' AND pergunta_id <=> ?
               AND situacao IN ('NOVO','SELECIONADO') ORDER BY id",
            [$rodadaId, $perguntaId]
        ) as $i) {
            // Ao reagrupar uma ideia editada, ela não pode ser líder de si mesma.
            if ((int)$i['id'] === $excetoId) {
                continue;
            }
            if (self::normalizar($i['texto']) === $alvo) {
                return (int)($i['agrupado_em_id'] ?? 0) ?: (int)$i['id'];
            }
        }
        return null;
    }

    /** O contador do item sai sempre da tabela de votos, nunca de +1/-1. */
    private function recontar(int $id): void
    {
        Database::executar(
            'UPDATE coleta_item SET votos = (SELECT COUNT(*) FROM coleta_voto WHERE item_id = ?) WHERE id = ?',
            [$id, $id]
        );
    }

    /** Corpo JSON, exigindo o Content-Type que obriga preflight no navegador. */
    private function corpo(): array
    {
        $tipo = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (!str_starts_with($tipo, 'application/json')) {
            Json::erro('Envie os dados como application/json.', 415);
        }
        return Json::corpo();
    }

    /**
     * O token só vale se a pessoa entrou nesta rodada.
     *
     * Nas rotas GET ele chega pelo cabeçalho X-Participante, não pela query
     * string: na URL o segredo vazava para o log de acesso do servidor, para o
     * log da borda do Railway e para o histórico do navegador. A query segue
     * aceita como alternativa para não quebrar uma aba já aberta na oficina.
     */
    private function participante(array $rodada, array $origem): array
    {
        $token = (string)($_SERVER['HTTP_X_PARTICIPANTE'] ?? ($origem['token'] ?? ''));
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            Json::erro('Entre na rodada antes de participar.', 403);
        }
        $p = Database::um(
            'SELECT token, nome FROM coleta_participante WHERE rodada_id = ? AND token = ?',
            [(int)$rodada['id'], $token]
        );
        if (!$p) {
            Json::erro('Entre na rodada antes de participar.', 403);
        }
        // Toda chamada autenticada é sinal de presença: é ela que impede o nome
        // de quem está na sala de ser reaproveitado por quem chega.
        $this->marcarVisto((int)$rodada['id'], (string)$p['token']);
        return $p;
    }

    /**
     * A sala está recolhendo ideias? Fechá-la (a fase da ★) tira o campo de
     * escrever do celular, e essa recusa é o espelho da tela: sem ela, um
     * aparelho que ainda não bateu o polling — ou um pedido montado à mão —
     * continuava gravando ideia com a sala inteira já votando, e a ideia nova
     * entrava numa lista que ninguém mais ia ler.
     *
     * A condição repete a da tela de propósito: **fechada com a lista vazia não
     * é fase nenhuma**. Sem nada para votar o celular volta a recolher (senão o
     * participante ficaria numa tela onde não dá para escrever nem para votar),
     * e recusar aqui transformaria esse contorno em erro na cara de quem digita.
     */
    private function exigirSalaRecolhendo(array $r): void
    {
        if ($r['modo'] !== 'TEMPESTADE' || $r['votacao'] !== 'ABERTA') {
            return;
        }
        $paraVotar = (int)(Database::um(
            "SELECT COUNT(*) AS n FROM coleta_item WHERE rodada_id = ? AND situacao = 'NOVO'",
            [(int)$r['id']]
        )['n'] ?? 0);
        if ($paraVotar > 0) {
            Json::erro('A sala foi fechada: agora é a vez de escolher com ★ as ideias mais importantes.');
        }
    }

    /** Escrita só em rodada aberta. */
    private function rodadaAberta(string $pin): array
    {
        $r = $this->rodadaPorPin($pin);
        if ($r['situacao'] !== 'ABERTA') {
            Json::erro('Esta rodada já foi encerrada.');
        }
        return $r;
    }

    /**
     * Resolve o PIN. Cada PIN que não existe conta contra a origem, o que
     * inviabiliza varrer o espaço de 6 dígitos dentro da janela da oficina.
     *
     * O PIN é resolvido ANTES de olhar o balde, e PIN certo nunca é punido nem
     * contabilizado. A ordem inversa derrubava a oficina inteira: como este
     * método atende todas as rotas públicas (inclusive as de quem já tem token),
     * bastavam algumas dezenas de PINs errados de uma origem para tudo responder
     * 429 por minutos — e a origem costuma ser compartilhada, seja o NAT do
     * wi-fi da sala, seja a borda do Railway. Do jeito certo, o balde só alcança
     * quem está errando o PIN, que é justamente quem tenta adivinhar.
     */
    /**
     * A contenção ANTES de resolver o PIN — só nas duas rotas que não têm
     * token (`rodada` e `entrar`). Em `rodadaPorPin` o PIN certo passa sempre,
     * e o balde só decidia entre 404 e 429 para o errado: quem varresse o
     * espaço de 6 dígitos ignorava o 429 e seguia, porque o acerto era
     * devolvido de qualquer jeito. Aqui, origem com o balde cheio não resolve
     * PIN nenhum. Quem já tem token (`X-Participante`) não passa por aqui: a
     * sala inteira atrás de um NAT continua respondendo mesmo que alguém ali
     * esteja errando o PIN.
     */
    private function exigirOrigemComFolga(): void
    {
        $origem = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        $recentes = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_tentativa
             WHERE origem = ? AND criado_em > (NOW() - INTERVAL ? MINUTE)',
            [$origem, self::JANELA_MIN]
        )['n'] ?? 0);
        if ($recentes >= self::MAX_TENTATIVAS) {
            Json::erro('Muitas tentativas. Espere alguns minutos e confira o PIN no telão.', 429);
        }
        $totais = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_tentativa WHERE criado_em > (NOW() - INTERVAL ? MINUTE)',
            [self::JANELA_MIN]
        )['n'] ?? 0);
        if ($totais >= self::MAX_TENTATIVAS_GLOBAL) {
            Json::erro('Muitas tentativas. Espere alguns minutos e confira o PIN no telão.', 429);
        }
    }

    private function rodadaPorPin(string $pin): array
    {
        // O prazo do questionário fecha a rodada na primeira leitura depois
        // dele — é aqui que toda rota pública começa, então é aqui que o
        // celular descobre que acabou.
        Quiz::fecharVencidas();
        $r = preg_match('/^\d{6}$/', $pin)
            ? Database::um('SELECT * FROM coleta_rodada WHERE pin = ?', [$pin])
            : null;
        if ($r) {
            return $r;
        }

        $origem = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
        Database::executar('INSERT INTO coleta_tentativa (origem) VALUES (?)', [$origem]);
        // Limpeza oportunista: a tabela não pode crescer sem fim
        Database::executar(
            'DELETE FROM coleta_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY) LIMIT 500'
        );

        $recentes = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_tentativa
             WHERE origem = ? AND criado_em > (NOW() - INTERVAL ? MINUTE)',
            [$origem, self::JANELA_MIN]
        )['n'] ?? 0);
        // Teto global para origens distribuídas: sozinho, o balde por origem só
        // multiplica o orçamento de tentativas pelo número de origens
        $totais = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM coleta_tentativa
             WHERE criado_em > (NOW() - INTERVAL ? MINUTE)',
            [self::JANELA_MIN]
        )['n'] ?? 0);
        if ($recentes >= self::MAX_TENTATIVAS || $totais >= self::MAX_TENTATIVAS_GLOBAL) {
            Json::erro('Muitas tentativas. Espere alguns minutos e confira o PIN no telão.', 429);
        }
        Json::erro('Rodada não encontrada. Confira o PIN.', 404);
    }
}

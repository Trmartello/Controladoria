<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Json;

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
    // Resposta do quiz da cascata: vira o texto de uma célula de decisão, e 255
    // é o tamanho que cabe numa síntese — limite diferente da tempestade de
    // propósito, e imposto AQUI: o maxlength do campo é só conforto da tela.
    private const MAX_TEXTO_CASCATA = 255;
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
     * Dados públicos da rodada. Encerrada, só devolve que encerrou: o tema é a
     * pergunta estratégica da oficina e não fica legível depois.
     */
    public function rodada(string $pin): void
    {
        $r = $this->rodadaPorPin($pin);
        if ($r['situacao'] !== 'ABERTA') {
            Json::ok(['situacao' => 'ENCERRADA']);
        }
        Json::ok([
            'tema' => $r['tema'],
            'situacao' => $r['situacao'],
            'votacao' => $r['votacao'],
            'modo' => $r['modo'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
            // No quiz da cascata o celular precisa do contexto que torna a
            // pergunta respondível: driver, horizonte (com tema e objetivo) e
            // eixo. Sem isso a célula é abstrata demais para resposta útil.
            'pergunta' => $r['modo'] === 'CASCATA' ? $this->perguntaAtiva((int)$r['id']) : null,
        ]);
    }

    /**
     * A pergunta que a sala está respondendo agora — a fonte da verdade é a
     * situação ATIVA em cascata_pergunta, nunca uma coluna na rodada.
     */
    private function perguntaAtiva(int $rodadaId): ?array
    {
        return Database::um(
            "SELECT p.id, d.nome AS driver, e.nome AS eixo,
                    h.nome AS horizonte, h.ano_inicio, h.ano_fim, h.tema, h.objetivo
             FROM cascata_pergunta p
             JOIN driver d ON d.id = p.driver_id
             JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN eixo e ON e.id = p.eixo_id
             WHERE p.rodada_id = ? AND p.situacao = 'ATIVA'
             ORDER BY p.aberta_em DESC, p.id DESC",
            [$rodadaId]
        );
    }

    /** Entra na rodada com um nome; registra e devolve o token da pessoa. */
    public function entrar(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $nome = mb_substr(trim(is_string($d['nome'] ?? null) ? $d['nome'] : ''), 0, self::MAX_NOME);
        if ($nome === '') {
            Json::erro('Digite seu nome para entrar.');
        }
        $token = bin2hex(random_bytes(16));
        Database::executar(
            'INSERT INTO coleta_participante (rodada_id, token, nome) VALUES (?, ?, ?)',
            [(int)$r['id'], $token, $nome]
        );
        Json::ok([
            'token' => $token,
            'nome' => $nome,
            'tema' => $r['tema'],
            'modo' => $r['modo'],
            'max_ideias' => (int)$r['max_ideias'],
            'max_votos' => (int)$r['max_votos'],
        ]);
    }

    /**
     * Resposta do quiz da cascata: uma sugestão de escolha OU de renúncia para
     * a pergunta ATIVA. O participante pode mandar várias de cada lado — o teto
     * (`max_ideias`) conta por (pergunta, tipo), senão quem gastasse tudo em
     * escolhas ficaria proibido de propor uma renúncia sequer.
     */
    public function resposta(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        if ($r['modo'] !== 'CASCATA') {
            Json::erro('Esta rodada é uma tempestade de ideias — envie pela tela de ideias.');
        }
        $p = $this->participante($r, $d);

        $ativa = $this->perguntaAtiva((int)$r['id']);
        if (!$ativa) {
            Json::erro('A pergunta foi fechada pela condução. Aguarde a próxima.', 409);
        }
        // O corpo declara qual pergunta o participante estava VENDO; a verdade é
        // a ativa. Se divergem (a condução avançou no meio da digitação), a
        // resposta seria gravada numa célula que a pessoa nunca leu — recusar é
        // mais honesto que aceitar em silêncio.
        if ((int)($d['pergunta_id'] ?? 0) !== (int)$ativa['id']) {
            Json::erro('A condução mudou de pergunta. Releia o contexto e envie de novo.', 409);
        }

        // Lista branca: qualquer outra coisa vinda do corpo vira ESCOLHA, pela
        // mesma razão que o nome sai do registro e nunca do corpo
        $tipo = ($d['tipo'] ?? '') === 'RENUNCIA' ? 'RENUNCIA' : 'ESCOLHA';
        $texto = mb_substr(trim(is_string($d['texto'] ?? null) ? $d['texto'] : ''), 0, self::MAX_TEXTO_CASCATA);
        if ($texto === '') {
            Json::erro('Escreva a sugestão antes de enviar.');
        }

        // Teto dentro do INSERT, por (pergunta, tipo): dois envios simultâneos
        // não furam a contagem
        $gravadas = Database::afetadas(
            'INSERT INTO coleta_item (planejamento_id, rodada_id, pergunta_id, tipo_resposta,
               ano, autor_id, autor_nome, participante_token, texto)
             SELECT ?, ?, ?, ?, ?, NULL, ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_item x
                              WHERE x.pergunta_id = ? AND x.participante_token = ?
                                AND x.tipo_resposta = ?) < ?',
            [
                (int)$r['planejamento_id'], (int)$r['id'], (int)$ativa['id'], $tipo,
                (int)$r['ano'], $p['nome'], $p['token'], $texto,
                (int)$ativa['id'], $p['token'], $tipo, (int)$r['max_ideias'],
            ]
        );
        if (!$gravadas) {
            $lado = $tipo === 'RENUNCIA' ? 'renúncia(s)' : 'resposta(s)';
            Json::erro("Você já enviou {$r['max_ideias']} {$lado} nesta pergunta.");
        }
        Json::ok(['ok' => true]);
    }

    public function ideia(): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        // Espelho da guarda de resposta(): sem ela, um participante do quiz da
        // cascata (mesmo PIN, mesma tabela) plantava "ideias" de 400 caracteres
        // fora de pergunta nenhuma — invisíveis para o condutor do quiz e
        // caindo direto na fila de triagem da Coleta
        if ($r['modo'] !== 'TEMPESTADE') {
            Json::erro('Esta sala é o quiz da cascata — responda pela tela da pergunta.');
        }
        $p = $this->participante($r, $d);

        $texto = mb_substr(trim(is_string($d['texto'] ?? null) ? $d['texto'] : ''), 0, self::MAX_TEXTO);
        if ($texto === '') {
            Json::erro('Escreva a ideia antes de enviar.');
        }
        $destino = in_array($d['destino_sugerido'] ?? '', ['CENARIO', 'PESTEL', 'PORTER', 'SWOT'], true)
            ? $d['destino_sugerido'] : 'NAO_SEI';
        // Texto igual já entra no mesmo grupo: assim o agrupamento automático e
        // o manual (arrastar uma sobre a outra) são o mesmo mecanismo
        $lider = $this->liderEquivalente((int)$r['id'], $texto);

        // O teto vai dentro do próprio INSERT: dois envios ao mesmo tempo não
        // conseguem furar a contagem, como fariam com COUNT + INSERT separados
        $gravadas = Database::afetadas(
            'INSERT INTO coleta_item (planejamento_id, rodada_id, ano, autor_id, autor_nome,
               participante_token, texto, destino_sugerido, agrupado_em_id)
             SELECT ?, ?, ?, NULL, ?, ?, ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_item x
                              WHERE x.rodada_id = ? AND x.participante_token = ?) < ?',
            [
                (int)$r['planejamento_id'], (int)$r['id'], (int)$r['ano'],
                $p['nome'], $p['token'], $texto, $destino, $lider,
                (int)$r['id'], $p['token'], (int)$r['max_ideias'],
            ]
        );
        if (!$gravadas) {
            Json::erro("Você já enviou {$r['max_ideias']} ideia(s) nesta rodada.");
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
        $p = $this->participante($r, $d);

        // A autoria é conferida por SELECT, não pelo número de linhas do UPDATE:
        // o PDO devolve linhas ALTERADAS, e salvar sem ter mudado o texto (abrir
        // o "✎", reler e confirmar) alterava zero linhas — o participante levava
        // "Não dá mais para editar esta ideia" no meio de uma oficina, como se a
        // ideia dele tivesse sido triada. O escopo continua sendo a guarda.
        // (No quiz da cascata, NOVO também significa "ainda não vinculada à
        // célula": o vínculo marca ACEITO, e a voz oficializada congela.)
        $minha = Database::um(
            "SELECT id, tipo_resposta FROM coleta_item
             WHERE id = ? AND rodada_id = ? AND participante_token = ? AND situacao = 'NOVO'",
            [$id, (int)$r['id'], $p['token']]
        );
        if (!$minha) {
            // Não é dela, já foi triada, ou a rodada virou: nada a corrigir.
            Json::erro('Não dá mais para editar esta ideia.', 409);
        }

        // O limite acompanha o rito: 255 na resposta do quiz, 400 na tempestade
        $eQuiz = $minha['tipo_resposta'] !== null;
        $limite = $eQuiz ? self::MAX_TEXTO_CASCATA : self::MAX_TEXTO;
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
                [$this->liderEquivalente((int)$r['id'], $texto, $id), $id]
            );
        }

        Json::ok(['ok' => true]);
    }

    /** As próprias ideias, para o participante conferir e corrigir. */
    public function minhas(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $p = $this->participante($r, $_GET);
        // pergunta_id e tipo_resposta servem ao quiz da cascata (a tela mostra
        // só as da pergunta ativa, com o selo do lado); na tempestade vêm nulos
        Json::ok(Database::todos(
            'SELECT id, texto, votos, situacao, pergunta_id, tipo_resposta FROM coleta_item
             WHERE rodada_id = ? AND participante_token = ? ORDER BY id',
            [(int)$r['id'], $p['token']]
        ));
    }

    /** Ideias abertas para votação, quando quem conduz liberar essa fase. */
    public function paraVotar(): void
    {
        $r = $this->rodadaPorPin((string)($_GET['pin'] ?? ''));
        $p = $this->participante($r, $_GET);
        // A estrela do quiz da cascata chega na fase própria, com teto POR
        // PERGUNTA; a votação da tempestade conta por rodada e furaria a regra
        if ($r['situacao'] !== 'ABERTA' || $r['votacao'] !== 'ABERTA' || $r['modo'] !== 'TEMPESTADE') {
            Json::ok(['votacao' => 'FECHADA', 'itens' => [], 'meus_votos' => 0]);
        }
        $itens = Database::todos(
            "SELECT i.id, i.texto, (v.id IS NOT NULL) AS votei
             FROM coleta_item i
             LEFT JOIN coleta_voto v ON v.item_id = i.id AND v.participante_token = ?
             WHERE i.rodada_id = ? AND i.situacao = 'NOVO'
             ORDER BY i.id",
            [$p['token'], (int)$r['id']]
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
        Json::ok(['votacao' => 'ABERTA', 'itens' => $itens, 'meus_votos' => $meus,
                  'max_votos' => (int)$r['max_votos']]);
    }

    /** Alterna o voto do participante numa ideia, respeitando o teto. */
    public function votar(int $id): void
    {
        $d = $this->corpo();
        $r = $this->rodadaAberta((string)($d['pin'] ?? ''));
        $p = $this->participante($r, $d);
        if ($r['votacao'] !== 'ABERTA' || $r['modo'] !== 'TEMPESTADE') {
            Json::erro('A votação não está aberta.');
        }
        // Ideia já tratada não recebe voto: seria gastar um voto em algo que o
        // participante nunca mais vê na lista
        if (!Database::um(
            "SELECT id FROM coleta_item WHERE id = ? AND rodada_id = ? AND situacao = 'NOVO'",
            [$id, (int)$r['id']]
        )) {
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
        // virar erro 500 pela chave única
        $gravou = Database::afetadas(
            'INSERT IGNORE INTO coleta_voto (item_id, rodada_id, participante_token)
             SELECT ?, ?, ?
             FROM DUAL WHERE (SELECT COUNT(*) FROM coleta_voto v
                              JOIN coleta_item i ON i.id = v.item_id AND i.situacao = \'NOVO\'
                              WHERE v.rodada_id = ? AND v.participante_token = ?) < ?',
            [$id, (int)$r['id'], $p['token'], (int)$r['id'], $p['token'], (int)$r['max_votos']]
        );
        if (!$gravou) {
            Json::erro("Você já usou seus {$r['max_votos']} voto(s). Toque num que já votou para trocar.");
        }
        $this->recontar($id);
        Json::ok(['votou' => true]);
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
    private function liderEquivalente(int $rodadaId, string $texto, ?int $excetoId = null): ?int
    {
        $alvo = self::normalizar($texto);
        foreach (Database::todos(
            "SELECT id, agrupado_em_id, texto FROM coleta_item
             WHERE rodada_id = ? AND situacao IN ('NOVO','SELECIONADO') ORDER BY id",
            [$rodadaId]
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
        return $p;
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
    private function rodadaPorPin(string $pin): array
    {
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

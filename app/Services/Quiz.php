<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Json;

/**
 * A sala do PROJETO: o que uma pergunta do quiz aponta, e como ela se descreve.
 *
 * Até a Fase 2 a sala tinha um RITO (`coleta_rodada.modo`) e a pergunta só
 * sabia apontar para uma célula da Cascata de Escolhas. Isso obrigava um PIN
 * por análise — o participante escaneando de novo no meio do encontro. Agora
 * quem manda é o ALVO da pergunta ATIVA: a mesma sala pergunta uma célula da
 * cascata, o cenário de 2026 e o quadrante Ameaças da SWOT, em sequência.
 *
 * Este serviço é a fonte única do que cada alvo significa — o lado da resposta,
 * o limite de texto, o rótulo curto e o contexto que o celular precisa ler. As
 * regras nasceram espalhadas entre controller e tela e, escritas duas vezes,
 * divergiriam na primeira análise nova.
 */
class Quiz
{
    /**
     * O lado da resposta, por alvo. Vazio = o alvo não tem lado: a categoria
     * JÁ é a pergunta ("me deem ameaças"), e um seletor no celular só somaria
     * um toque a cada envio.
     */
    public const LADOS = [
        'CASCATA' => ['ESCOLHA' => 'Escolha', 'RENUNCIA' => 'Renúncia'],
        'CENARIO' => ['SITUACAO_ATUAL' => 'Situação atual', 'TENDENCIA' => 'Tendência'],
        'FATOR'   => [],
        'LIVRE'   => [],
    ];

    /**
     * Limite de texto por alvo, imposto no SERVIDOR (o maxlength do campo é só
     * conforto da tela). A resposta que vira uma célula de decisão ou um fator
     * cabe em 255; a ideia solta da tempestade tem os 400 de sempre.
     */
    public const LIMITE_TEXTO = [
        'CASCATA' => 255,
        'CENARIO' => 255,
        'FATOR'   => 255,
        'LIVRE'   => 400,
    ];

    /**
     * Teto do roteiro de um encontro — e, por isso, da lista `alvos` de um
     * pedido. As 126 células da cascata mais os quadrantes de PESTEL/Porter/
     * SWOT de alguns anos passam de 200 sem esforço; é backstop contra roteiro
     * fugindo do controle, não limite de trabalho.
     */
    public const MAX_PERGUNTAS = 400;

    /** Categorias válidas por etapa — o mesmo mapa do FatorController. */
    public const CATEGORIAS = [
        'PESTEL' => ['POLITICO', 'ECONOMICO', 'SOCIAL', 'TECNOLOGICO', 'ECOLOGICO', 'LEGAL'],
        'PORTER' => ['RIVALIDADE', 'NOVOS_ENTRANTES', 'SUBSTITUTOS', 'PODER_FORNECEDORES', 'PODER_CLIENTES'],
        'SWOT'   => ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'],
    ];

    /** Rótulos legíveis das categorias, para o enunciado e o roteiro. */
    private const ROTULO_CATEGORIA = [
        'POLITICO' => 'Político', 'ECONOMICO' => 'Econômico', 'SOCIAL' => 'Social',
        'TECNOLOGICO' => 'Tecnológico', 'ECOLOGICO' => 'Ecológico', 'LEGAL' => 'Legal',
        'RIVALIDADE' => 'Rivalidade entre concorrentes',
        'NOVOS_ENTRANTES' => 'Ameaça de novos entrantes',
        'SUBSTITUTOS' => 'Ameaça de substitutos',
        'PODER_FORNECEDORES' => 'Poder dos fornecedores',
        'PODER_CLIENTES' => 'Poder dos clientes',
        'FORCA' => 'Forças', 'FRAQUEZA' => 'Fraquezas',
        'OPORTUNIDADE' => 'Oportunidades', 'AMEACA' => 'Ameaças',
    ];

    /** A tela de onde a sala está sendo conduzida — para o aviso de colisão. */
    private const TELA = [
        'CASCATA' => 'Cascata de Escolhas',
        'CENARIO' => 'Análise de Cenário',
        'FATOR'   => 'Diagnóstico',
        'LIVRE'   => 'Tempestade de ideias',
    ];

    // ---- Consultas ----

    /**
     * SELECT base do roteiro: os nomes de driver/horizonte/eixo vêm por LEFT
     * JOIN porque só o alvo CASCATA os tem — INNER JOIN sumiria com a pergunta
     * de cenário inteira, sem erro nenhum, que é o pior tipo de defeito.
     */
    private const SQL_BASE =
        'SELECT p.*, d.nome AS driver, e.nome AS eixo, h.nome AS horizonte,
                h.ano_inicio, h.ano_fim, h.tema AS horizonte_tema, h.objetivo
         FROM quiz_pergunta p
         LEFT JOIN driver d ON d.id = p.driver_id
         LEFT JOIN horizonte h ON h.id = p.horizonte_id
         LEFT JOIN eixo e ON e.id = p.eixo_id';

    /**
     * A pergunta que a sala responde agora. A fonte da verdade é a situação
     * ATIVA, nunca uma coluna na rodada: dois lugares dizendo "qual é a ativa"
     * dessincronizam na primeira corrida.
     */
    public static function ativa(int $rodadaId): ?array
    {
        return Database::um(
            self::SQL_BASE . " WHERE p.rodada_id = ? AND p.situacao = 'ATIVA'
             ORDER BY p.aberta_em DESC, p.id DESC",
            [$rodadaId]
        );
    }

    /** O roteiro inteiro, na ordem, com a contagem de sugestões por pergunta. */
    public static function roteiro(int $rodadaId): array
    {
        $linhas = Database::todos(
            "SELECT p.*, d.nome AS driver, e.nome AS eixo, h.nome AS horizonte,
                    h.ano_inicio, h.ano_fim, h.tema AS horizonte_tema, h.objetivo,
                    (SELECT COUNT(*) FROM coleta_item ci WHERE ci.pergunta_id = p.id) AS sugestoes
             FROM quiz_pergunta p
             LEFT JOIN driver d ON d.id = p.driver_id
             LEFT JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN eixo e ON e.id = p.eixo_id
             WHERE p.rodada_id = ?
             ORDER BY p.ordem, p.id",
            [$rodadaId]
        );
        foreach ($linhas as &$p) {
            $p['rotulo'] = self::rotulo($p);
        }
        return $linhas;
    }

    /**
     * "Pergunta N de M" para o caminho PÚBLICO — enxuto de propósito.
     *
     * O `roteiro()` completo faz três LEFT JOINs e um COUNT correlacionado por
     * pergunta, e monta o rótulo de cada uma. Isso roda a cada 4 segundos POR
     * PARTICIPANTE: trinta celulares num `php -S` single-threaded é a mesma
     * pressão que a regra "consulta periódica, nunca SSE" existe para evitar.
     * O celular só precisa de dois números.
     */
    public static function progressoDaRodada(int $rodadaId): array
    {
        $atual = null;
        $perguntas = Database::todos(
            'SELECT situacao FROM quiz_pergunta WHERE rodada_id = ? ORDER BY ordem, id',
            [$rodadaId]
        );
        foreach ($perguntas as $i => $p) {
            if ($p['situacao'] === 'ATIVA') {
                $atual = $i + 1;
            }
        }
        return ['atual' => $atual, 'total' => count($perguntas)];
    }

    /** "Pergunta N de M": N é a posição da ATIVA no roteiro; null sem ativa. */
    public static function progresso(array $roteiro): array
    {
        $atual = null;
        foreach (array_values($roteiro) as $i => $p) {
            if ($p['situacao'] === 'ATIVA') {
                $atual = $i + 1;
            }
        }
        return ['atual' => $atual, 'total' => count($roteiro)];
    }

    // ---- Como a pergunta se descreve ----

    /** Rótulo curto, para o roteiro e a faixa da sessão. */
    public static function rotulo(array $p): string
    {
        switch ($p['alvo_tipo'] ?? 'CASCATA') {
            case 'CENARIO':
                return "Cenário {$p['ano']}";
            case 'FATOR':
                return self::rotuloCategoria((string)$p['categoria'])
                    . " · {$p['etapa']} {$p['ano']}";
            case 'LIVRE':
                return (string)($p['enunciado'] ?: 'Tempestade de ideias');
            default:
                return ($p['driver'] ?? '?')
                    . ($p['eixo'] ? " · {$p['eixo']}" : ' · Síntese')
                    . ' (' . ($p['horizonte'] ?? '?') . ')';
        }
    }

    public static function rotuloCategoria(string $c): string
    {
        return self::ROTULO_CATEGORIA[$c] ?? $c;
    }

    /** O nome da tela de onde a pergunta é conduzida. */
    public static function tela(string $alvoTipo): string
    {
        return self::TELA[$alvoTipo] ?? 'Planejamento';
    }

    /**
     * O que o celular do participante recebe: título, contexto e os lados. É
     * aqui que o rito da sala passa a ser DA PERGUNTA — o participante nunca
     * vê o modo da rodada, só o que está sendo perguntado agora.
     */
    public static function paraSala(array $p): array
    {
        $tipo = (string)($p['alvo_tipo'] ?? 'CASCATA');
        $lados = [];
        foreach (self::LADOS[$tipo] ?? [] as $valor => $rot) {
            $lados[] = ['valor' => $valor, 'rotulo' => $rot];
        }
        return [
            'id' => (int)$p['id'],
            'alvo_tipo' => $tipo,
            'titulo' => self::titulo($p),
            'contexto' => self::contexto($p),
            'lados' => $lados,
            'max_texto' => self::LIMITE_TEXTO[$tipo] ?? 255,
            // O rótulo curto vai junto: a tela do participante mostra "sobre o
            // que estamos falando" mesmo quando o condutor não escreveu nada
            'rotulo' => self::rotulo($p),
        ];
    }

    /** A pergunta em si — a do condutor, quando ele a escreveu. */
    private static function titulo(array $p): string
    {
        $enunciado = trim((string)($p['enunciado'] ?? ''));
        if ($enunciado !== '') {
            return $enunciado;
        }
        switch ($p['alvo_tipo'] ?? 'CASCATA') {
            case 'CENARIO':
                return "O que descreve o cenário de {$p['ano']}?";
            case 'FATOR':
                return 'Quais ' . mb_strtolower(self::rotuloCategoria((string)$p['categoria']))
                    . " você vê para {$p['ano']}?";
            case 'LIVRE':
                return 'Quais ideias você tem sobre este tema?';
            default:
                return $p['eixo']
                    ? "Em “{$p['eixo']}”, o que escolhemos e do que abrimos mão?"
                    : 'Qual é a síntese desta escolha — e a sua renúncia?';
        }
    }

    /**
     * As linhas de apoio que tornam a pergunta respondível. Sem elas a célula
     * da cascata é abstrata demais para resposta útil, e o quadrante da SWOT
     * vira adivinhação.
     */
    private static function contexto(array $p): array
    {
        switch ($p['alvo_tipo'] ?? 'CASCATA') {
            case 'CENARIO':
                return [
                    ['rotulo' => 'Análise', 'valor' => "Cenário {$p['ano']}"],
                    ['rotulo' => 'Como responder',
                     'valor' => 'Escolha o lado: o que JÁ acontece (situação atual) '
                        . 'ou o que vem pela frente (tendência).'],
                ];
            case 'FATOR':
                return [
                    ['rotulo' => 'Análise', 'valor' => "{$p['etapa']} · {$p['ano']}"],
                    ['rotulo' => 'Categoria',
                     'valor' => self::rotuloCategoria((string)$p['categoria'])],
                ];
            case 'LIVRE':
                return [];
            default:
                $ctx = [
                    ['rotulo' => 'Linha base', 'valor' => (string)($p['driver'] ?? '')],
                    ['rotulo' => 'Horizonte',
                     'valor' => ($p['horizonte'] ?? '') . " · {$p['ano_inicio']}–{$p['ano_fim']}"],
                ];
                if ($p['horizonte_tema']) {
                    $ctx[] = ['rotulo' => 'Tema', 'valor' => "“{$p['horizonte_tema']}”"];
                }
                if ($p['objetivo']) {
                    $ctx[] = ['rotulo' => 'Objetivo', 'valor' => (string)$p['objetivo']];
                }
                $ctx[] = ['rotulo' => 'Abertura',
                          'valor' => $p['eixo'] ?: 'Síntese da célula'];
                return $ctx;
        }
    }

    // ---- Validação do que entra no roteiro ----

    /**
     * Os alvos pedidos, já normalizados em linhas prontas para o INSERT.
     *
     * O corpo declara o contexto da TELA (`alvo_tipo` mais o que aquele alvo
     * exige) e a lista `alvos` com as partes daquele contexto: eixos na
     * cascata, categorias no PESTEL/Porter/SWOT. Lista DECLARADA e vazia é
     * recusada, nunca "corrigida": o condutor desmarcou tudo, e assumir um
     * padrão abriria para a sala uma pergunta que ele acabou de tirar.
     */
    public static function validarAlvos(array $d, array $plan): array
    {
        $tipo = (string)($d['alvo_tipo'] ?? 'CASCATA');
        if (!isset(self::LADOS[$tipo])) {
            Json::erro('Tipo de pergunta inválido.');
        }
        // A lista é medida e limpa ANTES de qualquer consulta ao banco: cada
        // alvo custa um SELECT de validação e `php -S` é single-threaded, então
        // uma lista de 50 mil elementos segurava o servidor INTEIRO por doze
        // segundos — e o pedido terminava em erro de qualquer jeito. É o mesmo
        // amplificador de DoS que proíbe `usleep` na trava do login.
        $d['alvos'] = self::alvosCrus($d);
        $enunciado = mb_substr(trim(is_string($d['enunciado'] ?? null) ? $d['enunciado'] : ''), 0, 255);
        $enunciado = $enunciado !== '' ? $enunciado : null;

        $vazio = ['alvo_tipo' => $tipo, 'horizonte_id' => null, 'driver_id' => null,
                  'eixo_id' => null, 'ano' => null, 'etapa' => null, 'categoria' => null,
                  'enunciado' => $enunciado];

        switch ($tipo) {
            case 'CENARIO':
                return [array_merge($vazio, ['ano' => self::validarAno($d, $plan)])];

            case 'LIVRE':
                if ($enunciado === null) {
                    Json::erro('Escreva a pergunta que abre a tempestade.');
                }
                return [$vazio];

            case 'FATOR':
                $ano = self::validarAno($d, $plan);
                $etapa = (string)($d['etapa'] ?? '');
                if (!isset(self::CATEGORIAS[$etapa])) {
                    Json::erro('Etapa inválida.');
                }
                // Sem fallback implícito aqui: a categoria É a pergunta, e
                // adivinhar uma abriria para a sala algo que ninguém escolheu
                if (!$d['alvos']) {
                    Json::erro('Marque pelo menos uma categoria para perguntar.');
                }
                $linhas = [];
                foreach ($d['alvos'] as $categoria) {
                    if (!in_array($categoria, self::CATEGORIAS[$etapa], true)) {
                        Json::erro('Categoria inválida para esta etapa.');
                    }
                    $linhas[] = array_merge($vazio, ['ano' => $ano, 'etapa' => $etapa,
                                                     'categoria' => $categoria]);
                }
                return self::semRepetir($linhas);

            default: // CASCATA
                $base = self::validarCelulaBase($d, $plan);
                $linhas = [];
                foreach (self::lista($d, 'Marque pelo menos uma parte da célula para perguntar.') as $a) {
                    $eixoId = ($a === null || $a === '') ? null : (int)$a;
                    if ($eixoId !== null && !Database::um(
                        'SELECT id FROM eixo WHERE id = ? AND ativo = 1', [$eixoId]
                    )) {
                        Json::erro('Eixo inválido.');
                    }
                    $linhas[] = array_merge($vazio, $base, ['eixo_id' => $eixoId]);
                }
                return self::semRepetir($linhas);
        }
    }

    /**
     * A lista `alvos` do corpo, normalizada e limitada — sem tocar no banco.
     * Devolve `null` quando a chave não veio (o caminho legado, de um alvo só).
     */
    private static function alvosCrus(array $d): ?array
    {
        if (!array_key_exists('alvos', $d) || !is_array($d['alvos'])) {
            return null;
        }
        if (count($d['alvos']) > self::MAX_PERGUNTAS) {
            Json::erro('Marque menos alvos: o roteiro de um encontro tem limite.');
        }
        $alvos = [];
        foreach ($d['alvos'] as $a) {
            // Nada de coerção silenciosa de array/objeto: no caminho da cascata
            // ela viraria a síntese, abrindo para a sala algo que ninguém pediu
            if ($a !== null && !is_scalar($a)) {
                Json::erro('Alvo inválido.');
            }
            $chave = $a === null ? '' : (string)$a;
            if (!in_array($chave, $alvos, true)) {
                $alvos[] = $chave;
            }
        }
        return $alvos;
    }

    /**
     * A lista `alvos` do corpo, só para a CASCATA. Sem a chave, o alvo é um só
     * e implícito (a síntese da célula, no caminho legado); com a chave e
     * vazia, é recusa — o condutor desmarcou tudo, e assumir um padrão abriria
     * para a sala uma pergunta que ele acabou de tirar.
     */
    private static function lista(array $d, string $recusa): array
    {
        if ($d['alvos'] === null) {
            return [$d['eixo_id'] ?? null];
        }
        if (!$d['alvos']) {
            Json::erro($recusa);
        }
        return $d['alvos'];
    }

    /** Duplicata no mesmo pedido cairia no UNIQUE; some antes, em silêncio. */
    private static function semRepetir(array $linhas): array
    {
        $vistos = [];
        $saida = [];
        foreach ($linhas as $l) {
            $chave = json_encode([$l['horizonte_id'], $l['driver_id'], $l['eixo_id'],
                                  $l['ano'], $l['etapa'], $l['categoria']]);
            if (!isset($vistos[$chave])) {
                $vistos[$chave] = true;
                $saida[] = $l;
            }
        }
        return $saida;
    }

    /**
     * O ano da análise dentro do ciclo DESTE planejamento — o mesmo limite do
     * seletor de ano do diagnóstico. Ano fora do ciclo criaria uma pergunta
     * cuja resposta nenhuma tela mostra.
     */
    private static function validarAno(array $d, array $plan): int
    {
        $ano = (int)($d['ano'] ?? 0);
        $c = Database::um('SELECT ano_base, ano_fim FROM ciclo WHERE id = ?', [(int)$plan['ciclo_id']]);
        if (!$c || $ano < (int)$c['ano_base'] || $ano > (int)$c['ano_fim']) {
            Json::erro('Informe um ano dentro do ciclo do planejamento.');
        }
        return $ano;
    }

    /**
     * A célula pedida existe e pertence ao contexto: horizonte do ciclo DESTE
     * planejamento (o mesmo "H1" existe em cada ciclo) e driver ativo. A mesma
     * validação do CascataController::salvar — a pergunta não pode apontar
     * para onde a escolha não poderia ser gravada.
     */
    private static function validarCelulaBase(array $d, array $plan): array
    {
        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        $driverId = (int)($d['driver_id'] ?? 0);
        if (!Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        if (!Database::um('SELECT id FROM driver WHERE id = ? AND ativo = 1', [$driverId])) {
            Json::erro('Driver inválido.');
        }
        return ['horizonte_id' => $horizonteId, 'driver_id' => $driverId];
    }

    // ---- A sala única do projeto ----

    /**
     * A sala aberta do planejamento, com o rótulo de onde ela está.
     *
     * Uma aberta por planejamento, de qualquer rito: o PublicoController
     * resolve a rodada pelo PIN e a ideia manual da Coleta herda "a rodada
     * aberta" — duas abertas deixariam as duas regras cegas.
     */
    public static function salaAberta(int $planId): ?array
    {
        $r = Database::um(
            "SELECT * FROM coleta_rodada WHERE planejamento_id = ? AND situacao = 'ABERTA'",
            [$planId]
        );
        if (!$r) {
            return null;
        }
        if ($r['modo'] !== 'QUIZ') {
            $r['onde'] = self::tela('LIVRE');
            return $r;
        }
        // Sem pergunta ATIVA (o condutor usou "Encerrar" na pergunta, que é o
        // botão desenhado para isso), o rótulo cai para a ÚLTIMA pergunta da
        // rodada — onde o encontro parou. Um genérico "Planejamento" mandava o
        // condutor procurar uma tela que não existe.
        $p = self::ativa((int)$r['id']) ?: Database::um(
            'SELECT alvo_tipo FROM quiz_pergunta WHERE rodada_id = ?
             ORDER BY aberta_em IS NULL, aberta_em DESC, id DESC',
            [(int)$r['id']]
        );
        $r['onde'] = $p ? self::tela((string)$p['alvo_tipo']) : 'Planejamento';
        return $r;
    }

    /**
     * Libera o planejamento para abrir a sala na tela pedida.
     *
     * A colisão é PERGUNTA, não recusa: quem esqueceu de fechar a sala de
     * outra análise confirma o encerramento e segue. Mas sem a confirmação a
     * recusa continua — encerrar calado derrubaria a discussão de outra pessoa
     * por um clique distraído. O 409 leva o código que a tela reconhece, e não
     * só um texto: mensagem é para ler, código é para decidir.
     *
     * O encerra-e-abre é UM pedido de propósito: dois deixariam uma janela com
     * a sala fechada, e o segundo pode falhar depois de o primeiro ter
     * encerrado — o encontro ficaria sem sala nenhuma.
     */
    public static function liberarSala(int $planId, array $d, string $telaPedida): void
    {
        // Serializa por planejamento: encerrar-e-abrir é check-then-act, e sem
        // a trava dois condutores passavam os DOIS — o segundo encerrando a
        // sala que o primeiro acabou de abrir. É o mesmo recurso que o migrate
        // usa contra duas réplicas no check-then-act de `garantirColuna`.
        // O UNIQUE não resolve aqui: "uma ABERTA por planejamento" não é uma
        // chave (a coluna `situacao` repete em todas as encerradas).
        self::travarPlanejamento($planId);
        $aberta = self::salaAberta($planId);
        if (!$aberta) {
            return;
        }
        if (empty($d['confirmar_encerrar'])) {
            // A trava sai antes do 409: o pedido acabou aqui, e Json::erro
            // encerra a execução sem passar por nenhum `finally`.
            self::soltarPlanejamento($planId);
            Json::erro(
                "A sala está aberta em {$aberta['onde']}. Encerrar aquela discussão e abrir em {$telaPedida}?",
                409,
                'SALA_ABERTA'
            );
        }
        self::encerrarSala((int)$aberta['id']);
    }

    /**
     * A trava dura até o fim da conexão — e a conexão morre com a requisição,
     * porque o PDO não é persistente. Por isso quem abre a sala NÃO precisa
     * soltá-la no caminho feliz: o pedido termina em `Json::ok`, que também
     * encerra a execução.
     */
    private static function travarPlanejamento(int $planId): void
    {
        Database::um("SELECT GET_LOCK(CONCAT('sala_', ?), 10) AS travou", [$planId]);
    }

    private static function soltarPlanejamento(int $planId): void
    {
        Database::um("SELECT RELEASE_LOCK(CONCAT('sala_', ?)) AS soltou", [$planId]);
    }

    /**
     * Solta as vozes que apontavam para registros que vão deixar de existir.
     *
     * A ideia da TEMPESTADE volta a `SELECIONADO` — o mesmo que o "Desmarcar"
     * da triagem faz: ela segue na matriz, pronta para outro destino. A voz do
     * QUIZ volta a `NOVO`, que é a ÚNICA situação em que o autor consegue
     * corrigi-la de novo pelo celular (`PublicoController::editarIdeia` exige
     * NOVO). Dar SELECIONADO à voz do quiz a congelava: sem destino, sem
     * edição e ainda marcada "usada" na tela do participante.
     *
     * Existe como método porque são QUATRO caminhos que apagam um item de
     * cenário ou um fator (a tela da análise, a exclusão da ideia, a
     * reclassificação e a exclusão do fator) — escrita quatro vezes, a regra
     * divergiria na primeira mudança.
     */
    public static function soltarVozes(string $destinoTipo, array $destinoIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $destinoIds)));
        if (!$ids) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        Database::executar(
            "UPDATE coleta_item
                SET situacao = CASE WHEN origem = 'QUIZ' THEN 'NOVO' ELSE 'SELECIONADO' END,
                    destino_tipo = NULL, destino_id = NULL, triado_por = NULL, triado_em = NULL
              WHERE destino_tipo = ? AND destino_id IN ({$marcas})",
            array_merge([$destinoTipo], $ids)
        );
    }

    /** Encerra a rodada e o que estiver ativo nela. */
    public static function encerrarSala(int $rodadaId): void
    {
        Database::executar(
            "UPDATE quiz_pergunta SET situacao = 'ENCERRADA'
             WHERE rodada_id = ? AND situacao = 'ATIVA'",
            [$rodadaId]
        );
        Database::executar(
            "UPDATE coleta_rodada SET situacao = 'ENCERRADA', votacao = 'FECHADA', encerrada_em = NOW()
             WHERE id = ? AND situacao = 'ABERTA'",
            [$rodadaId]
        );
    }

    /** PIN de 6 dígitos livre; o UNIQUE do banco é a garantia final. */
    public static function pinLivre(): string
    {
        for ($i = 0; $i < 30; $i++) {
            $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (!Database::um('SELECT id FROM coleta_rodada WHERE pin = ?', [$pin])) {
                return $pin;
            }
        }
        Json::erro('Não foi possível gerar um PIN livre. Encerre rodadas antigas e tente de novo.');
    }
}

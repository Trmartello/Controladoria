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
     *
     * A exceção é o 🎤 da ETAPA INTEIRA (alvo FATOR com `categoria` nula): ali
     * os lados são as próprias categorias da etapa, e o celular escolhe em qual
     * a resposta entra — ver `ladosDe()`, que é quem deve ser consultado; esta
     * tabela é só a parte fixa.
     */
    public const LADOS = [
        'CASCATA' => ['ESCOLHA' => 'Escolha', 'RENUNCIA' => 'Renúncia'],
        'CENARIO' => ['SITUACAO_ATUAL' => 'Situação atual', 'TENDENCIA' => 'Tendência'],
        'FATOR'   => [],
        // O CRUZAMENTO não tem lado, tem PAR: o que ele pede a mais da pessoa
        // não é uma escolha entre dois rótulos, são dois fatores da SWOT. Isso
        // viaja em campos próprios (`fator_interno_id`/`fator_externo_id`), e
        // não aqui — lado é uma etiqueta no texto, par é o conteúdo.
        'CRUZAMENTO' => [],
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
        // A estratégia é um parágrafo do material, não uma frase de cartão:
        // "aproveitar a janela de proteína para expandir a Fábrica de Rações,
        // usando a força do maior negócio como âncora" já passa de 255.
        'CRUZAMENTO' => 400,
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

    /**
     * O que considerar em cada tópico — a orientação que o ⓘ da análise abre e
     * que o CELULAR passa a ler junto com a pergunta.
     *
     * Mora no servidor, e não no `Diag` do front, pelo mesmo motivo que a
     * pergunta: quem lê são DUAS telas (o condutor, pelo ⓘ, e o participante,
     * pelo celular) e uma segunda cópia divergiria na primeira revisão do
     * texto — deixando quem responde orientado por uma coisa e quem conduz por
     * outra. O front recebe o catálogo inteiro em `/api/me`.
     */
    public const ORIENTACAO_CATEGORIA = [
        // PESTEL — o macroambiente
        'POLITICO' => 'Mudanças na legislação, tributação e políticas setoriais; estabilidade '
            . 'política, incentivos e regulação do governo que afetam o setor.',
        'ECONOMICO' => 'Taxa de juros, inflação, poder de compra do consumidor, taxa de câmbio, '
            . 'crédito e crescimento — as forças econômicas que movem o mercado.',
        'SOCIAL' => 'Mudanças de comportamento, hábitos de consumo, demografia e valores culturais '
            . 'do público.',
        'TECNOLOGICO' => 'Automação, novas ferramentas, inteligência artificial e transformação '
            . 'digital que mudam como o setor opera.',
        'ECOLOGICO' => 'Clima, sustentabilidade, uso de recursos naturais, exigências ambientais e '
            . 'agenda ESG.',
        'LEGAL' => 'Leis trabalhistas, tributárias e setoriais, normas regulatórias, contratos e '
            . 'compliance que a empresa precisa cumprir.',
        // Porter — as 5 forças
        'RIVALIDADE' => 'Quem são os concorrentes diretos e como disputam o mercado (preço, '
            . 'qualidade, marca)?',
        'NOVOS_ENTRANTES' => 'É fácil ou difícil surgirem novos concorrentes? Que barreiras '
            . 'protegem o setor?',
        'SUBSTITUTOS' => 'Existem alternativas que resolvem a mesma dor do cliente de forma '
            . 'diferente?',
        'PODER_FORNECEDORES' => 'A empresa depende de poucos fornecedores críticos que ditam preço '
            . 'e prazo?',
        'PODER_CLIENTES' => 'Quão exigentes ou sensíveis a preço os clientes são, e quanto poder '
            . 'têm na negociação?',
        // SWOT — o que entra em cada quadrante
        'FORCA' => 'Diferenciais competitivos, processos bem consolidados, equipe qualificada, '
            . 'boa margem de lucro, tecnologia própria.',
        'FRAQUEZA' => 'Falta de padronização, dependência de pessoas-chave, sistemas defasados, '
            . 'alto custo operacional, comunicação ruidosa.',
        'OPORTUNIDADE' => 'Nichos de mercado não atendidos, novas tecnologias disponíveis, '
            . 'mudanças regulatórias favoráveis, expansão de demanda.',
        'AMEACA' => 'Entrada de concorrentes agressivos em preço, instabilidade econômica, '
            . 'escassez de matéria-prima, mudanças bruscas no comportamento do consumidor.',
        // Cenário
        'SITUACAO_ATUAL' => 'Onde o negócio está hoje: os fatos e números que descrevem a '
            . 'realidade atual — mercado, resultado, capacidade e posição competitiva.',
        'TENDENCIA' => 'Para onde o ambiente aponta: movimentos que já se desenham e devem se '
            . 'intensificar — mercado, tecnologia, comportamento do cliente e regulação.',
    ];

    /**
     * O catálogo das categorias que o CELULAR desenha quando a pergunta é da
     * etapa inteira: rótulo curto, cor e a dica de uma linha de cada cartão —
     * o mesmo cartão do formulário do fator (`Diag.campoCategoria`), para que
     * quem responde e quem conduz vejam a mesma coisa.
     *
     * É a cópia servida do `Diag.CATEGORIAS_ETAPA` / `CORES_QUADRANTE` /
     * `DICAS_QUADRANTE` do front. A tela do participante não carrega o `Diag`
     * (é uma página autônoma, sem login), e a cópia é vigiada pela bateria de
     * sistema (`provasEtapaNaSala` compara os dois catálogos) — divergência
     * vira vermelho, não duas telas dizendo coisas diferentes.
     * Os rótulos são os das COLUNAS (curtos, no plural na SWOT), não os do
     * enunciado (`ROTULO_CATEGORIA`, que fala "Ameaça de novos entrantes").
     */
    public const CATALOGO_CATEGORIA = [
        'PESTEL' => [
            'POLITICO'    => ['rotulo' => 'Político', 'cor' => '#7a3b8f', 'dica' => 'Governo · Regulação'],
            'ECONOMICO'   => ['rotulo' => 'Econômico', 'cor' => '#b08d4f', 'dica' => 'Juros · Câmbio · Renda'],
            'SOCIAL'      => ['rotulo' => 'Social', 'cor' => '#2c7fb8', 'dica' => 'Comportamento · Demografia'],
            'TECNOLOGICO' => ['rotulo' => 'Tecnológico', 'cor' => '#0d6e6e', 'dica' => 'Automação · Digital'],
            'ECOLOGICO'   => ['rotulo' => 'Ecológico', 'cor' => '#007a45', 'dica' => 'Clima · Recursos · ESG'],
            'LEGAL'       => ['rotulo' => 'Legal', 'cor' => '#8f3b3b', 'dica' => 'Leis · Compliance'],
        ],
        'PORTER' => [
            'RIVALIDADE'         => ['rotulo' => 'Rivalidade', 'cor' => '#8f3b3b', 'dica' => 'Concorrentes diretos'],
            'NOVOS_ENTRANTES'    => ['rotulo' => 'Novos Entrantes', 'cor' => '#b08d4f', 'dica' => 'Barreiras de entrada'],
            'SUBSTITUTOS'        => ['rotulo' => 'Substitutos', 'cor' => '#7a3b8f', 'dica' => 'Soluções alternativas'],
            'PODER_FORNECEDORES' => ['rotulo' => 'Poder dos Fornecedores', 'cor' => '#2c7fb8', 'dica' => 'Quem nos abastece'],
            'PODER_CLIENTES'     => ['rotulo' => 'Poder dos Clientes', 'cor' => '#0d6e6e', 'dica' => 'Quem compra de nós'],
        ],
        'SWOT' => [
            'FORCA'        => ['rotulo' => 'Forças', 'cor' => '#007a45', 'dica' => 'Interno · Ajuda'],
            'FRAQUEZA'     => ['rotulo' => 'Fraquezas', 'cor' => '#b08d4f', 'dica' => 'Interno · Atrapalha'],
            'OPORTUNIDADE' => ['rotulo' => 'Oportunidades', 'cor' => '#2c7fb8', 'dica' => 'Externo · Ajuda'],
            'AMEACA'       => ['rotulo' => 'Ameaças', 'cor' => '#8f3b3b', 'dica' => 'Externo · Atrapalha'],
        ],
    ];

    /**
     * A pergunta da ETAPA INTEIRA, quando o 🎤 é o do cabeçalho da análise e
     * não o de uma coluna. Pedido do cliente (2026-09-03): a sala escolhe o
     * quadrante no celular e lê ali o que considerar. O enunciado precisa
     * dizer que há uma escolha a fazer — sem isso a pessoa escreve e só então
     * descobre o cartão de categoria.
     */
    private const PERGUNTA_ETAPA = [
        'PESTEL' => 'Que fatores do ambiente afetam o nosso negócio em %d? Escolha a categoria.',
        'PORTER' => 'O que pesa nas cinco forças do nosso setor em %d? Escolha a força.',
        'SWOT'   => 'O que você vê de forças, fraquezas, oportunidades e ameaças em %d? Escolha o quadrante.',
    ];

    /**
     * A pergunta que a sala lê, por categoria. Não dá para montar isso a partir
     * do rótulo: as categorias do PESTEL são ADJETIVOS ("Político") e viravam
     * "Quais político você vê para 2026?" — o participante lê isso no celular,
     * e uma pergunta torta é uma resposta torta.
     */
    private const PERGUNTA_CATEGORIA = [
        // PESTEL — fatores do ambiente
        'POLITICO' => 'Que fatores POLÍTICOS afetam o nosso negócio em %d?',
        'ECONOMICO' => 'Que fatores ECONÔMICOS afetam o nosso negócio em %d?',
        'SOCIAL' => 'Que fatores SOCIAIS e culturais afetam o nosso negócio em %d?',
        'TECNOLOGICO' => 'Que fatores TECNOLÓGICOS afetam o nosso negócio em %d?',
        'ECOLOGICO' => 'Que fatores AMBIENTAIS afetam o nosso negócio em %d?',
        'LEGAL' => 'Que fatores LEGAIS e regulatórios afetam o nosso negócio em %d?',
        // Porter — o que pesa em cada força
        'RIVALIDADE' => 'O que pesa na RIVALIDADE entre os concorrentes em %d?',
        'NOVOS_ENTRANTES' => 'O que facilita ou dificulta a entrada de NOVOS CONCORRENTES em %d?',
        'SUBSTITUTOS' => 'Que produtos ou serviços podem SUBSTITUIR os nossos em %d?',
        'PODER_FORNECEDORES' => 'Onde os FORNECEDORES têm poder sobre nós em %d?',
        'PODER_CLIENTES' => 'Onde os CLIENTES têm poder sobre nós em %d?',
        // SWOT — os quatro quadrantes
        'FORCA' => 'Quais são as nossas FORÇAS em %d?',
        'FRAQUEZA' => 'Quais são as nossas FRAQUEZAS em %d?',
        'OPORTUNIDADE' => 'Que OPORTUNIDADES você enxerga para %d?',
        'AMEACA' => 'Que AMEAÇAS você enxerga para %d?',
    ];

    /** A tela de onde a sala está sendo conduzida — para o aviso de colisão. */
    private const TELA = [
        'CASCATA' => 'Cascata de Escolhas',
        'CENARIO' => 'Análise de Cenário',
        'FATOR'   => 'Diagnóstico',
        'CRUZAMENTO' => 'Cruzamentos (SWOT)',
        'LIVRE'   => 'Tempestade de ideias',
    ];

    /**
     * O alvo FATOR serve a TRÊS telas — quem diz qual é a etapa. Sem isto o
     * aviso de colisão mandava o condutor para um "Diagnóstico" que não é uma
     * aba do menu, e o selo não sabia para onde navegar.
     */
    private const TELA_ETAPA = [
        'PESTEL' => 'PESTEL',
        'PORTER' => 'Porter — 5 Forças',
        'SWOT'   => 'SWOT',
    ];

    /** A seção do menu que conduz cada alvo (o `data-secao` do shell). */
    private const SECAO = [
        'CASCATA' => 'cascata',
        'CENARIO' => 'cenario',
        'CRUZAMENTO' => 'cruzamentos',
        'LIVRE'   => 'coleta',
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

    /**
     * A última pergunta que esteve na sala e já foi fechada. É a janela da
     * ESTRELA: fechado o 🎤, o celular passa a votar no que acabou de ser dito,
     * e só nessa pergunta — a de antes já foi triada, e votar nela seria mexer
     * no que a condução deu por encerrado.
     *
     * Ordena por `aberta_em`, como `ativa()`: reabrir uma pergunta atualiza
     * essa data, então "a última que esteve na sala" é a última aberta, não a
     * última criada — o condutor volta a uma pergunta antiga o tempo todo.
     */
    public static function encerradaRecente(int $rodadaId): ?array
    {
        return Database::um(
            self::SQL_BASE . " WHERE p.rodada_id = ? AND p.situacao = 'ENCERRADA'
               AND p.aberta_em IS NOT NULL
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
            $p['secao'] = self::secaoDe($p);
            $p['tela'] = self::telaDe($p);
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

    /**
     * A pergunta é da ETAPA INTEIRA — o celular escolhe a categoria? É o alvo
     * FATOR sem categoria. Quem decide "tem lados / quais" para uma pergunta
     * concreta é `ladosDe()`, e é ela que as rotas devem consultar: a tabela
     * `LADOS` sozinha diz que FATOR não tem lado, e para esta pergunta isso é
     * mentira.
     */
    public static function escolheCategoria(array $p): bool
    {
        $categoria = $p['categoria'] ?? null;
        return ($p['alvo_tipo'] ?? '') === 'FATOR'
            && ($categoria === null || $categoria === '')
            && isset(self::CATALOGO_CATEGORIA[(string)($p['etapa'] ?? '')]);
    }

    /**
     * Os lados de UMA pergunta: o que o celular oferece para escolher antes de
     * escrever. Para a cascata e o cenário, a tabela fixa; para a etapa
     * inteira, as categorias da etapa — cada uma com cor, dica e a orientação
     * do ⓘ, porque é ao escolher o cartão que a pessoa lê o que considerar.
     */
    public static function ladosDe(array $p): array
    {
        if (self::escolheCategoria($p)) {
            $lados = [];
            foreach (self::CATALOGO_CATEGORIA[(string)$p['etapa']] as $valor => $c) {
                $lados[] = [
                    'valor' => $valor, 'rotulo' => $c['rotulo'], 'cor' => $c['cor'],
                    'dica' => $c['dica'],
                    'orientacao' => self::ORIENTACAO_CATEGORIA[$valor] ?? null,
                ];
            }
            return $lados;
        }
        $lados = [];
        foreach (self::LADOS[(string)($p['alvo_tipo'] ?? 'CASCATA')] ?? [] as $valor => $rot) {
            $lados[] = ['valor' => $valor, 'rotulo' => $rot];
        }
        return $lados;
    }

    /** Rótulo curto, para o roteiro e a faixa da sessão. */
    public static function rotulo(array $p): string
    {
        switch ($p['alvo_tipo'] ?? 'CASCATA') {
            case 'CENARIO':
                return "Cenário {$p['ano']}";
            case 'FATOR':
                if (self::escolheCategoria($p)) {
                    return (self::TELA_ETAPA[(string)$p['etapa']] ?? $p['etapa'])
                        . " {$p['ano']} · a análise inteira";
                }
                return self::rotuloCategoria((string)$p['categoria'])
                    . " · {$p['etapa']} {$p['ano']}";
            case 'CRUZAMENTO':
                return (Cruzamentos::BLOCOS[(string)$p['categoria']]['rotulo'] ?? 'Cruzamentos')
                    . " · {$p['ano']}";
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

    /** O nome da tela de onde a pergunta é conduzida, pelo tipo do alvo. */
    public static function tela(string $alvoTipo): string
    {
        return self::TELA[$alvoTipo] ?? 'Planejamento';
    }

    /** O mesmo, para uma pergunta concreta — FATOR depende da etapa dela. */
    public static function telaDe(array $p): string
    {
        return ($p['alvo_tipo'] ?? '') === 'FATOR'
            ? (self::TELA_ETAPA[(string)$p['etapa']] ?? 'Diagnóstico')
            : self::tela((string)($p['alvo_tipo'] ?? ''));
    }

    /**
     * A seção do menu onde esta pergunta é conduzida. O selo de cada análise
     * usa isto para dizer "a sala está em Porter · Rivalidade" com um atalho —
     * saber que a sala está longe sem poder ir até lá é meia informação.
     */
    public static function secaoDe(array $p): string
    {
        return ($p['alvo_tipo'] ?? '') === 'FATOR'
            ? strtolower((string)$p['etapa'])
            : (self::SECAO[(string)($p['alvo_tipo'] ?? '')] ?? 'painel');
    }

    /**
     * Duas perguntas apontam para o MESMO alvo? Serve para o toque no 🎤 da
     * categoria que já está na sala não fazer nada: reativar reabriria a
     * pergunta e zeraria o cronômetro dela.
     */
    public static function mesmoAlvo(array $a, array $b): bool
    {
        $colunas = ['alvo_tipo', 'horizonte_id', 'driver_id', 'eixo_id', 'ano', 'etapa', 'categoria'];
        // Em LIVRE o alvo É o enunciado (o UNIQUE `alvo_chave` inclui o MD5
        // dele). Sem compará-lo, duas tempestades diferentes davam "mesmo
        // alvo", a rota respondia `sem_mudanca` e a sala ficava na pergunta
        // velha — silenciosamente.
        if (($a['alvo_tipo'] ?? '') === 'LIVRE') {
            $colunas[] = 'enunciado';
        }
        foreach ($colunas as $c) {
            if ((string)($a[$c] ?? '') !== (string)($b[$c] ?? '')) {
                return false;
            }
        }
        return true;
    }

    /**
     * O que o celular do participante recebe: título, contexto e os lados. É
     * aqui que o rito da sala passa a ser DA PERGUNTA — o participante nunca
     * vê o modo da rodada, só o que está sendo perguntado agora.
     */
    public static function paraSala(array $p, ?int $planId = null): array
    {
        $tipo = (string)($p['alvo_tipo'] ?? 'CASCATA');
        $lados = self::ladosDe($p);
        // O alvo CRUZAMENTO é o único que manda REGISTROS para o celular: sem
        // as duas listas a pessoa não tem o que escolher. Elas descem para uma
        // tela sem login, e por isso vêm de `Cruzamentos::doQuadrante`, que
        // devolve só id e descrição — a decisão de o que expor mora lá, num
        // lugar só, e não espalhada em SELECTs por aí.
        //
        // O `planejamento_id` vem de QUEM CHAMA (a rodada), nunca da pergunta:
        // ela não o carrega, e derivá-lo por JOIN aqui faria toda pergunta de
        // toda análise pagar uma junção que só este alvo usa.
        $pares = [];
        if ($tipo === 'CRUZAMENTO' && $planId) {
            $bloco = Cruzamentos::BLOCOS[(string)($p['categoria'] ?? '')] ?? null;
            if ($bloco) {
                $pares = [
                    'interno' => [
                        'rotulo' => self::rotuloCategoria($bloco['interno']),
                        'itens' => Cruzamentos::doQuadrante($planId, (int)$p['ano'], $bloco['interno']),
                    ],
                    'externo' => [
                        'rotulo' => self::rotuloCategoria($bloco['externo']),
                        'itens' => Cruzamentos::doQuadrante($planId, (int)$p['ano'], $bloco['externo']),
                    ],
                ];
            }
        }
        return [
            'pares' => $pares ?: null,
            'id' => (int)$p['id'],
            'alvo_tipo' => $tipo,
            'titulo' => self::titulo($p),
            'contexto' => self::contexto($p),
            'lados' => $lados,
            'max_texto' => self::LIMITE_TEXTO[$tipo] ?? 255,
            // O rótulo curto vai junto: a tela do participante mostra "sobre o
            // que estamos falando" mesmo quando o condutor não escreveu nada
            'rotulo' => self::rotulo($p),
            // A orientação do ⓘ desce com a pergunta: quem responde pelo celular
            // não vê o ícone da análise, e sem ela "que fatores TECNOLÓGICOS
            // afetam o negócio?" fica no ar. É o mesmo texto que o condutor lê.
            // Na etapa inteira ela vai em CADA lado (`ladosDe`), e aparece ao
            // escolher o cartão — aqui fica nula de propósito.
            'orientacao' => self::ORIENTACAO_CATEGORIA[(string)($p['categoria'] ?? '')] ?? null,
            // O celular precisa saber que a escolha é OBRIGATÓRIA: com dois
            // lados ele marca o primeiro por padrão; com as categorias, marcar
            // uma sozinho mandaria a resposta para um quadrante que ninguém
            // escolheu — e o servidor recusa sem ela.
            'escolhe_categoria' => self::escolheCategoria($p),
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
                if (self::escolheCategoria($p)) {
                    return sprintf(self::PERGUNTA_ETAPA[(string)$p['etapa']], (int)$p['ano']);
                }
                $modelo = self::PERGUNTA_CATEGORIA[(string)$p['categoria']] ?? null;
                return $modelo
                    ? sprintf($modelo, (int)$p['ano'])
                    : 'O que você vê em ' . self::rotuloCategoria((string)$p['categoria'])
                        . " para {$p['ano']}?";
            case 'CRUZAMENTO':
                $bloco = Cruzamentos::BLOCOS[(string)$p['categoria']] ?? null;
                return $bloco
                    ? "Que {$bloco['pergunta']}"
                    : 'Que cruzamento você propõe?';
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
                if (self::escolheCategoria($p)) {
                    return [
                        ['rotulo' => 'Análise', 'valor' => "{$p['etapa']} · {$p['ano']}"],
                        ['rotulo' => 'Como responder',
                         'valor' => 'Toque na categoria em que a sua sugestão entra; a orientação '
                            . 'do que considerar aparece ao escolher.'],
                    ];
                }
                return [
                    ['rotulo' => 'Análise', 'valor' => "{$p['etapa']} · {$p['ano']}"],
                    ['rotulo' => 'Categoria',
                     'valor' => self::rotuloCategoria((string)$p['categoria'])],
                ];
            case 'CRUZAMENTO':
                $bloco = Cruzamentos::BLOCOS[(string)$p['categoria']] ?? null;
                return $bloco ? [
                    ['rotulo' => 'Análise', 'valor' => "Cruzamentos da SWOT · {$p['ano']}"],
                    ['rotulo' => 'Bloco', 'valor' => $bloco['rotulo']],
                    // O verbo é o que diz à sala QUE TIPO de estratégia se
                    // espera: com força e ameaça na mão, "defender" e "atacar"
                    // levam a respostas diferentes, e quem responde pelo celular
                    // não tem o material do bloco na frente.
                    ['rotulo' => 'Como responder',
                     'valor' => 'Escolha um fator de cada lado e escreva o que fazer — '
                        . "neste bloco a estratégia é {$bloco['verbo']}."],
                ] : [];
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
                    // O alvo VAZIO é a etapa inteira: a pergunta nasce sem
                    // categoria e o celular escolhe (`escolheCategoria`). É o
                    // 🎤 do cabeçalho da análise; o de cada coluna continua
                    // mandando a categoria dele.
                    if ($categoria === '') {
                        $linhas[] = array_merge($vazio, ['ano' => $ano, 'etapa' => $etapa]);
                        continue;
                    }
                    if (!in_array($categoria, self::CATEGORIAS[$etapa], true)) {
                        Json::erro('Categoria inválida para esta etapa.');
                    }
                    $linhas[] = array_merge($vazio, ['ano' => $ano, 'etapa' => $etapa,
                                                     'categoria' => $categoria]);
                }
                return self::semRepetir($linhas);

            case 'CRUZAMENTO':
                // Um alvo por BLOCO do TOWS, guardado na coluna `categoria`
                // (ver o esquema): a pergunta do cruzamento é sempre "deste
                // bloco, que par vocês propõem?". Sem fallback, pela mesma
                // razão do FATOR — adivinhar um bloco abriria para a sala algo
                // que ninguém escolheu.
                $ano = self::validarAno($d, $plan);
                if (!$d['alvos']) {
                    Json::erro('Marque pelo menos um bloco do cruzamento para perguntar.');
                }
                $linhas = [];
                foreach ($d['alvos'] as $bloco) {
                    if (!isset(Cruzamentos::BLOCOS[(string)$bloco])) {
                        Json::erro('Bloco de cruzamento inválido.');
                    }
                    $linhas[] = array_merge($vazio, ['ano' => $ano, 'categoria' => (string)$bloco]);
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
        // A etapa vem junto: `telaDe` a lê quando o alvo é FATOR, e sem a
        // coluna o fallback estourava warning de chave indefinida e rotulava
        // a sala de "Diagnóstico" — que não é uma aba do menu.
        $p = self::ativa((int)$r['id']) ?: Database::um(
            'SELECT alvo_tipo, etapa FROM quiz_pergunta WHERE rodada_id = ?
             ORDER BY aberta_em IS NULL, aberta_em DESC, id DESC',
            [(int)$r['id']]
        );
        $r['onde'] = $p ? self::telaDe($p) : 'Planejamento';
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
                $aberta['modo'] === 'QUIZ'
                    ? "A sala está aberta em {$aberta['onde']}. Encerrar aquela discussão e abrir em {$telaPedida}?"
                    : "A sala está na tempestade de ideias. Passar a MESMA sala para {$telaPedida}? "
                        . 'Ninguém precisa escanear o QR de novo, e as ideias já enviadas ficam guardadas.',
                409,
                'SALA_ABERTA'
            );
        }
        self::encerrarSala((int)$aberta['id']);
    }

    /**
     * Passa a sala da TEMPESTADE para o quiz **sem trocar o PIN**.
     *
     * Encerrar a tempestade e abrir uma rodada nova quebrava a promessa da aba
     * Sala — "um PIN para o encontro inteiro" —, e quebrava no pior momento: o
     * condutor recolhia ideias, tocava o 🎤 de uma célula da cascata e todo
     * mundo na sala ficava preso em "Esta rodada foi encerrada", sem aviso e sem
     * caminho, porque o celular está amarrado ao PIN que escaneou.
     *
     * Trocar `modo` é seguro porque o que separa os dois ritos NÃO é a rodada, e
     * sim `coleta_item.origem`: as ideias já enviadas continuam TEMPESTADE (e
     * seguem na Coleta), e as respostas do quiz nascem QUIZ. Os participantes
     * continuam registrados na mesma rodada, com o mesmo token. A votação da
     * tempestade é fechada: ela conta por rodada e não vale no rito novo.
     *
     * Devolve a rodada assumida, ou `null` quando não havia tempestade aberta —
     * aí o chamador cria a sala do zero, como sempre fez.
     */
    public static function assumirTempestade(int $planId, array $d, string $telaPedida): ?array
    {
        self::travarPlanejamento($planId);
        $aberta = self::salaAberta($planId);
        if (!$aberta) {
            self::soltarPlanejamento($planId);
            return null;
        }
        if ($aberta['modo'] === 'QUIZ') {
            // Sala de quiz já aberta é outro caso: quem chama aqui é o caminho
            // que só existe quando NÃO há sessão de quiz. Cai na regra de
            // sempre — pergunta e encerra.
            self::soltarPlanejamento($planId);
            self::liberarSala($planId, $d, $telaPedida);
            return null;
        }
        if (empty($d['confirmar_encerrar'])) {
            self::soltarPlanejamento($planId);
            Json::erro(
                "A sala está na tempestade de ideias. Passar a MESMA sala para {$telaPedida}? "
                    . 'Ninguém precisa escanear o QR de novo, e as ideias já enviadas ficam guardadas.',
                409,
                'SALA_ABERTA'
            );
        }
        Database::executar(
            "UPDATE coleta_rodada SET modo = 'QUIZ', votacao = 'FECHADA'
              WHERE id = ? AND situacao = 'ABERTA' AND modo = 'TEMPESTADE'",
            [(int)$aberta['id']]
        );
        $aberta['modo'] = 'QUIZ';
        return $aberta;
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
     * Solta as vozes que apontavam para registros que vão deixar de existir
     * SEM que o conteúdo deles tenha sido descartado — hoje, só a
     * reclassificação da ideia da tempestade (`ColetaController::reclassificar`),
     * que apaga o registro para criá-lo noutra análise.
     *
     * A ideia da TEMPESTADE volta a `SELECIONADO` — o mesmo que o "Desmarcar"
     * da triagem faz: ela segue na matriz, pronta para outro destino. A voz do
     * QUIZ volta a `NOVO`, que é a ÚNICA situação em que o autor consegue
     * corrigi-la de novo pelo celular (`PublicoController::editarIdeia` exige
     * NOVO). Dar SELECIONADO à voz do quiz a congelava: sem destino, sem
     * edição e ainda marcada "usada" na tela do participante.
     *
     * Os caminhos que EXCLUEM o registro (a tela da análise, a exclusão da
     * ideia, a exclusão do fator e a do cruzamento) usam `excluirVozes`, que
     * apaga a voz do quiz de vez e delega a este método só a tempestade. Até
     * 2026-09-02 todos passavam por aqui, e a voz voltava ao painel como
     * sugestão nova depois de o condutor tê-la excluído — ver lá o porquê.
     *
     * A voz volta com o TEXTO REDIGIDO, não com o original: quem aceitou
     * refinou a frase da sala, e apagar o registro não desfaz esse trabalho. O
     * `texto_tratado` (a redação, guardada no vínculo) é PROMOVIDO a `texto` e
     * some — deixá-lo por cima do original criaria duas verdades, e a correção
     * do participante pelo celular escreve em `texto` e ficaria invisível.
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
                    texto = CASE WHEN origem = 'QUIZ' AND texto_tratado IS NOT NULL
                                   AND texto_tratado <> '' THEN texto_tratado ELSE texto END,
                    texto_tratado = CASE WHEN origem = 'QUIZ' THEN NULL ELSE texto_tratado END,
                    destino_tipo = NULL, destino_id = NULL, triado_por = NULL, triado_em = NULL
              WHERE destino_tipo = ? AND destino_id IN ({$marcas})",
            array_merge([$destinoTipo], $ids)
        );
    }

    /**
     * Apaga DE VEZ as vozes do QUIZ amarradas a registros que estão sendo
     * excluídos — e solta, como sempre, as ideias da tempestade que apontavam
     * para eles.
     *
     * Até 2026-09-02 a exclusão passava por `soltarVozes`, e a voz voltava a
     * `NOVO`: o condutor apagava um fator na SWOT e a mesma frase reaparecia
     * no painel do Cenário como sugestão nova. Pior quando o registro tinha
     * atravessado de análise (`mudarDestino`): a voz voltava a uma pergunta de
     * onde ninguém a esperava mais. O cliente viu exatamente isso acontecer e
     * pediu a exclusão definitiva. Faz sentido: excluir o registro é o mesmo
     * gesto de condução do ✕ da ficha (`QuizController::excluirSugestao`) — a
     * voz foi lida, virou registro, e o registro foi descartado. Devolvê-la à
     * fila era refazer uma triagem que acabou de ser desfeita de propósito.
     *
     * A ideia da TEMPESTADE segue outra regra e continua em `soltarVozes`: ela
     * vive na matriz da Coleta, com quadrante e motivo próprios, e quem a
     * apagou foi o destino, não a triagem. Voltar a `SELECIONADO` é o que o
     * "Desmarcar" faz, e é na matriz que o triador decide o que fazer com ela.
     *
     * O grupo unificado sai inteiro (o "Usar" amarra líder e unidas ao mesmo
     * destino), os votos caem pela FK, e quem ainda apontava para uma linha
     * apagada por `agrupado_em_id` ou `unido_de_id` perde a marca — o mesmo
     * cuidado do `excluirSugestao`, para o desfazer nunca devolver uma ficha a
     * um líder que não existe.
     */
    public static function excluirVozes(string $destinoTipo, array $destinoIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $destinoIds)));
        if (!$ids) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $vozes = array_map('intval', array_column(Database::todos(
            "SELECT id FROM coleta_item
              WHERE origem = 'QUIZ' AND destino_tipo = ? AND destino_id IN ({$marcas})",
            array_merge([$destinoTipo], $ids)
        ), 'id'));
        if ($vozes) {
            $marcasVozes = implode(',', array_fill(0, count($vozes), '?'));
            Database::executar(
                "UPDATE coleta_item
                    SET agrupado_em_id = NULL, unido_de_id = NULL, unido_por = NULL, unido_em = NULL
                  WHERE agrupado_em_id IN ({$marcasVozes}) OR unido_de_id IN ({$marcasVozes})",
                array_merge($vozes, $vozes)
            );
            Database::executar("DELETE FROM coleta_item WHERE id IN ({$marcasVozes})", $vozes);
        }
        self::soltarVozes($destinoTipo, $ids);
    }

    /**
     * Leva as vozes de um registro para OUTRO — a mudança de análise que troca
     * de tabela (item de cenário ⇄ fator).
     *
     * É o contrário do `soltarVozes`, e a diferença é de propósito: ali o
     * registro morre e não há para onde levar as vozes; aqui o registro morre
     * mas o conteúdo dele CONTINUA, com outro id, noutra tabela. Devolvê-las à
     * fila neste caso seria pior que não fazer nada: o item já existe no
     * destino, e triar a ideia de novo criaria um SEGUNDO registro dizendo a
     * mesma coisa — duplicata que ninguém pediu, no meio de uma reunião.
     *
     * Nada de `situacao` muda: a voz continua ACEITA, com a mesma redação
     * (`texto_tratado` viaja na própria linha) e o mesmo autor. O que muda é
     * apenas para ONDE ela aponta.
     *
     * **A voz do quiz atravessa mesmo tendo vindo de uma pergunta de outro
     * alvo.** A guarda de `alvo_tipo` do `vincularSugestoes` existe para
     * impedir que alguém amarre ao seu item uma voz que é de outro — aqui não
     * há escolha nenhuma sendo feita: viajam exatamente as vozes que JÁ eram
     * deste registro. É por isso, porém, que o "solta quem saiu" dos dois
     * `vincularSugestoes` precisou ser apertado para só alcançar o que o painel
     * poderia ter oferecido: sem isso, a primeira edição do registro no destino
     * soltaria calada as vozes que acabaram de chegar.
     */
    public static function mudarDestino(string $deTipo, int $deId, string $paraTipo, int $paraId): void
    {
        Database::executar(
            'UPDATE coleta_item SET destino_tipo = ?, destino_id = ?
              WHERE destino_tipo = ? AND destino_id = ?',
            [$paraTipo, $paraId, $deTipo, $deId]
        );
    }

    /**
     * Guarda no vínculo a redação ATUAL do registro, para a voz voltar refinada
     * ao painel se ele for apagado (`soltarVozes` promove este campo).
     *
     * É chamado a cada salvamento, não só ao amarrar: editar o fator meses
     * depois sem tocar nas vozes deixaria a redação guardada velha, e a voz
     * voltaria com um texto que ninguém mais reconhece. `$lado` existe para a
     * cascata, cuja célula tem dois textos — devolver a renúncia com o texto da
     * escolha seria pior que devolver o original.
     *
     * A redação é do CARTÃO, e o cartão é o LÍDER: as absorvidas ficam com
     * `texto_tratado` nulo e voltam com o próprio texto. Sem a cláusula, o
     * "Usar" de um cartão unificado (que amarra o grupo inteiro) gravava a mesma
     * frase do condutor em todas, e o `soltarVozes` da exclusão do registro a
     * promovia a `texto`: N-1 respostas distintas, de autores diferentes,
     * apagadas sem histórico — justamente o que a unificação existe para
     * preservar ("cada linha guarda o próprio texto, autor, data e votos").
     */
    public static function guardarRedacao(
        string $destinoTipo, int $destinoId, string $texto, ?string $lado = null
    ): void {
        Database::executar(
            "UPDATE coleta_item SET texto_tratado = ?
             WHERE destino_tipo = ? AND destino_id = ? AND origem = 'QUIZ'
               AND agrupado_em_id IS NULL"
            . ($lado === null ? '' : ' AND tipo_resposta = ?'),
            $lado === null
                ? [$texto, $destinoTipo, $destinoId]
                : [$texto, $destinoTipo, $destinoId, $lado]
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

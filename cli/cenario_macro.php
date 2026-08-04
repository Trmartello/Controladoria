<?php

/**
 * Carga do cenário macroeconômico na Análise de Cenário (cenario_item).
 *
 *   php cli/cenario_macro.php --listar              # planejamentos disponíveis
 *   php cli/cenario_macro.php 7 2026                # PRÉVIA (não escreve nada)
 *   php cli/cenario_macro.php 7 2026 --aplicar      # grava os itens
 *
 * A prévia é o padrão de propósito: a carga escreve na análise de um
 * planejamento em uso, e a tela não tem "desfazer" — só exclusão item a item.
 *
 * Reexecutar é seguro: item cujo texto já existe naquele planejamento/ano é
 * ignorado (comparação por texto normalizado — minúsculas, sem acento e com
 * espaços colapsados), e os itens que já estavam na tela permanecem intactos.
 *
 * O conteúdo abaixo é uma FOTOGRAFIA de agosto/2026 (fontes no fim do arquivo).
 * Cenário envelhece: revise os números antes de aplicar em outro momento.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

/** Itens da carga, na ordem em que devem aparecer na tela. */
const ITENS = [
    'SITUACAO_ATUAL' => [
        'Juro em patamar recorde começando a ceder: o Copom sustentou a Selic em 15% a.a. '
        . 'durante todo o primeiro semestre e iniciou o ciclo de corte em 17/06/2026, para '
        . '14,25%. O Focus de 03/08/2026 projeta 13,75% no fim de 2026 e 12% em 2027, com '
        . 'IPCA de 5,03% (ainda acima do teto da meta), dólar a R$ 5,20 e PIB de 1,99%. '
        . 'Capital de giro e investimento seguem caros, e o associado chega à cooperativa '
        . 'já endividado a juro de mercado.',

        'Suíno vivo abaixo do custo de produção: em Santa Catarina o quilo recuou de R$ 5,05 '
        . 'para R$ 4,95 na integração e de R$ 5,10 para R$ 4,90 no interior (24/07/2026), '
        . 'contra custo de R$ 6,21/kg vivo apurado em junho (ICPSuíno/Embrapa). É o menor '
        . 'patamar real em duas décadas, com superoferta no mercado interno e margem '
        . 'negativa na ponta da produção.',

        'A exportação é o que sustenta o abate: o Brasil embarcou 392,2 mil toneladas de '
        . 'carne suína no 1º trimestre de 2026, alta de 16,5% sobre igual período de 2025 e '
        . 'volume histórico. O paradoxo do ano é recorde de embarque convivendo com preço '
        . 'interno abaixo do custo.',

        'A ração responde por 72,6% do custo do suíno e é de onde veio o alívio: o milho é '
        . 'negociado cerca de R$ 40/t abaixo do pico da última crise e o farelo de soja '
        . 'também recua, amparados por safra brasileira recorde. A alta dos grãos em julho, '
        . 'porém, já devolveu parte do ganho.',

        'Leite reage depois de oito meses de queda: o preço ao produtor subiu 17,6% no 1º '
        . 'trimestre de 2026 e a média Brasil chegou a R$ 2,3924/litro em março. As praças '
        . 'catarinenses acompanharam a recuperação, mas seguem abaixo do mesmo período de '
        . '2025, e a importação em alta mantém o déficit da balança em cerca de 2 bilhões '
        . 'de litros equivalentes.',

        'Avicultura entre a retomada e o risco sanitário: a China reabriu para o frango '
        . 'brasileiro, mas compra cerca de 30% menos em volume do que em 2024 e 2025; a '
        . 'projeção para 2026 é de 5,5 milhões de toneladas exportadas (+3,4%). A influenza '
        . 'aviária de alta patogenicidade avança no hemisfério norte, e o episódio do Rio '
        . 'Grande do Sul mostrou que um único foco embarga o país inteiro.',

        'Mercado regional aquecido: Santa Catarina opera com desemprego de 2,3% (mínima da '
        . 'série histórica), varejo em alta de 4,4% no 1º trimestre de 2026 contra 2,4% da '
        . 'média nacional, e o extremo Oeste com o maior avanço do emprego formal do estado '
        . '(+6,79% em 12 meses). A agropecuária catarinense cresceu 3,1% e a pecuária 4,4%. '
        . 'Sustenta supermercados, postos e agropecuárias — e pressiona salário e retenção '
        . 'de pessoas.',

        'Plano Safra 2026/27 melhora o funding: R$ 525,1 bilhões em crédito rural (+1,7%), '
        . 'com o custeio de cooperativas caindo de 14% para 12,5% a.a. e Prodecoop e '
        . 'Procap-Agro de 13,5% para 12% a.a., além de limites ampliados para singulares, '
        . 'centrais e federações. O setor alerta que o custeio encolheu em termos reais e '
        . 'que o seguro rural ficou sem solução.',
    ],

    'TENDENCIA' => [
        'O alívio do juro é de 2027, não de 2026: com o Focus projetando 13,75% ao fim deste '
        . 'ano e 12% em 2027, o custo de capital só melhora de verdade no próximo ciclo. O '
        . 'ano corrente pede alongamento de dívida e escalonamento de investimento, não '
        . 'ampliação de exposição.',

        'Ciclo suíno em ajuste de oferta: com o vivo abaixo do custo, a tendência é redução '
        . 'de alojamento e saída de produtores independentes, reequilibrando a oferta ao '
        . 'longo de 2027. Quem atravessar o vale ganha participação, e a margem virá de '
        . 'conversão alimentar, sanidade e escala — não de preço.',

        'Custo de ração comportado é a principal defesa da margem: a safra 2026/27 é '
        . 'projetada em novo recorde (185,6 milhões de toneladas de soja e 147,5 milhões de '
        . 'milho). O clima e a migração do produtor para o barter, por falta de crédito, são '
        . 'os riscos que podem virar essa mesa.',

        'Lácteos com recuperação moderada e teto à vista: oferta interna em nível recorde, '
        . 'importações crescendo e as cotas de leite em pó e queijos previstas no acordo '
        . 'Mercosul–UE limitam a alta. A disputa por matéria-prima entre indústrias tende a '
        . 'se acirrar na entressafra.',

        'Sanidade deixa de ser custo e vira condição de acesso a mercado: com HPAI ativa no '
        . 'hemisfério norte e embargos aplicados por região, biosseguridade, rastreabilidade '
        . 'e velocidade de resposta a foco passam a decidir quais unidades continuam '
        . 'exportando.',

        'Protecionismo redesenha o destino das proteínas: cotas e sobretaxas chinesas, '
        . 'tarifas norte-americanas e o acordo Mercosul–UE deslocam preço e volume de um '
        . 'mercado para outro dentro do mesmo ano. Diversificar destino e mix passa a pesar '
        . 'mais do que crescer volume.',

        'A cooperativa assume o papel de financiadora do associado: crédito rural menor em '
        . 'termos reais e juro de mercado alto empurram o produtor para o barter e para o '
        . 'prazo da cooperativa. A consequência é pressão sobre o capital de giro e '
        . 'elevação do risco de crédito da carteira de associados.',

        'Consumo regional resiliente, com o mix migrando: emprego e renda em Santa Catarina '
        . 'sustentam o varejo, mas inflação acima do teto e juro alto favorecem marca '
        . 'própria, promoção e atacarejo. A disputa passa a ser por frequência de compra, e '
        . 'não por ticket médio.',

        'Energia e mão de obra como custos estruturais: energia elétrica e combustível '
        . 'seguem pressionando o custo industrial e logístico, o que encurta o payback da '
        . 'geração própria; e o pleno emprego no Oeste mantém a pressão salarial, '
        . 'transformando retenção de pessoas e sucessão rural em gargalo de execução do '
        . 'planejamento.',
    ],
];

/** Normaliza para comparar: minúsculas, sem acento, espaços colapsados. */
function chaveTexto(string $t): string
{
    // A extensão intl não está na imagem — a tabela substitui o Normalizer,
    // como em PublicoController::normalizar().
    $t = strtr(mb_strtolower(trim($t), 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
        'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
    return preg_replace('/\s+/u', ' ', $t);
}

/** str_pad conta bytes: "2027–2035" tem travessão e desalinharia a coluna. */
function coluna(string $t, int $largura): string
{
    return $t . str_repeat(' ', max(1, $largura - mb_strlen($t, 'UTF-8')));
}

function listar(): void
{
    $linhas = Database::todos(
        "SELECT p.id, c.nome AS ciclo, c.ano_base, c.ano_fim, p.escopo,
                COALESCE(n.nome, 'Corporativo') AS negocio,
                (SELECT COUNT(*) FROM cenario_item ci WHERE ci.planejamento_id = p.id) AS itens
         FROM planejamento p
         JOIN ciclo c ON c.id = p.ciclo_id
         LEFT JOIN negocio n ON n.id = p.negocio_id
         ORDER BY c.ano_base DESC, negocio"
    );
    if (!$linhas) {
        echo "cenario: nenhum planejamento cadastrado.\n";
        return;
    }
    echo coluna('ID', 5) . coluna('CICLO', 26) . coluna('ESCOPO', 14)
        . coluna('NEGÓCIO', 26) . "ITENS DE CENÁRIO\n";
    foreach ($linhas as $l) {
        echo coluna((string)$l['id'], 5)
            . coluna("{$l['ciclo']} ({$l['ano_base']}-{$l['ano_fim']})", 26)
            . coluna((string)$l['escopo'], 14)
            . coluna((string)$l['negocio'], 26)
            . $l['itens'] . "\n";
    }
}

$args = array_slice($argv, 1);
$aplicar = in_array('--aplicar', $args, true);
$args = array_values(array_filter($args, fn($a) => $a[0] !== '-'));

if (in_array('--listar', array_slice($argv, 1), true)) {
    listar();
    exit(0);
}

$planId = (int)($args[0] ?? 0);
$ano = (int)($args[1] ?? date('Y'));
if ($planId <= 0) {
    fwrite(STDERR, "Uso: php cli/cenario_macro.php [--listar | <planejamento_id> [ano] [--aplicar]]\n");
    exit(2);
}

$plan = Database::um(
    "SELECT p.id, c.nome AS ciclo, c.ano_base, c.ano_fim,
            COALESCE(n.nome, 'Corporativo') AS negocio
     FROM planejamento p
     JOIN ciclo c ON c.id = p.ciclo_id
     LEFT JOIN negocio n ON n.id = p.negocio_id
     WHERE p.id = ?",
    [$planId]
);
if (!$plan) {
    fwrite(STDERR, "cenario: planejamento {$planId} não encontrado (use --listar).\n");
    exit(1);
}
// O seletor de ano da tela é limitado a [ano_base, ano_fim]: item gravado fora
// da faixa existiria no banco sem jamais aparecer para o usuário.
if ($ano < (int)$plan['ano_base'] || $ano > (int)$plan['ano_fim']) {
    fwrite(STDERR, "cenario: ano {$ano} fora do ciclo {$plan['ciclo']} "
        . "({$plan['ano_base']}-{$plan['ano_fim']}); a tela não exibiria os itens.\n");
    exit(1);
}

echo "cenario: {$plan['negocio']} · {$plan['ciclo']} · ano {$ano}"
    . ($aplicar ? "\n" : "  [PRÉVIA — nada será gravado]\n");

$existentes = [];
foreach (Database::todos(
    'SELECT descricao FROM cenario_item WHERE planejamento_id = ? AND ano = ?',
    [$planId, $ano]
) as $l) {
    $existentes[chaveTexto($l['descricao'])] = true;
}

$novos = 0;
$repetidos = 0;
foreach (ITENS as $tipo => $textos) {
    $rotulo = $tipo === 'SITUACAO_ATUAL' ? 'Situação Atual' : 'Tendências';
    echo "\n== {$rotulo}\n";
    // Continua a numeração do que já está na tela, em vez de disputar a ordem
    $ordem = (int)(Database::um(
        'SELECT COALESCE(MAX(ordem), 0) AS o FROM cenario_item
         WHERE planejamento_id = ? AND ano = ? AND tipo = ?',
        [$planId, $ano, $tipo]
    )['o'] ?? 0);

    foreach ($textos as $texto) {
        $resumo = mb_substr($texto, 0, 88, 'UTF-8') . '…';
        if (isset($existentes[chaveTexto($texto)])) {
            echo "  = já existe: {$resumo}\n";
            $repetidos++;
            continue;
        }
        $ordem++;
        $novos++;
        if ($aplicar) {
            Database::executar(
                'INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao)
                 VALUES (?, ?, ?, ?, ?)',
                [$planId, $ano, $tipo, $ordem, $texto]
            );
            echo "  + gravado ({$ordem}): {$resumo}\n";
        } else {
            echo "  + gravaria ({$ordem}): {$resumo}\n";
        }
        // Evita gravar duas vezes o mesmo texto se ele se repetir na lista
        $existentes[chaveTexto($texto)] = true;
    }
}

echo "\ncenario: {$novos} item(ns) " . ($aplicar ? 'gravado(s)' : 'a gravar')
    . ", {$repetidos} já presente(s).\n";
if (!$aplicar && $novos) {
    echo "cenario: repita com --aplicar para gravar.\n";
}
exit(0);

/*
 * Fontes da fotografia de agosto/2026:
 * - Boletim Focus de 03/08/2026 (IPCA 5,03%; Selic 13,75% em 2026 e 12% em 2027;
 *   dólar R$ 5,20; PIB 1,99%) e decisão do Copom de 17/06/2026 (Selic 14,25%).
 * - ICPSuíno/Embrapa (custo de R$ 6,21/kg vivo em junho; alimentação 72,6%) e
 *   cotações de suíno vivo em SC de 24/07/2026.
 * - ABPA/Secex: 392,2 mil t de carne suína no 1º tri/2026 (+16,5%); projeção de
 *   5,5 milhões de t de frango em 2026 (+3,4%) e retorno da China.
 * - Cepea e Epagri/Cepa: leite ao produtor +17,6% no 1º tri/2026, média Brasil de
 *   R$ 2,3924/l em março; recuperação das praças catarinenses desde fevereiro.
 * - Conab e projeções de mercado para a safra 2026/27 (soja 185,6 mi t;
 *   milho 147,5 mi t).
 * - Plano Safra 2026/27: R$ 525,1 bi; custeio de cooperativas 14% -> 12,5% a.a.;
 *   Prodecoop e Procap-Agro 13,5% -> 12% a.a.
 * - IBGE/PNAD, PMC e Caged via Seplan-SC e Facisc: desemprego de 2,3%, varejo
 *   catarinense +4,4% no 1º tri/2026, emprego formal do extremo Oeste +6,79%.
 */

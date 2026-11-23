<?php

/**
 * Provas do crescimento POR OMISSÃO da proposta (pedido do cliente,
 * 2026-11-18): a Receita cresce IPCA + PIB + crescimento desejado, as Despesas
 * com Pessoal crescem só o percentual de pessoas, e nenhuma outra linha cresce
 * por omissão. O que foi digitado numa linha manda.
 *
 * Pura: sem banco. Os números são de MÃO (R$ 100 mi × 1,12 = R$ 112 mi), porque
 * uma prova que refizesse a conta com a mesma fórmula passaria sem provar nada.
 *
 *   php testes/projecao_crescimento.php
 */

require_once __DIR__ . '/../app/Services/Projecao.php';

use App\Services\Projecao;

$ok = 0;
$bad = [];
function prova(string $nome, bool $cond, string $extra = ''): void
{
    global $ok, $bad;
    if ($cond) {
        $ok++;
        echo "  ✓ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    } else {
        $bad[] = $nome . ($extra ? " — {$extra}" : '');
        echo "  ✗ {$nome}" . ($extra ? " — {$extra}" : '') . "\n";
    }
}
function perto(float $a, float $b, float $tol = 0.001): bool
{
    return abs($a - $b) <= $tol;
}

/** Uma DRE mínima: receita, custos, pessoal e outra despesa (despesa é NEGATIVA). */
$dre = static fn () => [
    ['codigo' => '1.1', 'nome' => 'Receita de Vendas', 'nivel' => 2, 'percentual' => 0, 'projecao' => 100.0],
    ['codigo' => '4.1', 'nome' => 'Custos das Vendas', 'nivel' => 2, 'percentual' => 0, 'projecao' => -60.0],
    ['codigo' => '8.5', 'nome' => 'Despesas com Pessoal', 'nivel' => 2, 'percentual' => 0, 'projecao' => -10.0],
    ['codigo' => '8.1', 'nome' => 'Despesas com Vendas', 'nivel' => 2, 'percentual' => 0, 'projecao' => -5.0],
];
$por = static function (array $linhas): array {
    $o = [];
    foreach ($linhas as $l) {
        $o[$l['codigo']] = $l;
    }
    return $o;
};

// ── Quanto cada linha cresce ────────────────────────────────────────────────
$c = Projecao::crescimentosPadrao(['IPCA' => 5, 'PIB' => 6, 'CRESCIMENTO' => 1, 'PESSOAS' => 5.5]);
prova('a Receita cresce IPCA + PIB + desejado: 5 + 6 + 1 = 12%', perto($c['1.1'], 12.0), json_encode($c));
prova('as Despesas com Pessoal crescem SÓ o percentual de pessoas (5,5%)', perto($c['8.5'], 5.5), json_encode($c));
prova('e mais nenhuma linha cresce por omissão', array_keys($c) === ['1.1', '8.5'], json_encode(array_keys($c)));

$c = Projecao::crescimentosPadrao(['IPCA' => 5, 'PIB' => 6]);
prova('sem o desejado, a Receita cresce IPCA + PIB (11%), e pessoal não cresce nada', perto($c['1.1'], 11.0) && !isset($c['8.5']), json_encode($c));
$c = Projecao::crescimentosPadrao(['PESSOAS' => 4]);
prova('só o percentual de pessoas lançado NÃO faz a Receita crescer', !isset($c['1.1']) && perto($c['8.5'], 4.0), json_encode($c));
prova('sem premissa nenhuma não há crescimento (premissa ausente não é 0%)', Projecao::crescimentosPadrao([]) === []);
$c = Projecao::crescimentosPadrao(['IPCA' => 5, 'PIB' => 6, 'CRESCIMENTO' => 0]);
prova('o desejado lançado como 0 conta (é decisão, não ausência): a Receita fica em 11%', perto($c['1.1'], 11.0));
$c = Projecao::crescimentosPadrao(['IPCA' => -2, 'PIB' => 1]);
prova('premissa negativa também soma: −2 + 1 = −1%', perto($c['1.1'], -1.0), json_encode($c));

// ── A proposta que nasce disso ──────────────────────────────────────────────
$l = $por(Projecao::aplicar($dre(), [], ['1.1' => 12.0, '8.5' => 5.5]));
prova('Receita: R$ 100 mi × 1,12 = R$ 112 mi, de origem PREMISSA', perto($l['1.1']['proposta'], 112.0) && $l['1.1']['proposta_origem'] === 'PREMISSA',
    json_encode([$l['1.1']['proposta'], $l['1.1']['proposta_origem']]));
prova('Despesas com Pessoal: −R$ 10 mi × 1,055 = −R$ 10,55 mi (a despesa AUMENTA, fica mais negativa)',
    perto($l['8.5']['proposta'], -10.55), (string)$l['8.5']['proposta']);
// Custo e deduções ACOMPANHAM A RECEITA (decisão do cliente, 2026-11-18): a
// margem bruta de 2026 se mantém. As demais despesas ficam na projeção (diretriz:
// "sem previsão de crescimento").
prova('o custo acompanha a receita: −R$ 60 mi × 1,12 = −R$ 67,2 mi (margem bruta mantida)',
    perto($l['4.1']['proposta'], -67.2) && $l['4.1']['proposta_origem'] === 'PREMISSA'
    && ($l['4.1']['proposta_regra'] ?? null) === 'RECEITA', json_encode([$l['4.1']['proposta'], $l['4.1']['proposta_regra'] ?? null]));
prova('as demais despesas ficam na projeção, de origem PROJECAO',
    perto($l['8.1']['proposta'], -5.0) && $l['8.1']['proposta_origem'] === 'PROJECAO');
prova('a linha guarda o percentual que a gerou (para a tela mostrar de onde veio)',
    perto((float)$l['1.1']['proposta_padrao'], 12.0) && perto((float)$l['4.1']['proposta_padrao'], 12.0)
    && $l['8.1']['proposta_padrao'] === null);

// O que foi digitado MANDA.
$lanc = ['1.1' => ['entrada' => 'PERCENTUAL', 'percentual' => 15.0, 'valor' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0, '8.5' => 5.5]));
prova('um reajuste digitado na Receita (acima do piso) vence o crescimento por omissão (15%, e não 12%)',
    perto($l['1.1']['proposta'], 115.0) && $l['1.1']['proposta_origem'] === 'PERCENTUAL' && $l['1.1']['proposta_padrao'] === null,
    json_encode([$l['1.1']['proposta'], $l['1.1']['proposta_origem']]));
$lanc = ['8.5' => ['entrada' => 'VALOR', 'valor' => -9.0, 'percentual' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0, '8.5' => 5.5]));
prova('desde 2026-11-23 a linha Pessoal se LANÇA de novo: o digitado (−9) vale mesmo com percentual de pessoas',
    perto($l['8.5']['proposta'], -9.0) && $l['8.5']['proposta_origem'] === 'VALOR' && !isset($l['8.5']['proposta_travada']),
    json_encode([$l['8.5']['proposta'], $l['8.5']['proposta_origem']]));

// ── O mínimo das Despesas com Pessoal (pedido do cliente, 2026-11-23) ───────
// Mínimo = pessoas × custo com o reajuste. Abaixo dele, grava e marca o ⚠.
$m = $por(Projecao::marcarMinimoPessoal($l, 5.5));
prova('−9 contra o mínimo de −10,55 (projeção × 1,055): marcado abaixo do mínimo, e o valor fica',
    $m['8.5']['abaixo_minimo_pessoal'] === true && perto($m['8.5']['minimo_pessoal'], -10.55) && perto($m['8.5']['proposta'], -9.0));
$lanc = ['8.5' => ['entrada' => 'VALOR', 'valor' => -12.0, 'percentual' => null]];
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), $lanc, ['8.5' => 5.5]), 5.5));
prova('−12 (contratação, reajuste maior): acima do mínimo, sem ⚠', $m['8.5']['abaixo_minimo_pessoal'] === false);
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), [], ['8.5' => 5.5]), 5.5));
prova('o padrão (−10,55) é o próprio mínimo: sem ⚠', $m['8.5']['abaixo_minimo_pessoal'] === false && perto($m['8.5']['proposta'], -10.55));
$lanc = ['8.5' => ['entrada' => 'PERCENTUAL', 'percentual' => 2.0, 'valor' => null]];
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), $lanc, ['8.5' => 5.5]), 5.5));
prova('reajuste digitado de 2% (abaixo dos 5,5%) também sai com ⚠', $m['8.5']['abaixo_minimo_pessoal'] === true);
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), [], ['8.5' => 2.0]), 5.5, true));
prova('na filial, o PADRÃO abaixo do mínimo (quadro planejado menor no negócio) não acusa: só o lançado',
    $m['8.5']['abaixo_minimo_pessoal'] === false);
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), [], ['8.5' => 2.0]), 5.5, false));
prova('o total do negócio no modo filial é comparado sempre (soma das filiais abaixo do mínimo)',
    $m['8.5']['abaixo_minimo_pessoal'] === true);
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), $lanc, ['8.5' => 5.5]), null, true, ['colaboradores' => 0]));
prova('filial sem quadro não tem mínimo', $m['8.5']['minimo_pessoal'] === null && $m['8.5']['abaixo_minimo_pessoal'] === false);
$m = $por(Projecao::marcarMinimoPessoal(Projecao::aplicar($dre(), [], ['8.5' => 5.5]), 5.5, true, ['colaboradores' => 4]));
prova('a filial com quadro mostra o custo por pessoa (projeção −10 ÷ 4 = −2,5)',
    $m['8.5']['quadro_pessoal'] === 4 && perto((float)$m['8.5']['custo_por_pessoa'], -2.5));

// O padrão na PROPORÇÃO do quadro planejado: 90 pessoas planejadas contra 100
// hoje, sem folha (reajuste puro): (1,055 × 0,9 − 1) = −5,05%.
[$cresc, , $min] = Projecao::crescimentosDoNegocio(2029, ['PESSOAS' => 5.5], -10.0,
    ['folha' => null, 'calc' => ['colaboradores' => 90.0, 'colaboradores_base' => 100.0]]);
prova('quadro planejado 90 de 100: o padrão da 8.5 cai na proporção (1,055 × 0,9 − 1 = −5,05%)',
    perto($cresc['8.5'], -5.05) && perto($min['percentual'], -5.05) && perto($min['percentual_atual'], 5.5) && perto($min['fator_quadro'], 0.9),
    json_encode($min));
[$cresc, , $min] = Projecao::crescimentosDoNegocio(2029, ['PESSOAS' => 5.5], -10.0,
    ['folha' => null, 'calc' => ['colaboradores' => 100.0, 'colaboradores_base' => 100.0]]);
prova('com o quadro atual o fator é 1 e o padrão é o reajuste', perto($cresc['8.5'], 5.5) && perto($min['fator_quadro'], 1.0));
[$cresc, , $min] = Projecao::crescimentosDoNegocio(2029, [], -10.0, null);
prova('sem premissa de pessoas não há mínimo', !isset($cresc['8.5']) && $min === null);

// Com a folha (a conta da diretriz): a folha reajustada e o PPR andam no fator, e
// os benefícios já vêm pelo quadro planejado. Percentual "atual" = o total ÷ fator.
$folha = ['pessoal_ate' => 100.0, 'vale_ate' => 10.0, 'premio_ate' => 0.0, 'ppr_ate' => 0.0,
    'salario_despesa' => 5.0, 'salario_custo' => 0.0, 'mes_ate' => 10, 'ano' => 2026];
$calc90 = ['anual' => ['total' => 9.0]];
$d1 = Projecao::pessoalDaDiretriz(-100.0, $folha, ['anual' => ['total' => 10.0]], 5.5, 1.5, 1.0);
$d9 = Projecao::pessoalDaDiretriz(-100.0, $folha, $calc90, 5.5, 1.5, 0.9);
prova('com folha, 90% do quadro dá 90% do total (base, PPR e benefícios proporcionais)',
    perto(-$d9['proposta'], 0.9 * -$d1['proposta']) && perto($d9['percentual_atual'], $d1['percentual']),
    json_encode([$d1['proposta'], $d9['proposta'], $d1['percentual'], $d9['percentual_atual']]));

// ── O piso do faturamento (2026-11-19; trava desde 2026-11-21) ─────────────
// A Receita não pode ficar abaixo de IPCA + PIB + crescimento desejado; acima
// pode. Lançamento abaixo NÃO VALE: a linha fica no piso e sai marcada
// (`piso_aplicado`, com o valor recusado em `lancado_abaixo`).
$l = $por(Projecao::aplicar($dre(), [], ['1.1' => 12.0]));
prova('pela premissa a Receita fica no piso, e a linha guarda o piso (12%)',
    $l['1.1']['piso_aplicado'] === false && perto((float)$l['1.1']['piso_percentual'], 12.0) && perto($l['1.1']['proposta'], 112.0));
$lanc = ['1.1' => ['entrada' => 'PERCENTUAL', 'percentual' => 3.0, 'valor' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0]));
prova('reajuste de 3% com piso de 12%: não vale, a linha fica no piso (112) e guarda o recusado (103)',
    $l['1.1']['piso_aplicado'] === true && perto($l['1.1']['proposta'], 112.0) && $l['1.1']['proposta_origem'] === 'PREMISSA'
    && perto($l['1.1']['lancado_abaixo'], 103.0), json_encode([$l['1.1']['proposta'], $l['1.1']['proposta_origem']]));
prova('e o custo acompanha a receita do PISO, não a do lançamento recusado',
    perto($l['4.1']['proposta'] / $l['4.1']['projecao'], 1.12), (string)$l['4.1']['proposta']);
$lanc = ['1.1' => ['entrada' => 'VALOR', 'valor' => 130.0, 'percentual' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0]));
prova('valor acima do piso (130 contra 112): o negócio se desafia mais, e o lançado vale',
    $l['1.1']['piso_aplicado'] === false && perto($l['1.1']['proposta'], 130.0) && $l['1.1']['proposta_origem'] === 'VALOR');
$lanc = ['1.1' => ['entrada' => 'VALOR', 'valor' => 99.0, 'percentual' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0], 0.1));
prova('no formato de 2027 a comparação é com a CBS dentro: 99 sem CBS = 110 com ela, abaixo de 112 → piso',
    $l['1.1']['piso_aplicado'] === true && perto($l['1.1']['proposta'], 112.0), (string)$l['1.1']['proposta']);
$lanc = ['1.1' => ['entrada' => 'VALOR', 'valor' => 108.0, 'percentual' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0], 0.1));
prova('e 108 sem CBS = 120 com ela fica acima do piso: o lançado não é comparado sem a CBS',
    $l['1.1']['piso_aplicado'] === false && perto($l['1.1']['proposta'], 120.0), (string)$l['1.1']['proposta']);
$lanc = ['1.1' => ['entrada' => 'PERCENTUAL', 'percentual' => -5.0, 'valor' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => -8.0]));
prova('com crescimento desejado negativo o piso é negativo: −5% acima de −8% vale',
    $l['1.1']['piso_aplicado'] === false && perto($l['1.1']['proposta'], 95.0));
$l = $por(Projecao::aplicar($dre(), [], []));
prova('sem premissas não há piso', !isset($l['1.1']['piso_aplicado']));

// Sem premissas, tudo como sempre foi.
$l = $por(Projecao::aplicar($dre(), [], []));
prova('sem premissas a linha vale a projeção, como sempre valeu',
    perto($l['1.1']['proposta'], 100.0) && $l['1.1']['proposta_origem'] === 'PROJECAO' && perto($l['8.5']['proposta'], -10.0));

// ── O que a leitura do painel diz ───────────────────────────────────────────
// Cresc. Total = proposta da Receita ÷ projeção − 1; Cresc. Real = Total − IPCA − PIB.
$linhas = Projecao::aplicar(
    array_merge($dre(), [
        ['codigo' => '1', 'nome' => 'RECEITA BRUTA', 'nivel' => 1, 'percentual' => 0, 'projecao' => 100.0],
        ['codigo' => '4', 'nome' => 'CUSTOS', 'nivel' => 1, 'percentual' => 0, 'projecao' => -60.0],
    ]),
    [],
    ['1.1' => 12.0]
);
$p = Projecao::premissas($linhas, ['IPCA' => 5, 'PIB' => 6, 'CRESCIMENTO' => 1]);
prova('Cresc. Total = 12% (a soma dos três) e Cresc. Real = 1% (exatamente o desejado)',
    perto($p['CRESC_TOTAL'], 12.0) && perto($p['CRESC_REAL'], 1.0), json_encode([$p['CRESC_TOTAL'], $p['CRESC_REAL']]));
prova('% Custos = 12%: o custo cresce com a receita (margem bruta mantida)', perto($p['PCT_CUSTOS'], 12.0), json_encode($p['PCT_CUSTOS']));

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
if ($bad) {
    foreach ($bad as $b) {
        echo "  ✗ {$b}\n";
    }
}
exit($bad ? 1 : 0);

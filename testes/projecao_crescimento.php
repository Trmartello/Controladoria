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
$lanc = ['1.1' => ['entrada' => 'PERCENTUAL', 'percentual' => 3.0, 'valor' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0, '8.5' => 5.5]));
prova('um reajuste digitado na Receita vence o crescimento por omissão (3%, e não 12%)',
    perto($l['1.1']['proposta'], 103.0) && $l['1.1']['proposta_origem'] === 'PERCENTUAL' && $l['1.1']['proposta_padrao'] === null,
    json_encode([$l['1.1']['proposta'], $l['1.1']['proposta_origem']]));
$lanc = ['8.5' => ['entrada' => 'VALOR', 'valor' => -9.0, 'percentual' => null]];
$l = $por(Projecao::aplicar($dre(), $lanc, ['1.1' => 12.0, '8.5' => 5.5]));
prova('um valor digitado em Pessoal vence o percentual de pessoas', perto($l['8.5']['proposta'], -9.0) && $l['8.5']['proposta_origem'] === 'VALOR');

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

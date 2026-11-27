<?php

/**
 * Provas das DIRETRIZES DO PLANEJAMENTO 2027 na Projeção (pedido do cliente,
 * 2026-11-18, com o documento de 16/11/2026): os valores fixos, a despesa de
 * pessoal com o PPR de 1,5 salário, o custo que acompanha a receita, e o Painel
 * fazendo a MESMA conta da tela.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/diretriz_2027.php
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';
require_once __DIR__ . '/../app/Core/Versao.php';
require_once __DIR__ . '/../app/Core/Json.php';
require_once __DIR__ . '/../app/Core/Auth.php';
require_once __DIR__ . '/../app/Services/Bloqueio.php';
require_once __DIR__ . '/../app/Services/Projecao.php';
require_once __DIR__ . '/../app/Services/Metas.php';
require_once __DIR__ . '/../app/Services/DesafioCota.php';
require_once __DIR__ . '/../app/Services/DesafioDrivers.php';
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Services/CargaFolha.php';
require_once __DIR__ . '/../app/Services/DesafioAjuste.php';
require_once __DIR__ . '/../app/Controllers/ProjecaoController.php';

use App\Core\Database;
use App\Services\CargaFolha;
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
function perto(float $a, float $b, float $tol = 0.01): bool
{
    return abs($a - $b) <= $tol;
}

// ── 1. O documento ──────────────────────────────────────────────────────────
$d = Projecao::diretriz(2027);
prova('a diretriz de 2027 é a do documento: pessoal 5,50 fixo; IPCA 4,50 e PIB agro 1,50 sugeridos',
    $d['premissas'] === ['PESSOAS' => 5.5] && $d['sugeridas'] === ['IPCA' => 4.5, 'PIB' => 1.5],
    json_encode([$d['premissas'], $d['sugeridas']]));
prova('vale R$ 500, prêmio assiduidade R$ 200, prêmio metas R$ 500, PPR de 1,5 salário',
    $d['beneficios'] === ['ALIMENTACAO' => 500.0, 'ASSIDUIDADE' => 200.0, 'RESULTADO' => 500.0, 'PPR_SALARIOS' => 1.5]
    && empty($d['maximos']));
// O PPR é DIRETRIZ FIXA desde 2026-11-26 ("nenhum negócio poderia alterar o número de
// salários de PPR; é uma decisão corporativa") — antes era um teto que o negócio baixava.
prova('PPR_SALARIOS em 2027 é fixo (1,5), sem teto; nos outros anos é livre',
    in_array('PPR_SALARIOS', Projecao::BENEFICIOS, true) && Projecao::fixadaPelaDiretriz(2027, 'PPR_SALARIOS') === 1.5
    && Projecao::maximoDaDiretriz(2027, 'PPR_SALARIOS') === null && Projecao::fixadaPelaDiretriz(2028, 'PPR_SALARIOS') === null);
prova('sem digitar, o PPR de 2027 vale a diretriz (1,5)', Projecao::beneficiosEfetivos(2027, [])['PPR_SALARIOS'] === 1.5);
prova('um PPR digitado antes (1 salário) não vale mais: fica 1,5', Projecao::beneficiosEfetivos(2027, ['PPR_SALARIOS' => 1.0])['PPR_SALARIOS'] === 1.5);
prova('nem acima (2 guardado vale 1,5)', Projecao::beneficiosEfetivos(2027, ['PPR_SALARIOS' => 2.0])['PPR_SALARIOS'] === 1.5);
prova('os benefícios fixos continuam por cima do digitado', Projecao::beneficiosEfetivos(2027, ['ALIMENTACAO' => 600.0])['ALIMENTACAO'] === 500.0);
prova('crescimento real, IPCA e PIB NÃO são fixos (cada negócio edita); pessoal é',
    Projecao::fixadaPelaDiretriz(2027, 'CRESCIMENTO') === null && Projecao::fixadaPelaDiretriz(2027, 'IPCA') === null
    && Projecao::fixadaPelaDiretriz(2027, 'PIB') === null && Projecao::fixadaPelaDiretriz(2027, 'PESSOAS') === 5.5);
prova('o IPCA digitado pelo negócio vale; o PESSOAL da diretriz vale por cima; só no ano dela',
    Projecao::premissasEfetivas(2027, ['IPCA' => 6.0, 'PESSOAS' => 9.0, 'CRESCIMENTO' => 2.0])
        == ['IPCA' => 6.0, 'PIB' => 1.5, 'PESSOAS' => 5.5, 'CRESCIMENTO' => 2.0]
    && Projecao::premissasEfetivas(2028, ['IPCA' => 6.0]) === ['IPCA' => 6.0]);
prova('sem digitado, IPCA e PIB são os sugeridos pela diretriz (4,5 e 1,5)',
    Projecao::premissasEfetivas(2027, []) == ['IPCA' => 4.5, 'PIB' => 1.5, 'PESSOAS' => 5.5]);
prova('quantidade e previsão de colaboradores continuam do negócio',
    Projecao::fixadaPelaDiretriz(2027, 'COLABORADORES') === null && Projecao::fixadaPelaDiretriz(2027, 'PCT_RESULTADO') === null);

// ── 2. A despesa de pessoal, com números de mão ─────────────────────────────
// Linha 8.5 projetada em −1.000; nas contas, 800 de pessoal até o corte, com 80 de
// vale, 40 de prêmio e 80 de PPR (25% da folha). Folha do mês 50 na despesa e 50 no
// custo. Benefícios de 2027 no ano: 120.
$folha = ['pessoal_ate' => 800, 'vale_ate' => 80, 'premio_ate' => 40, 'ppr_ate' => 80,
    'salario_despesa' => 50, 'salario_custo' => 50, 'mes_ate' => 10, 'ano' => 2026];
$calc = ['anual' => ['total' => 120.0]];
$p = Projecao::pessoalDaDiretriz(-1000.0, $folha, $calc, 5.5, 1.5);
// base = 1000 × 75% = 750 → × 1,055 = 791,25; benefícios = 120 × 50% = 60; PPR = 100 × 1,055 × 1,5 = 158,25.
prova('base: tira de 2026 a parte de vale, prêmio e PPR (25%) e reajusta 5,5% → 791,25', perto($p['base_reajustada'], 791.25), (string)$p['base_reajustada']);
prova('vale e prêmios de 2027 só pela parte da folha que é despesa (50% de 120 = 60)', perto($p['beneficios'], 60.0), (string)$p['beneficios']);
prova('PPR = folha do mês (despesa + custo) × 1,055 × 1,5 = 158,25, e 1/12 por mês', perto($p['ppr'], 158.25) && perto($p['ppr_mensal'], 158.25 / 12));
prova('proposta da 8.5 = −(791,25 + 60 + 158,25) = −1.009,50, ou +0,95% sobre a projeção',
    perto($p['proposta'], -1009.5) && perto($p['percentual'], 0.95), "{$p['proposta']} / {$p['percentual']}");
prova('sem folha carregada não há conta (vale o reajuste puro)', Projecao::pessoalDaDiretriz(-1000.0, null, $calc, 5.5, 1.5) === null);
prova('sem despesa de pessoal (Usina) também não', Projecao::pessoalDaDiretriz(0.0, $folha, $calc, 5.5, 1.5) === null);
[$cresc, $det] = Projecao::crescimentosDoNegocio(2027, [], -1000.0, ['folha' => $folha, 'calc' => $calc]);
prova('no ano da diretriz, a receita cresce IPCA + PIB (6%) e a 8.5 cresce o % da conta de pessoal',
    perto($cresc['1.1'], 6.0) && perto($cresc['8.5'], 0.95) && $det !== null, json_encode($cresc));
[$cresc] = Projecao::crescimentosDoNegocio(2028, ['PESSOAS' => 4.0], -1000.0, ['folha' => $folha, 'calc' => $calc]);
prova('em ano SEM diretriz (sem PPR definido) a 8.5 cresce só o reajuste digitado', perto($cresc['8.5'], 4.0) && !isset($cresc['1.1']), json_encode($cresc));
// Em 2028 o negócio digita o PPR: 1 salário. Base 750 × 1,04 = 780; benefícios 60;
// PPR = 100 × 1,04 × 1 = 104 → 944, ou −5,6% sobre os 1.000 da projeção.
[$cresc, $det] = Projecao::crescimentosDoNegocio(2028, ['PESSOAS' => 4.0], -1000.0,
    ['folha' => $folha, 'calc' => $calc, 'ppr_salarios' => 1.0]);
prova('com o campo PPR digitado (1 salário em 2028), a conta usa o número digitado: PPR = 104, 8.5 = −944',
    $det && perto($det['ppr'], 104.0) && perto($det['proposta'], -944.0) && $det['ppr_salarios'] === 1.0, json_encode([$det['ppr'] ?? null, $det['proposta'] ?? null]));
[$cresc, $det] = Projecao::crescimentosDoNegocio(2027, [], -1000.0, ['folha' => $folha, 'calc' => $calc, 'ppr_salarios' => 1.5]);
prova('em 2027 o campo vem da diretriz (1,5) e a conta é a de sempre (158,25)', $det && perto($det['ppr'], 158.25));

// ── 3. Com o banco: Pecuária, e o Painel igual à tela ───────────────────────
$ids = [];
foreach (Database::todos('SELECT id, cod_negocio FROM negocio') as $n) {
    $ids[$n['cod_negocio']] = (int)$n['id'];
}
$f = Database::um('SELECT * FROM negocio_folha WHERE negocio_id = ? AND ano = 2026', [$ids['2']]);
prova('a folha de outubro da Pecuária está carregada (salários R$ 375.803, sem custo)',
    $f && (float)$f['salario_despesa'] == 375803.0 && (float)$f['salario_custo'] == 0.0 && (int)$f['mes_ate'] === 10);
$ctrl = new App\Controllers\ProjecaoController();
$montar = new ReflectionMethod($ctrl, 'montar');
$montar->setAccessible(true);
$fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
$ano = (int)$fech['ano'];
$corte = (int)$fech['mes_fechado_ate'];
$m = $montar->invoke($ctrl, $ids['2'], $ano, $corte, 0);
prova('Pecuária: PPR de 2027 = R$ 375.803 × 1,055 × 1,5 = R$ 594.708',
    $m['pessoal_diretriz'] && perto($m['pessoal_diretriz']['ppr'], 375803 * 1.055 * 1.5, 1), (string)round($m['pessoal_diretriz']['ppr'] ?? 0));
$l = array_column($m['linhas'], null, 'codigo');
prova('a linha 8.5 da tela é a da conta (origem PREMISSA, regra PESSOAL) — não se lança mais nela',
    ($l['8.5']['proposta_origem'] === 'PREMISSA' && ($l['8.5']['proposta_regra'] ?? '') === 'PESSOAL'
        && perto($l['8.5']['proposta'], $m['pessoal_diretriz']['proposta'], 1))
    || $l['8.5']['proposta_origem'] === 'FILIAIS',
    json_encode([$l['8.5']['proposta'], $l['8.5']['proposta_origem'], $m['pessoal_diretriz']['proposta']]));

$painel = Projecao::resumoDosNegocios(array_values($ids), $ano, $corte, $ano + 1, ['2.3', '4.1', '8.5', '18']);
$divergem = [];
foreach ($ids as $cod => $id) {
    if (!isset($painel[$id])) {
        continue;
    }
    $t = array_column($montar->invoke($ctrl, $id, $ano, $corte, 0)['linhas'], 'proposta', 'codigo');
    foreach (['2.3', '4.1', '8.5', '18'] as $c) {
        if (abs(($painel[$id]['proposta'][$c] ?? 0) - ($t[$c] ?? 0)) > 1) {
            $divergem[] = "{$cod}/{$c}: " . round($painel[$id]['proposta'][$c] ?? 0) . ' ≠ ' . round($t[$c] ?? 0);
        }
    }
}
prova('o Painel faz a MESMA conta da tela (custo, pessoal e resultado) nos ' . count($painel) . ' negócios',
    count($painel) >= 10 && !$divergem, implode(' | ', $divergem));

$pdo = Database::conn();

// ── 3b. A conta reduzida 3227 (acerto do PPR) fora da base ──────────────────
// Pedido do cliente, 2026-11-23: "estornando esses 1 milhão de PPR da conta
// reduzida 3227. A base tem que ir para 44 milhões e pouco. Faça isso para todos
// os negócios e filiais". De mão: projeção −1.000; contas 780 até o corte, vale 80,
// prêmio 40, PPR líquido 60 = provisão 80 − estorno 20 (a 3227 com +20).
// Sem a 3227: base 1.020, contas 800, peças 80 + 40 + 80 = 25%.
$folha3 = ['pessoal_ate' => 780, 'vale_ate' => 80, 'premio_ate' => 40, 'ppr_ate' => 60,
    'salario_despesa' => 50, 'salario_custo' => 50, 'mes_ate' => 10, 'ano' => 2026];
$p3 = Projecao::pessoalDaDiretriz(-1000.0, $folha3, $calc, 5.5, 1.5, 1.0, ['realizado' => 20.0]);
// base = 1.020 × 75% = 765 × 1,055 = 807,075; + 60 + 158,25 = 1.025,325.
prova('3227: a base sai sem o estorno (1.000 + 20 = 1.020) e tira vale, prêmio e a PROVISÃO (80) — 25%',
    perto($p3['projecao_sem_ppr'], -1020.0) && perto($p3['ppr_provisao_2026'], 80.0) && perto($p3['parte_2026'], 0.25)
    && perto($p3['pecas_2026'], 255.0) && perto($p3['base_reajustada'], 807.075), json_encode($p3));
prova('3227: 8.5 = −1.025,325 (+2,53% sobre a projeção, +0,52% sobre a base sem a 3227)',
    perto($p3['proposta'], -1025.325) && perto($p3['percentual'], 2.5325) && perto($p3['percentual_sem_ppr'], 1025.325 / 1020 * 100 - 100),
    json_encode([$p3['proposta'], $p3['percentual'], $p3['percentual_sem_ppr']]));
$p3b = Projecao::pessoalDaDiretriz(-1000.0, $folha3, $calc, 5.5, 1.5);
prova('sem o nível 3 carregado vale a conta anterior (PPR líquido na parte, projeção inteira)',
    perto($p3b['pecas_2026'], 1000 * 180 / 780) && perto($p3b['projecao_sem_ppr'], -1000.0) && perto($p3b['ppr_acerto'], 0.0));
[$c3, , $min3] = Projecao::crescimentosDoNegocio(2027, [], -1000.0,
    ['folha' => $folha3, 'calc' => $calc, 'ppr_salarios' => 1.5, 'ppr26' => ['realizado' => 20.0]]);
$fA = [['codigo' => '8.5', 'projecao' => -100.0]];
$fB = [['codigo' => '8.5', 'projecao' => -900.0]];
$gA = Projecao::crescimentoPessoalDaFilial($c3, $fA, 20.0, $min3);
$gB = Projecao::crescimentoPessoalDaFilial($c3, $fB, 0.0, $min3);
prova('filial: cresce sobre a projeção DELA sem a 3227 DELA, e as filiais somam o negócio',
    perto(100 * (1 + $gA / 100) + 900 * (1 + $gB / 100), 1025.325) && perto(100 * (1 + $gA / 100), 120 * 1025.325 / 1020)
    && perto($gB, $min3['percentual_sem_ppr']), json_encode([$gA, $gB, $c3['8.5']]));

// No banco: Agropecuárias (cód. 8), o exemplo do cliente — a 3227 tem +1.049.311
// em jan–out/2026 e a projeção de 43.464.705 vira 44.514.016.
$agro = $ids['8'];
$ma = $montar->invoke($ctrl, $agro, $ano, $corte, 0);
$pa = $ma['pessoal_diretriz'];
prova('Agropecuárias: 3227 de +R$ 1.049.311 fora da base; base de 2026 = R$ 44.514.016',
    $pa && perto($pa['ppr_acerto'], 1049311, 1) && perto(-$pa['projecao_sem_ppr'], -$pa['projecao'] + 1049311, 1)
    && perto(-$pa['projecao_sem_ppr'], 44514016, 1), json_encode([$pa['ppr_acerto'] ?? null, $pa['projecao_sem_ppr'] ?? null]));
prova('Agropecuárias: o PPR de 2026 que sai é a provisão (3419): R$ 2.710.489',
    perto($pa['ppr_provisao_2026'], 2710489, 2), (string)($pa['ppr_provisao_2026'] ?? ''));
// Estorno direto (pedido do cliente, 2026-11-25): "estornar a provisão atual de PPR conta
// 3419, projetar esse estorno de novembro e dezembro também; fazer a mesma questão para
// as contas 3398 e 3339 (dividir por 10 e multiplicar por 12)". Agropecuárias, jan–out:
// 3419 −2.710.490, 3398 −1.133.297, 3339 −2.391.217 → no ano −7.482.005; base 37.032.011.
$est = array_column($pa['estornos'] ?? [], null, 'conta');
prova('Agropecuárias: 3419, 3398 e 3339 de jan–out ÷ 10 × 12 saem da base: 44.514.016 − 7.482.005 = 37.032.011',
    count($est) === 3 && perto($est['3419']['anual'], -2710490 / 10 * 12, 1) && perto($est['3398']['anual'], -1133297 / 10 * 12, 1)
    && perto($est['3339']['anual'], -2391217 / 10 * 12, 1) && perto($pa['pecas_2026'], 7482005, 2) && perto($pa['base'], 37032011, 2)
    && ($pa['estornos_corte'] ?? null) === 10,
    json_encode([$pa['pecas_2026'] ?? null, $pa['base'] ?? null, $pa['estornos'] ?? null]));
// De mão, sem banco: projeção −1.000, 3227 +20 → 1.020; 3419 −50, 3398 −20, 3339 −30 até
// outubro → −120 no ano; base 900 × 1,055 = 949,5; + benefícios + PPR.
$p4 = Projecao::pessoalDaDiretriz(-1000.0, $folha3, $calc, 5.5, 1.5, 1.0,
    ['realizado' => 20.0, 'estornos' => ['3419' => -50.0, '3398' => -20.0, '3339' => -30.0], 'corte' => 10]);
prova('de mão: base 1.020 − 120 = 900, reajustada 949,5; a parte da folha não entra',
    perto($p4['base'], 900.0) && perto($p4['base_reajustada'], 949.5) && perto($p4['pecas_2026'], 120.0)
    && perto($p4['proposta'], -(949.5 + $p3['beneficios'] + $p3['ppr'])), json_encode($p4));
$l85 = array_column($ma['linhas'], null, 'codigo')['8.5'];
prova('Agropecuárias: as filiais, cada uma sem a 3227 dela, somam o negócio (R$ 10 de folga do arredondamento)',
    $l85['distribuido'] !== null && abs($l85['distribuido'] - $pa['proposta']) < 10,
    round($l85['distribuido']) . ' / ' . round($pa['proposta']));
// O Painel no MODO FILIAL (é quando as filiais formam o total) faz a mesma conta da tela.
$projAgro = Database::um('SELECT id, modo FROM projecao WHERE negocio_id = ? AND ano = ?', [$agro, $ano + 1]);
if ($projAgro) {
    $pdo->prepare('UPDATE projecao SET modo = ? WHERE id = ?')->execute(['FILIAL', $projAgro['id']]);
    try {
        $tela = array_column($montar->invoke($ctrl, $agro, $ano, $corte, 0)['linhas'], 'proposta', 'codigo');
        $pn = Projecao::resumoDosNegocios([$agro], $ano, $corte, $ano + 1, ['8.5', '18'])[$agro]['proposta'] ?? [];
        prova('modo filial: Painel = tela na 8.5 e no resultado das Agropecuárias',
            abs(($pn['8.5'] ?? 0) - $tela['8.5']) < 1 && abs(($pn['18'] ?? 0) - $tela['18']) < 1,
            json_encode([$pn, $tela['8.5'], $tela['18']]));
    } finally {
        $pdo->prepare('UPDATE projecao SET modo = ? WHERE id = ?')->execute([$projAgro['modo'], $projAgro['id']]);
    }
}

// ── 4. A carga da folha ─────────────────────────────────────────────────────
$carga = require __DIR__ . '/../database/folha_2026_10.php';
$recusa = function (array $x) use ($pdo): string {
    try {
        CargaFolha::aplicar($pdo, $x);
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$t = $carga;
$t['ano'] = 2099;
$m2 = $t;
$m2['valores']['2'][1] = 9000000;
prova('vale + prêmio + PPR maiores que a despesa de pessoal são recusados', str_contains($recusa($m2), 'somam mais'));
$m2 = $t;
$m2['valores']['2'] = [1, 2, 3];
prova('linha de tamanho errado é recusada', str_contains($recusa($m2), 'tamanho errado'));
$m2 = $t;
$m2['valores']['2'][4] = -1;
prova('valor negativo é recusado', str_contains($recusa($m2), 'valor inválido'));
prova('nada da carga recusada foi gravado', (int)Database::um('SELECT COUNT(*) n FROM negocio_folha WHERE ano = 2099')['n'] === 0);
CargaFolha::aplicar($pdo, $t);
unset($t['valores']['13']);
CargaFolha::aplicar($pdo, $t);
$n = (int)Database::um('SELECT COUNT(*) n FROM negocio_folha WHERE ano = 2099')['n'];
prova('recarregar sobrescreve o ano (o negócio que saiu da carga sai do banco)', $n === 9, (string)$n);
$pdo->exec('DELETE FROM negocio_folha WHERE ano = 2099');

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

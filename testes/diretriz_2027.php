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
require_once __DIR__ . '/../app/Services/Oportunidades.php';
require_once __DIR__ . '/../app/Services/Reforma.php';
require_once __DIR__ . '/../app/Services/CargaFolha.php';
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
    $d['beneficios'] === ['ALIMENTACAO' => 500.0, 'ASSIDUIDADE' => 200.0, 'RESULTADO' => 500.0]
    && $d['maximos'] === ['PPR_SALARIOS' => 1.5]);
// O número de salários do PPR é um CAMPO com TETO na diretriz (pedido do cliente,
// 2026-11-23): vem preenchido com ela, o negócio pode baixar, acima é recusado.
prova('PPR_SALARIOS é um campo: em 2027 não é fixo, tem teto de 1,5; nos outros anos é livre',
    in_array('PPR_SALARIOS', Projecao::BENEFICIOS, true) && Projecao::fixadaPelaDiretriz(2027, 'PPR_SALARIOS') === null
    && Projecao::maximoDaDiretriz(2027, 'PPR_SALARIOS') === 1.5 && Projecao::maximoDaDiretriz(2028, 'PPR_SALARIOS') === null);
prova('sem digitar, o PPR de 2027 vale a diretriz (1,5)', Projecao::beneficiosEfetivos(2027, [])['PPR_SALARIOS'] === 1.5);
prova('o negócio pode BAIXAR: 1 salário digitado vale 1', Projecao::beneficiosEfetivos(2027, ['PPR_SALARIOS' => 1.0])['PPR_SALARIOS'] === 1.0);
prova('acima da diretriz não passa do teto (2 guardado vale 1,5)', Projecao::beneficiosEfetivos(2027, ['PPR_SALARIOS' => 2.0])['PPR_SALARIOS'] === 1.5);
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
$pdo = Database::conn();
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

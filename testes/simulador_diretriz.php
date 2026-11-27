<?php

/**
 * Provas do SIMULADOR e da DIRETRIZ editável (cliente, 2026-11-26: "se eu, como diretor,
 * quiser visualizar o impacto no resultado de todos os negócios apenas reduzindo o PPR de
 * 1,5 para 1 […]"; "faça as diretrizes editáveis pelo administrador, com o botão Aplicar").
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/simulador_diretriz.php
 *
 * Simular não grava nada; aplicar grava `diretriz_valor` e muda a proposta das linhas de
 * pessoal NÃO digitadas — e nenhum lançamento. Só ADMIN, com o modo apresentação
 * desligado. A prova devolve a diretriz e a configuração ao que eram.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

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

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8099';
$sessao = static function () use ($base): callable {
    $jar = tempnam(sys_get_temp_dir(), 'cfg');
    return static function (string $metodo, string $caminho, ?array $corpo = null, array $cab = []) use ($jar, $base): string {
        $c = curl_init($base . $caminho);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $cab)]);
        if ($corpo !== null) {
            curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($corpo));
        }
        $r = curl_exec($c);
        curl_close($c);
        return (string)$r;
    };
};
$entrar = static function (callable $http, string $email, string $senha): array {
    $http('POST', '/api/login', ['email' => $email, 'senha' => $senha]);
    preg_match('/name="csrf" content="([^"]*)"/', $http('GET', '/'), $mm);
    return ['X-CSRF-Token: ' . ($mm[1] ?? '')];
};
$ler = static fn (string $r) => (json_decode($r, true)['dados'] ?? json_decode($r, true));

$http = $sessao();
if ($http('GET', '/login') === '') {
    echo "  ⏭  pulada: a aplicação não responde em {$base}.\n";
    exit(0);
}
$digital = static fn () => md5(json_encode(Database::todos('SELECT * FROM projecao_valor ORDER BY id')));
$diretrizAntes = Database::todos('SELECT * FROM diretriz_valor ORDER BY ano, chave');
$flagAntes = Database::um("SELECT valor FROM configuracao WHERE chave = 'diagnostico_somente_leitura'")['valor'] ?? null;
$cab = $entrar($http, getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', getenv('APP_SENHA') ?: 'trocar123');
$email = 'prova.simulador.' . getmypid() . '@teste.local';
$bruto = static function (string $r): array {
    $j = json_decode($r, true) ?: [];
    return $j;
};
// Um negócio com a despesa com pessoal DIGITADA à mão (Supermercados, 8.5 = −20 mi), para
// provar que ele também mostra o impacto da diretriz (cliente, 2026-11-27). A prova o apaga.
$projSuper = (int)(Database::um("SELECT p.id FROM projecao p JOIN negocio n ON n.id = p.negocio_id
    WHERE n.cod_negocio = '9' AND p.ano = 2027")['id'] ?? 0);
$conta85 = (int)(Database::um("SELECT id FROM dre_conta WHERE codigo = '8.5'")['id'] ?? 0);
$criouLancamento = false;
try {
    $http('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 0], $cab);
    Database::executar('DELETE FROM diretriz_valor');
    if ($projSuper && $conta85 && !Database::um('SELECT 1 FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?', [$projSuper, $conta85])) {
        Database::executar("INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor) VALUES (?, 0, ?, 'VALOR', -20000000)",
            [$projSuper, $conta85]);
        $criouLancamento = true;
    }
    $lanc0 = $digital();

    // ── simular ──
    $z = $ler($http('GET', '/api/projecao/simulacao'));
    $zero = array_map(static fn ($c) => round($z['total']['simulado'][$c] - $z['total']['atual'][$c], 2), ['3', '7', '8.5', '15', '18']);
    prova('com a diretriz vigente, a simulação dá impacto zero em todas as linhas', $zero === [0.0, 0.0, 0.0, 0.0, 0.0], json_encode($zero));
    $u = $ler($http('GET', '/api/projecao/simulacao?ppr=1'));
    $imp = static fn ($c) => $u['total']['simulado'][$c] - $u['total']['atual'][$c];
    $soma = array_sum(array_map(static fn ($n) => $n['simulado']['18'] - $n['atual']['18'], $u['negocios']));
    prova('PPR de 1,5 para 1: o resultado líquido sobe, igual à queda da despesa com pessoal; a receita líquida não muda',
        $imp('18') > 0 && abs($imp('18') - $imp('8.5')) < 1 && abs($imp('3')) < 0.01 && abs($soma - $imp('18')) < 1,
        json_encode(['18' => round($imp('18')), '8.5' => round($imp('8.5')), '3' => $imp('3')]));
    $super = array_values(array_filter($u['negocios'], static fn ($n) => str_contains($n['rotulo'], 'SUPERMERCADOS')))[0] ?? null;
    if ($criouLancamento && $super) {
        $dSuper = $super['simulado']['8.5'] - $super['atual']['8.5'];
        // O líquido dele também sobe — não exatamente o mesmo: o rateio administrativo se
        // redistribui pelas despesas dos OUTROS negócios, que o motor mudou.
        prova('negócio com pessoal digitado à mão também mostra o impacto (marcado), e ele chega ao resultado líquido',
            $super['pessoal_lancado'] === true && $dSuper > 1000 && abs($super['ajuste_lancado'] - $dSuper) < 1
            && ($super['simulado']['18'] - $super['atual']['18']) > 0,
            json_encode(['8.5' => round($dSuper), '18' => round($super['simulado']['18'] - $super['atual']['18']), 'lancado' => $super['pessoal_lancado']]));
    }
    // O abono de resultado segue o % de meses com resultado PREVISTO pelo negócio: o impacto de
    // R$ 500 → R$ 300 é −40% do abono anual dele (colaboradores × % × valor × 12), na parte da despesa.
    $leiteId = (int)(Database::um("SELECT id FROM negocio WHERE cod_negocio = '4'")['id'] ?? 0);
    $nl = $ler($http('GET', "/api/projecao?negocio_id={$leiteId}"));
    $anoP = (int)$nl['ano_planejamento'];
    $esperado = 0.4 * (float)$nl['pessoas']['por_ano'][$anoP]['anual']['resultado'] * (float)($nl['pessoal_diretriz']['parte_despesa'] ?? 1);
    $sr = $ler($http('GET', '/api/projecao/simulacao?resultado=300'));
    $lt = array_values(array_filter($sr['negocios'], static fn ($n) => (int)$n['id'] === $leiteId))[0] ?? null;
    prova('abono de resultado R$ 500 → R$ 300: o impacto é 40% do abono anual previsto pelo negócio (colaboradores × % dos meses × 12)',
        $lt && $esperado > 0 && abs(($lt['simulado']['8.5'] - $lt['atual']['8.5']) - $esperado) < 1,
        json_encode([round($lt['simulado']['8.5'] - $lt['atual']['8.5']), round($esperado)]));
    prova('as quatro linhas do seletor vêm (despesas operacionais, pessoal, resultado do negócio, líquido)',
        isset($u['total']['atual']['7'], $u['total']['atual']['8.5'], $u['total']['atual']['15'], $u['total']['atual']['18']));
    $inv = $bruto($http('GET', '/api/projecao/simulacao?ppr=13'));
    prova('valor fora de faixa é recusado', str_contains((string)($inv['erro'] ?? ''), 'inválido'), (string)($inv['erro'] ?? ''));
    prova('simular não grava nada (lançamentos e diretriz intactos)',
        $digital() === $lanc0 && (int)Database::um('SELECT COUNT(*) n FROM diretriz_valor')['n'] === 0);

    // ── só ADMIN, com o modo apresentação desligado ──
    Database::executar("INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES ('Prova simulador', ?, ?, 'CONTROLADORIA', 1)",
        [$email, password_hash('prova123', PASSWORD_DEFAULT)]);
    $h2 = $sessao();
    $cab2 = $entrar($h2, $email, 'prova123');
    $g1 = $bruto($h2('GET', '/api/projecao/simulacao?ppr=1'));
    $g2 = $bruto($h2('POST', '/api/projecao/diretriz', ['ppr' => 1], $cab2));
    prova('quem não é ADMIN (CONTROLADORIA) não simula nem aplica',
        str_contains((string)($g1['erro'] ?? ''), 'administradores') && str_contains((string)($g2['erro'] ?? ''), 'administradores'),
        json_encode([$g1['erro'] ?? null, $g2['erro'] ?? null]));
    $http('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 1], $cab);
    $f = $bruto($http('GET', '/api/projecao/simulacao?ppr=1'));
    prova('com o modo apresentação ligado, nem o ADMIN simula', str_contains((string)($f['erro'] ?? ''), 'apresentação'), (string)($f['erro'] ?? ''));
    $http('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 0], $cab);

    // ── aplicar ──
    $corp0 = $ler($http('GET', '/api/projecao?corporativo=1'));
    $a = $ler($http('POST', '/api/projecao/diretriz', ['ppr' => '1'], $cab));
    $linhas = Database::todos('SELECT chave, valor FROM diretriz_valor WHERE ano = ?', [(int)$a['ano']]);
    prova('aplicar grava só o que difere do documento (PPR = 1)',
        count($linhas) === 1 && $linhas[0]['chave'] === 'PPR_SALARIOS' && (float)$linhas[0]['valor'] === 1.0, json_encode($linhas));
    $corp1 = $ler($http('GET', '/api/projecao?corporativo=1'));
    prova('a diretriz vigente passa a ser a aplicada, com a marca de alterada',
        ($corp1['diretriz_simulavel']['ppr'] ?? null) == 1 && !empty($corp1['diretriz_alterada']), json_encode($corp1['diretriz_simulavel'] ?? null));
    $res0 = $corp0['visao_ano_total']['18']['proposta'];
    $res1 = $corp1['visao_ano_total']['18']['proposta'];
    // Aplicar muda o simulado MENOS a parte dos negócios com pessoal digitado (o lançamento vale).
    $pendente = array_sum(array_map(static fn ($n) => (float)$n['ajuste_lancado'], $u['negocios']));
    prova('aplicar muda o resultado consolidado exatamente o simulado, menos a parte dos negócios com pessoal digitado',
        abs(($res1 - $res0) - ($imp('18') - $pendente)) < 1, json_encode([round($res1 - $res0), round($imp('18')), round($pendente)]));
    prova('nenhum lançamento dos negócios mudou', $digital() === $lanc0);
    $ppr = $bruto($http('POST', '/api/projecao/beneficio', ['negocio_id' => (int)$corp1['negocios'][0]['id'], 'ano' => (int)$a['ano'],
        'chave' => 'PPR_SALARIOS', 'valor' => 2], $cab));
    prova('o negócio continua sem poder mexer no PPR (diretriz fixa: o valor aplicado)',
        str_contains((string)($ppr['erro'] ?? ''), 'Fixado pela diretriz') && str_contains((string)($ppr['erro'] ?? ''), '1,0 salário'),
        (string)($ppr['erro'] ?? ''));

    // ── voltar ao documento ──
    $ler($http('POST', '/api/projecao/diretriz', ['restaurar' => true], $cab));
    $corp2 = $ler($http('GET', '/api/projecao?corporativo=1'));
    prova('"voltar ao documento" apaga o que foi gravado e o resultado volta ao de antes',
        (int)Database::um('SELECT COUNT(*) n FROM diretriz_valor')['n'] === 0 && empty($corp2['diretriz_alterada'])
        && abs($corp2['visao_ano_total']['18']['proposta'] - $res0) < 0.01);
} finally {
    if ($criouLancamento) {
        Database::executar('DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = 0 AND conta_id = ?', [$projSuper, $conta85]);
    }
    Database::executar('DELETE FROM usuario WHERE email = ?', [$email]);
    Database::executar('DELETE FROM diretriz_valor');
    foreach ($diretrizAntes as $l) {
        Database::executar('INSERT INTO diretriz_valor (ano, chave, valor, atualizado_por, atualizado_em) VALUES (?, ?, ?, ?, ?)',
            [$l['ano'], $l['chave'], $l['valor'], $l['atualizado_por'], $l['atualizado_em']]);
    }
    Database::executar("UPDATE configuracao SET valor = ? WHERE chave = 'diagnostico_somente_leitura'", [$flagAntes ?? '0']);
}

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

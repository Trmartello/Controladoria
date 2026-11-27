<?php

/**
 * Provas do AGRUPAMENTO para quem não enxerga todos os negócios (cliente,
 * 2026-11-26: "não consigo mais selecionar mais de um negócio […] volte como
 * estava"). O pedido de 2026-11-24 era de todo usuário: um GESTOR com dois ou
 * mais negócios soma os DELE — sem o Corporativo, sem negócio de fora, só para ver.
 *
 *   DB_HOST=127.0.0.1 DB_PORT=33061 DB_NAME=planejamento DB_USER=app DB_PASS=app \
 *     php testes/agrupamento_escopo.php
 *
 * Bate na aplicação em APP_URL (padrão http://127.0.0.1:8099); sem ela de pé, é
 * pulada. Cria um gestor de prova (Leite, P. Combustíveis, P. Resf. Leite) e o apaga.
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
$id = static function (string $cod): int {
    return (int)(Database::um('SELECT id FROM negocio WHERE cod_negocio = ?', [$cod])['id'] ?? 0);
};
[$leite, $comb, $resf, $cereais] = [$id('4'), $id('11'), $id('12'), $id('1')];
$email = 'prova.agrupa.' . getmypid() . '@teste.local';
try {
    Database::executar("INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES ('Prova agrupamento', ?, ?, 'GESTOR', 1)",
        [$email, password_hash('prova123', PASSWORD_DEFAULT)]);
    $uid = (int)Database::um('SELECT id FROM usuario WHERE email = ?', [$email])['id'];
    foreach ([$leite, $comb, $resf] as $n) {
        Database::executar('INSERT INTO usuario_negocio (usuario_id, negocio_id) VALUES (?, ?)', [$uid, $n]);
    }
    $entrar($http, $email, 'prova123');

    $d = $ler($http('GET', "/api/projecao?negocio_id={$comb}"));
    prova('o gestor com três negócios pode agrupar, mas não vê o Corporativo',
        ($d['pode_agrupar'] ?? null) === true && ($d['pode_corporativo'] ?? null) === false,
        json_encode(['agrupar' => $d['pode_agrupar'] ?? null, 'corp' => $d['pode_corporativo'] ?? null]));

    $g = $ler($http('GET', "/api/projecao?corporativo=1&negocios={$leite},{$resf}"));
    prova('dois negócios dele: a soma, só para ver',
        !empty($g['corporativo']) && ($g['grupo'] ?? null) === [$leite, $resf] && ($g['pode_editar'] ?? null) === false,
        json_encode(['grupo' => $g['grupo'] ?? null, 'editar' => $g['pode_editar'] ?? null]));

    $somaTelas = 0.0;
    foreach ([$leite, $resf] as $n) {
        $t = $ler($http('GET', "/api/projecao?negocio_id={$n}"));
        foreach ($t['linhas'] as $l) {
            if ($l['codigo'] === '18') { $somaTelas += (float)$l['proposta']; }
        }
    }
    $somaGrupo = 0.0;
    foreach ($g['linhas'] as $l) {
        if ($l['codigo'] === '18') { $somaGrupo = (float)$l['proposta']; }
    }
    prova('o resultado líquido da soma é a soma das telas dos negócios',
        abs($somaGrupo - $somaTelas) < 0.01, sprintf('%.2f × %.2f', $somaGrupo, $somaTelas));

    $f = $ler($http('GET', "/api/projecao?corporativo=1&negocios={$cereais},{$leite},{$resf}"));
    prova('negócio de fora do escopo (Cereais) é ignorado', ($f['grupo'] ?? null) === [$leite, $resf], json_encode($f['grupo'] ?? null));

    $u = $ler($http('GET', "/api/projecao?corporativo=1&negocios={$cereais},{$leite}"));
    prova('com um só negócio dele não há soma: fica na tela do negócio', empty($u['corporativo']), json_encode(['corp' => $u['corporativo'] ?? null]));

    $s = $ler($http('GET', '/api/projecao?corporativo=1'));
    prova('sem escolher negócios, o gestor não recebe o Corporativo', empty($s['corporativo']), json_encode(['corp' => $s['corporativo'] ?? null]));

    // A comparação com a Proposta do ano seguinte no quadro do ano é só do ADMIN, e só
    // com o modo apresentação desligado (cliente, 2026-11-26) — a trava é do servidor.
    $semProposta = static fn (array $v) => !array_filter($v, static fn ($x) => array_key_exists('proposta', (array)$x)
        || array_key_exists('desafio', (array)$x));
    prova('o gestor não recebe as propostas no quadro do ano (nem o seletor)',
        ($g['pode_comparar_proposta'] ?? null) === false && $semProposta($g['visao_ano_total'] ?? [])
        && ($d['pode_comparar_proposta'] ?? null) === false && $semProposta($d['visao_ano'] ?? []),
        json_encode(['grupo' => $g['pode_comparar_proposta'] ?? null, 'negocio' => $d['pode_comparar_proposta'] ?? null]));
    $adm = $sessao();
    $cabAdm = $entrar($adm, getenv('APP_EMAIL') ?: 'admin@coperdia.com.br', getenv('APP_SENHA') ?: 'trocar123');
    $flagAntes = Database::um("SELECT valor FROM configuracao WHERE chave = 'diagnostico_somente_leitura'")['valor'] ?? null;
    try {
        $adm('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 0], $cabAdm);
        $a0 = $ler($adm('GET', '/api/projecao?corporativo=1'));
        prova('o ADMIN, com o modo apresentação desligado, recebe as propostas',
            ($a0['pode_comparar_proposta'] ?? null) === true && array_key_exists('proposta', $a0['visao_ano_total']['18'] ?? []),
            json_encode(['pode' => $a0['pode_comparar_proposta'] ?? null]));
        $adm('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => 1], $cabAdm);
        $a1 = $ler($adm('GET', '/api/projecao?corporativo=1'));
        prova('com o modo apresentação ligado, nem o ADMIN recebe as propostas',
            ($a1['pode_comparar_proposta'] ?? null) === false && $semProposta($a1['visao_ano_total'] ?? []),
            json_encode(['pode' => $a1['pode_comparar_proposta'] ?? null]));
    } finally {
        $adm('POST', '/api/configuracoes', ['chave' => 'diagnostico_somente_leitura', 'valor' => (int)($flagAntes ?? 0)], $cabAdm);
    }
    prova('o modo apresentação voltou ao que era',
        (Database::um("SELECT valor FROM configuracao WHERE chave = 'diagnostico_somente_leitura'")['valor'] ?? null) === ($flagAntes ?? '0'));
} finally {
    Database::executar('DELETE FROM usuario_negocio WHERE usuario_id IN (SELECT id FROM usuario WHERE email = ?)', [$email]);
    Database::executar('DELETE FROM usuario WHERE email = ?', [$email]);
}

echo "\n" . count($bad) . " falha(s), {$ok} verde(s).\n";
exit($bad ? 1 : 0);

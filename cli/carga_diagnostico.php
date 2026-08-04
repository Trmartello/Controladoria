<?php

/**
 * Aplica uma carga de conteúdo do diagnóstico a um planejamento escolhido.
 *
 *   php cli/carga_diagnostico.php --listar                  # cargas e planejamentos
 *   php cli/carga_diagnostico.php pestel 7 2026             # PRÉVIA (não escreve)
 *   php cli/carga_diagnostico.php pestel 7 2026 --aplicar   # grava
 *
 * O deploy já aplica todas as cargas ao planejamento CORPORATIVO, no passo do
 * `database/migrate.php`. Esta CLI serve para o que o migrate não faz: aplicar
 * a um negócio específico, a outro ano do ciclo, ou antes do próximo deploy.
 *
 * Os textos vêm dos arquivos `database/conteudo_*.php` — os mesmos que o
 * migrate lê — e a decisão do que já está na tela é de
 * App\Services\CargaConteudo, a mesma que o migrate usa. Cada cópia dessa
 * regra divergiria da outra na primeira revisão.
 *
 * A prévia é o padrão de propósito: a carga escreve na análise de um
 * planejamento em uso, e a tela não tem "desfazer" — só exclusão item a item.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';
require __DIR__ . '/../app/Services/CargaConteudo.php';

use App\Core\Database;
use App\Services\CargaConteudo;

/** Cargas disponíveis, pelo nome curto usado na linha de comando. */
const CARGAS = [
    'cenario' => 'conteudo_cenario_macro.php',
    'pestel'  => 'conteudo_pestel_macro.php',
    'porter'  => 'conteudo_porter_macro.php',
];

/** str_pad conta bytes: "2027–2035" tem travessão e desalinharia a coluna. */
function coluna(string $t, int $largura): string
{
    return $t . str_repeat(' ', max(1, $largura - mb_strlen($t, 'UTF-8')));
}

function carregar(string $nome): array
{
    return require __DIR__ . '/../database/' . CARGAS[$nome];
}

function listar(): void
{
    echo "Cargas disponíveis:\n";
    foreach (CARGAS as $nome => $arquivo) {
        $c = carregar($nome);
        $qtd = array_sum(array_map('count', $c['itens']));
        $aplicada = CargaConteudo::jaAplicada(Database::conn(), $c['chave']) ? 'sim' : 'não';
        echo '  ' . coluna($nome, 10) . coluna("{$qtd} registro(s)", 18)
            . coluna("ano {$c['ano']}", 10) . coluna("chave {$c['chave']}", 30)
            . "aplicada no deploy: {$aplicada}\n";
    }

    $linhas = Database::todos(
        "SELECT p.id, c.nome AS ciclo, c.ano_base, c.ano_fim, p.escopo,
                COALESCE(n.nome, 'Corporativo') AS negocio,
                (SELECT COUNT(*) FROM cenario_item ci WHERE ci.planejamento_id = p.id) AS cenario,
                (SELECT COUNT(*) FROM fator f WHERE f.planejamento_id = p.id AND f.etapa = 'PESTEL') AS pestel,
                (SELECT COUNT(*) FROM fator f WHERE f.planejamento_id = p.id AND f.etapa = 'PORTER') AS porter
         FROM planejamento p
         JOIN ciclo c ON c.id = p.ciclo_id
         LEFT JOIN negocio n ON n.id = p.negocio_id
         ORDER BY c.ano_base DESC, negocio"
    );
    if (!$linhas) {
        echo "\nNenhum planejamento cadastrado.\n";
        return;
    }
    echo "\n" . coluna('ID', 5) . coluna('CICLO', 26) . coluna('ESCOPO', 14)
        . coluna('NEGÓCIO', 26) . coluna('CENÁRIO', 10) . coluna('PESTEL', 9) . "PORTER\n";
    foreach ($linhas as $l) {
        echo coluna((string)$l['id'], 5)
            . coluna("{$l['ciclo']} ({$l['ano_base']}-{$l['ano_fim']})", 26)
            . coluna((string)$l['escopo'], 14)
            . coluna((string)$l['negocio'], 26)
            . coluna((string)$l['cenario'], 10)
            . coluna((string)$l['pestel'], 9)
            . $l['porter'] . "\n";
    }
}

$args = array_slice($argv, 1);
$aplicar = in_array('--aplicar', $args, true);

if (in_array('--listar', $args, true)) {
    listar();
    exit(0);
}

$args = array_values(array_filter($args, fn($a) => $a[0] !== '-'));
$nome = strtolower((string)($args[0] ?? ''));
$uso = 'Uso: php cli/carga_diagnostico.php [--listar | <'
    . implode('|', array_keys(CARGAS)) . '> <planejamento_id> [ano] [--aplicar]]';
if (!isset(CARGAS[$nome])) {
    fwrite(STDERR, "{$uso}\n");
    exit(2);
}
$conteudo = carregar($nome);
$planId = (int)($args[1] ?? 0);
$ano = (int)($args[2] ?? $conteudo['ano']);
if ($planId <= 0) {
    fwrite(STDERR, "{$uso}\n");
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
    fwrite(STDERR, "carga: planejamento {$planId} não encontrado (use --listar).\n");
    exit(1);
}
// O seletor de ano da tela é limitado a [ano_base, ano_fim]: registro gravado
// fora da faixa existiria no banco sem jamais aparecer para o usuário.
if ($ano < (int)$plan['ano_base'] || $ano > (int)$plan['ano_fim']) {
    fwrite(STDERR, "carga: ano {$ano} fora do ciclo {$plan['ciclo']} "
        . "({$plan['ano_base']}-{$plan['ano_fim']}); a tela não exibiria os registros.\n");
    exit(1);
}
$conteudo['ano'] = $ano;

echo "carga {$nome}: {$plan['negocio']} · {$plan['ciclo']} · ano {$ano}"
    . ($aplicar ? "\n" : "  [PRÉVIA — nada será gravado]\n");

// A prévia repete a mesma decisão do serviço (o que já está na tela não entra),
// só que sem gravar — por isso lista os grupos a partir do próprio conteúdo.
$pdo = Database::conn();
if (!$aplicar) {
    $novos = 0;
    $repetidos = 0;
    foreach ($conteudo['itens'] as $grupo => $textos) {
        echo "\n== {$grupo}\n";
        foreach ($textos as $texto) {
            $resumo = mb_substr($texto, 0, 84, 'UTF-8') . '…';
            // Uma consulta por texto é desperdício irrelevante aqui: são dezenas
            // de linhas, uma única vez, na mão de quem está conferindo
            $ja = $conteudo['destino'] === 'CENARIO'
                ? Database::todos(
                    'SELECT descricao FROM cenario_item WHERE planejamento_id = ? AND ano = ?',
                    [$planId, $ano])
                : Database::todos(
                    'SELECT descricao FROM fator WHERE planejamento_id = ? AND ano = ? AND etapa = ?',
                    [$planId, $ano, $conteudo['etapa']]);
            $existe = false;
            foreach ($ja as $l) {
                if (CargaConteudo::chaveTexto($l['descricao']) === CargaConteudo::chaveTexto($texto)) {
                    $existe = true;
                    break;
                }
            }
            echo $existe ? "  = já existe: {$resumo}\n" : "  + gravaria: {$resumo}\n";
            $existe ? $repetidos++ : $novos++;
        }
    }
    echo "\ncarga {$nome}: {$novos} registro(s) a gravar, {$repetidos} já presente(s).\n";
    echo "carga {$nome}: repita com --aplicar para gravar.\n";
    exit(0);
}

$gravados = CargaConteudo::aplicar($pdo, $conteudo, $planId);
$total = array_sum(array_map('count', $conteudo['itens']));
echo "carga {$nome}: {$gravados} registro(s) gravado(s), "
    . ($total - $gravados) . " já presente(s).\n";
exit(0);

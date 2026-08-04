<?php

/**
 * Aplica a carga de cenário macroeconômico a um planejamento escolhido à mão.
 *
 *   php cli/cenario_macro.php --listar              # planejamentos disponíveis
 *   php cli/cenario_macro.php 7 2026                # PRÉVIA (não escreve nada)
 *   php cli/cenario_macro.php 7 2026 --aplicar      # grava os itens
 *
 * O deploy já aplica esta mesma carga ao planejamento CORPORATIVO, no passo do
 * `database/migrate.php`. Esta CLI serve para o que o migrate não faz: aplicar
 * a um negócio específico, a outro ano do ciclo, ou antes do próximo deploy.
 * Os textos vêm de `database/conteudo_cenario_macro.php` — o mesmo arquivo que
 * o migrate lê, para não existirem duas redações envelhecendo à parte.
 *
 * A prévia é o padrão de propósito: a carga escreve na análise de um
 * planejamento em uso, e a tela não tem "desfazer" — só exclusão item a item.
 *
 * Reexecutar é seguro: item cujo texto já existe naquele planejamento/ano é
 * ignorado (comparação por texto normalizado — minúsculas, sem acento e com
 * espaços colapsados), e os itens que já estavam na tela permanecem intactos.
 */

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

$conteudo = require __DIR__ . '/../database/conteudo_cenario_macro.php';

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

if (in_array('--listar', $args, true)) {
    listar();
    exit(0);
}

$args = array_values(array_filter($args, fn($a) => $a[0] !== '-'));
$planId = (int)($args[0] ?? 0);
$ano = (int)($args[1] ?? $conteudo['ano']);
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
foreach ($conteudo['itens'] as $tipo => $textos) {
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

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
// Database::executar marca o pulso em App\Core\Versao; sem a classe carregada,
// a primeira escrita fora do front controller morre com "Class not found".
require __DIR__ . '/../app/Core/Versao.php';
require __DIR__ . '/../app/Services/CargaConteudo.php';

use App\Core\Database;
use App\Services\CargaConteudo;

/** Cargas disponíveis, pelo nome curto usado na linha de comando. */
const CARGAS = [
    'cenario' => 'conteudo_cenario_macro.php',
    'pestel'  => 'conteudo_pestel_macro.php',
    'porter'  => 'conteudo_porter_macro.php',
    'swot'    => 'conteudo_swot_macro.php',
    'cascata' => 'conteudo_cascata_h1.php',
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
        $quando = isset($c['ano']) ? "ano {$c['ano']}" : "horizonte {$c['horizonte']}";
        $unidade = $c['destino'] === 'CASCATA' ? 'célula(s)' : 'registro(s)';
        echo '  ' . coluna($nome, 10) . coluna("{$qtd} {$unidade}", 18)
            . coluna($quando, 16) . coluna("chave {$c['chave']}", 30)
            . "aplicada no deploy: {$aplicada}\n";
    }

    // Uma coluna por carga, montada a partir de CARGAS: carga nova aparece
    // aqui sozinha, sem mais uma edição nesta consulta.
    $etapasValidas = ['PESTEL', 'PORTER', 'SWOT'];
    $contagens = [];
    foreach (CARGAS as $nome => $_) {
        $c = carregar($nome);
        if ($c['destino'] === 'CENARIO') {
            $contagens[] = "(SELECT COUNT(*) FROM cenario_item ci
                             WHERE ci.planejamento_id = p.id) AS `{$nome}`";
            continue;
        }
        if ($c['destino'] === 'CASCATA') {
            $contagens[] = "(SELECT COUNT(*) FROM cascata_escolha ce
                              JOIN horizonte h ON h.id = ce.horizonte_id
                             WHERE ce.planejamento_id = p.id
                               AND h.nome = " . Database::conn()->quote($c['horizonte'])
                . ") AS `{$nome}`";
            continue;
        }
        // A etapa vem do arquivo de conteúdo, não de entrada do usuário — mas
        // ela entra no SQL por interpolação, então passa por lista branca
        // antes, e não por "é nosso arquivo, então é seguro".
        if (!in_array($c['etapa'], $etapasValidas, true)) {
            fwrite(STDERR, "carga {$nome}: etapa {$c['etapa']} desconhecida.\n");
            exit(1);
        }
        $contagens[] = "(SELECT COUNT(*) FROM fator f
                         WHERE f.planejamento_id = p.id AND f.etapa = '{$c['etapa']}') AS `{$nome}`";
    }
    $linhas = Database::todos(
        "SELECT p.id, c.nome AS ciclo, c.ano_base, c.ano_fim, p.escopo,
                COALESCE(n.nome, 'Corporativo') AS negocio,
                " . implode(",\n                ", $contagens) . "
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
        . coluna('NEGÓCIO', 26)
        . implode('', array_map(fn($n) => coluna(mb_strtoupper($n), 10), array_keys(CARGAS)))
        . "\n";
    foreach ($linhas as $l) {
        echo coluna((string)$l['id'], 5)
            . coluna("{$l['ciclo']} ({$l['ano_base']}-{$l['ano_fim']})", 26)
            . coluna((string)$l['escopo'], 14)
            . coluna((string)$l['negocio'], 26)
            . implode('', array_map(fn($n) => coluna((string)$l[$n], 10), array_keys(CARGAS)))
            . "\n";
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
$porHorizonte = $conteudo['destino'] === 'CASCATA';
$planId = (int)($args[1] ?? 0);
$ano = (int)($args[2] ?? $conteudo['ano'] ?? 0);
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
// fora da faixa existiria no banco sem jamais aparecer para o usuário. A
// cascata não passa por aqui: ela é do horizonte, que já pertence ao ciclo, e
// quem confere se ele existe é a própria carga.
if (!$porHorizonte) {
    if ($ano < (int)$plan['ano_base'] || $ano > (int)$plan['ano_fim']) {
        fwrite(STDERR, "carga: ano {$ano} fora do ciclo {$plan['ciclo']} "
            . "({$plan['ano_base']}-{$plan['ano_fim']}); a tela não exibiria os registros.\n");
        exit(1);
    }
    $conteudo['ano'] = $ano;
}

$quando = $porHorizonte ? "horizonte {$conteudo['horizonte']}" : "ano {$ano}";
echo "carga {$nome}: {$plan['negocio']} · {$plan['ciclo']} · {$quando}"
    . ($aplicar ? "\n" : "  [PRÉVIA — nada será gravado]\n");

$pdo = Database::conn();
$total = array_sum(array_map('count', $conteudo['itens']));

// A prévia pergunta ao SERVIÇO o que falta, em vez de repetir a comparação.
// Repetida aqui, ela divergiria da que grava — e a prévia passaria a mentir
// exatamente sobre o que a carga vai fazer.
if (!$aplicar) {
    if ($conteudo['destino'] === 'CASCATA') {
        try {
            $vazias = CargaConteudo::celulasVazias($pdo, $conteudo, $planId);
        } catch (RuntimeException $e) {
            fwrite(STDERR, "carga {$nome}: {$e->getMessage()}\n");
            exit(1);
        }
        $grupo = null;
        foreach ($vazias as $c) {
            if ($c['driver'] !== $grupo) {
                $grupo = $c['driver'];
                echo "\n== {$grupo}\n";
            }
            $resumo = mb_substr($c['escolha'], 0, 70, 'UTF-8') . '…';
            echo "  + gravaria [{$c['eixo']}]: {$resumo}\n";
        }
        $novos = count($vazias);
        echo "\ncarga {$nome}: {$novos} célula(s) a gravar, "
            . ($total - $novos) . " já preenchida(s).\n";
        echo "carga {$nome}: repita com --aplicar para gravar.\n";
        exit(0);
    }

    $ja = $conteudo['destino'] === 'CENARIO'
        ? Database::todos(
            'SELECT descricao FROM cenario_item WHERE planejamento_id = ? AND ano = ?',
            [$planId, $ano])
        : Database::todos(
            'SELECT descricao FROM fator WHERE planejamento_id = ? AND ano = ? AND etapa = ?',
            [$planId, $ano, $conteudo['etapa']]);
    $existentes = [];
    foreach ($ja as $l) {
        $existentes[CargaConteudo::chaveTexto($l['descricao'])] = true;
    }
    $novos = 0;
    $repetidos = 0;
    $revisoes = 0;
    foreach ($conteudo['itens'] as $grupo => $itens) {
        echo "\n== {$grupo}\n";
        foreach ($itens as $item) {
            $texto = CargaConteudo::textoDoItem($item);
            $anterior = CargaConteudo::textoAnterior($item);
            $resumo = mb_substr($texto, 0, 84, 'UTF-8') . '…';
            // Revisão: o texto anterior na tela é atualizado no lugar (só o
            // cenário sabe fazer isso; ver CargaConteudo::textoDoItem)
            if ($anterior !== null && $conteudo['destino'] === 'CENARIO'
                && isset($existentes[CargaConteudo::chaveTexto($anterior)])) {
                echo "  ~ atualizaria: {$resumo}\n";
                $revisoes++;
                continue;
            }
            $existe = isset($existentes[CargaConteudo::chaveTexto($texto)]);
            echo $existe ? "  = já existe: {$resumo}\n" : "  + gravaria: {$resumo}\n";
            $existe ? $repetidos++ : $novos++;
        }
    }
    echo "\ncarga {$nome}: {$novos} registro(s) a gravar, {$revisoes} a atualizar no lugar, "
        . "{$repetidos} já presente(s).\n";
    echo "carga {$nome}: repita com --aplicar para gravar.\n";
    exit(0);
}

try {
    $gravados = CargaConteudo::aplicar($pdo, $conteudo, $planId);
} catch (RuntimeException $e) {
    fwrite(STDERR, "carga {$nome}: {$e->getMessage()}\n");
    exit(1);
}
echo "carga {$nome}: {$gravados} registro(s) gravado(s), "
    . ($total - $gravados) . " já presente(s).\n";
exit(0);

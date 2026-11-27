<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\DesafioCota;
use App\Services\DesafioAjuste;
use App\Services\DesafioDrivers;
use App\Services\Oportunidades;
use App\Services\Projecao;
use App\Services\Reforma;

/**
 * Projeção de Resultados — fatia 1: a DRE do ano corrente na tela.
 *
 * Realizado dos meses FECHADOS, projetado pelo orçamento dos meses que faltam,
 * e a soma dos dois como projeção do ano. A proposta do ano de planejamento
 * (fatias 2 e 3 do tema 13 do backlog) ainda não existe — esta tela é só de
 * leitura, e é de propósito: ela já substitui a planilha que hoje circula por
 * e-mail antes da reunião.
 *
 * Duas decisões que não são óbvias e não devem ser desfeitas:
 *
 * 1. **O corte do fechamento vem do BANCO, não do relógio.** `dre_fechamento`
 *    guarda até que mês o ano fechou, e quem o escreve é a carga, que recusa
 *    mês sem rateio. Derivar do mês corrente faria a tela virar de ano em 1º de
 *    janeiro com dezembro ainda aberto.
 * 2. **O ano de planejamento é o do realizado + 1**, e o ano do realizado é o
 *    do último fechamento CARREGADO. Não é o `ciclo.ano_base`: o ciclo fica
 *    parado em 2026 enquanto os fechamentos avançam, e a tela passaria a propor
 *    meta para um ano que já começou.
 */
class ProjecaoController
{
    public function indice(): void
    {
        $u = Auth::exigirLogin();

        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::ok([
                'sem_carga' => true,
                'negocios' => [],
            ]);
            return;
        }
        $ano = (int)$fech['ano'];
        $corte = (int)$fech['mes_fechado_ate'];

        // Só negócios COM DRE carregada: oferecer no seletor um negócio cuja
        // tela abriria vazia é oferecer uma pergunta sem resposta.
        $escopo = Auth::escopoNegocios($u);
        $sql = "SELECT n.id, CONCAT(n.cod_negocio, ' - ', n.nome) AS rotulo
                  FROM negocio n
                 WHERE n.ativo = 1
                   AND EXISTS (SELECT 1 FROM dre_valor v WHERE v.negocio_id = n.id AND v.ano = ?)";
        $args = [$ano];
        if ($escopo !== null) {
            if (!$escopo) {
                Json::ok(['ano' => $ano, 'negocios' => [], 'linhas' => []]);
                return;
            }
            $sql .= ' AND n.id IN (' . implode(',', array_fill(0, count($escopo), '?')) . ')';
            $args = array_merge($args, $escopo);
        }
        $sql .= ' ORDER BY CAST(n.cod_negocio AS UNSIGNED)';
        $negocios = Database::todos($sql, $args);
        if (!$negocios) {
            Json::ok(['ano' => $ano, 'negocios' => [], 'linhas' => []]);
            return;
        }

        $pedido = (int)($_GET['negocio_id'] ?? 0);
        $ids = array_map(static fn ($n) => (int)$n['id'], $negocios);
        $negocioId = in_array($pedido, $ids, true) ? $pedido : $ids[0];

        // O Corporativo é a soma dos negócios, e só quem enxerga todos eles
        // pode vê-lo: um consolidado dos negócios do escopo de alguém seria um
        // consolidado parcial lido como o da cooperativa.
        $podeCorporativo = Auth::veTudo($u);
        // AGRUPAR é de todo perfil com dois ou mais negócios (cliente, 2026-11-26:
        // "não consigo mais selecionar mais de um negócio" — o pedido de 2026-11-24
        // nunca restringiu por perfil; a trava fora herdada do Corporativo). Quem
        // não enxerga todos soma só os SEUS negócios ($negocios já é o escopo dele).
        $podeAgrupar = count($negocios) >= 2;
        $comuns = [
            'extraido_em' => $fech['extraido_em'],
            'fonte' => $fech['fonte'],
            'negocios' => $negocios,
            'pode_corporativo' => $podeCorporativo,
            'pode_agrupar' => $podeAgrupar,
        ];
        $grupoDoEscopo = !$podeCorporativo && $podeAgrupar && !empty($_GET['corporativo'])
            ? self::grupoDoEscopo((string)($_GET['negocios'] ?? ''), $ids)
            : null;
        if ($grupoDoEscopo) {
            $somados = array_values(array_filter($negocios, static fn ($n) => in_array((int)$n['id'], $grupoDoEscopo, true)));
            Json::ok(self::propostaNoQuadro(self::paraTela($this->montarCorporativo($somados, $ano, $corte))) + $comuns + [
                'pode_editar' => false,
                'grupo' => $grupoDoEscopo,
            ]);
            return;
        }
        if ($podeCorporativo && !empty($_GET['corporativo'])) {
            // AGRUPAMENTO (pedido do cliente, 2026-11-24: "preciso poder selecionar e
            // totalizar mais de um negócio — Leite mais Postos de resfriamento"): a
            // mesma soma do Corporativo, só com os negócios marcados. Ids fora da
            // lista com DRE são ignorados; nenhum válido = todos (o Corporativo).
            $grupo = self::grupoPedido((string)($_GET['negocios'] ?? ''), $ids);
            $somados = $grupo
                ? array_values(array_filter($negocios, static fn ($n) => in_array((int)$n['id'], $grupo, true)))
                : $negocios;
            Json::ok(self::propostaNoQuadro(self::paraTela($this->montarCorporativo($somados, $ano, $corte))) + $comuns + [
                'pode_editar' => false,
                'grupo' => $grupo,
            ]);
            return;
        }

        $filialId = (int)($_GET['filial_id'] ?? 0);
        Json::ok(self::propostaNoQuadro(self::paraTela($this->montar($negocioId, $ano, $corte, $filialId))) + $comuns + [
            'pode_editar' => $u['perfil'] !== 'LEITURA',
        ]);
    }

    /**
     * Os negócios de um AGRUPAMENTO (`?negocios=4,12`), na ordem da lista com DRE.
     * Devolve null quando o pedido não restringe nada — vazio, nenhum id válido ou
     * todos os negócios —, e a tela mostra o Corporativo.
     *
     * @param int[] $validos os negócios com DRE que o perfil enxerga
     * @return int[]|null
     */
    public static function grupoPedido(string $pedido, array $validos): ?array
    {
        $pedidos = array_map('intval', array_filter(array_map('trim', explode(',', $pedido)), 'strlen'));
        $grupo = array_values(array_filter($validos, static fn ($id) => in_array($id, $pedidos, true)));
        return $grupo && count($grupo) < count($validos) ? $grupo : null;
    }

    /**
     * O agrupamento de quem NÃO enxerga todos os negócios: os pedidos que estão no
     * escopo dele, no mínimo dois. "Todos os meus" vale como grupo — não é o
     * Corporativo da cooperativa, e o nome do agrupamento diz quais são. Menos de
     * dois válidos = null, e a tela fica no negócio.
     *
     * @param int[] $validos os negócios com DRE do escopo do usuário
     * @return int[]|null
     */
    public static function grupoDoEscopo(string $pedido, array $validos): ?array
    {
        $pedidos = array_map('intval', array_filter(array_map('trim', explode(',', $pedido)), 'strlen'));
        $grupo = array_values(array_filter($validos, static fn ($id) => in_array($id, $pedidos, true)));
        return count($grupo) >= 2 ? $grupo : null;
    }

    /**
     * O Corporativo: a DRE de TODOS os negócios somada, conta a conta.
     *
     * É conta derivada e não carga, de propósito: guardar a soma criaria a
     * segunda cópia do mesmo número, e a primeira recarga de um negócio a
     * deixaria velha sem ninguém ver. Cada negócio entra por `montar()` — a
     * MESMA conta da tela dele, com a proposta lançada (ou a projeção, onde
     * nada foi lançado) —, então o Corporativo nunca discorda da soma do que
     * as telas dos negócios mostram. Não se lança nada aqui: a proposta é
     * decidida nos negócios, e o Corporativo só a soma.
     *
     * Quantos negócios JÁ lançaram proposta vai junto: um consolidado em que
     * só três de onze lançaram se lê como a meta da cooperativa, quando é
     * metade projeção repetida.
     */
    private function montarCorporativo(array $negocios, int $ano, int $corte): array
    {
        $soma = [];
        $semMeta = [];
        $lancaram = 0;
        // Pessoas e benefícios: a soma dos negócios que têm quadro, e o quadro
        // base (último mês) — o Corporativo não planeja, só totaliza.
        $pessoasSoma = ['por_ano' => [], 'base' => null];
        $propostas = [];
        $propostaVisao = [];
        $desafioLinhas = [];
        $desafioTributos = [];
        $desafioVisao = [];
        $desafios = [];
        $tributosDre = [];
        $oportunidades = [];
        $estoque = [];
        $juros = [];
        $pessoalAlertas = [];
        $questionamentos = [];
        // Os rateios travados (16.1 e 14.3) saem do critério de todos: uma vez só.
        $rateios = Projecao::rateios($ano, $corte, $ano + 1);
        foreach ($negocios as $n) {
            $m = $this->montar((int)$n['id'], $ano, $corte, 0, $rateios);
            $tributosDre[(int)$n['id']] = $m['tributos_dre'];
            $q = $m['oportunidades']['quadro'];
            if ($q['linhas']) {
                $oportunidades[(int)$n['id']] = [
                    'mes_ate' => $q['mes_ate'], 'oportunidade' => $q['oportunidade'], 'risco' => $q['risco'],
                    'itens' => array_map(static fn ($l) => ['rotulo' => $l['nome']], array_slice($q['linhas'], 0, 3)),
                ];
            }
            if ($qs = $m['oportunidades']['questionamentos']) {
                $questionamentos[(int)$n['id']] = ['parou' => count($qs['parou']), 'comecou' => count($qs['comecou'])];
            }
            if ($j = $m['oportunidades']['juros']) {
                $juros[(int)$n['id']] = [
                    'socio' => $j['socio'], 'nao_socio' => $j['nao_socio'], 'total' => $j['total'],
                    'risco' => $j['risco'], 'acima' => count($j['acima']),
                    'maiores' => array_map(static fn ($c) => ['cliente' => $c['cliente'], 'valor' => $c['valor']],
                        array_slice($j['clientes'], 0, 3)),
                ];
            }
            if ($m['estoque']) {
                $e = $m['estoque'];
                $estoque[(int)$n['id']] = [
                    'perdas' => array_sum(array_map(static fn ($l) => $l['tipo'] === 'PERDA' ? $l['oportunidade'] : 0.0, $e['linhas'])),
                    'acerto' => array_sum(array_map(static fn ($l) => $l['tipo'] === 'ACERTO' ? $l['oportunidade'] : 0.0, $e['linhas'])),
                    'parado' => $e['caixa'], 'parado_dias' => $e['parado']['dias'] ?? null,
                    'resultado' => $e['resultado'], 'total' => $e['total'],
                ];
            }
            $propostas[(int)$n['id']] = self::resumoDaProposta($m['linhas']);
            $propostaVisao[(int)$n['id']] = self::propostaDaVisao($m['linhas']);
            if (!empty($m['desafio_linhas'])) {
                $desafioLinhas[] = $m['desafio_linhas'];
                $desafioTributos[(int)$n['id']] = $m['desafio_tributos_dre'] ?? null;
            }
            // O desafio do ADMIN nas mesmas contas (`desafio_linhas` só vem para ele).
            $desafioVisao[(int)$n['id']] = !empty($m['desafio_linhas']) ? self::propostaDaVisao($m['desafio_linhas']) : [];
            if (isset($m['desafio']['desafio'])) {
                $desafios[(int)$n['id']] = $m['desafio']['desafio'];
            }
            if ($m['pessoal_alertas']) {
                $nomesFilial = array_column($m['filiais'], 'nome', 'id');
                $codsFilial = array_column($m['filiais'], 'cod_filial', 'id');
                $pessoalAlertas[] = [
                    'negocio_id' => (int)$n['id'],
                    'negocio' => $n['rotulo'] ?? $n['nome'] ?? '',
                    'itens' => array_map(static fn ($a) => $a + [
                        'filial' => $a['filial_id'] ? trim(($codsFilial[$a['filial_id']] ?? '') . ' ' . ($nomesFilial[$a['filial_id']] ?? '')) : null,
                    ], $m['pessoal_alertas']),
                ];
            }
            foreach ($m['linhas'] as $l) {
                $c = $l['codigo'];
                if (!isset($soma[$c])) {
                    $soma[$c] = [
                        'codigo' => $c, 'nome' => $l['nome'], 'nivel' => $l['nivel'],
                        'pai_codigo' => $l['pai_codigo'], 'percentual' => $l['percentual'],
                        'realizado' => 0.0, 'projetado' => 0.0, 'projecao' => 0.0,
                        'proposta' => 0.0, 'proposta_origem' => 'CONSOLIDADO', 'proposta_lancado' => null,
                    ];
                }
                foreach (['realizado', 'projetado', 'projecao'] as $k) {
                    $soma[$c][$k] += (float)$l[$k];
                }
                // Linha sem regra de proposta (Despesas Totais, os percentuais)
                // continua sem regra no consolidado: somar os que têm e calar
                // sobre os que não têm seria inventar o número.
                if ($soma[$c]['proposta'] !== null) {
                    $soma[$c]['proposta'] = ($l['proposta'] ?? null) === null
                        ? null : $soma[$c]['proposta'] + (float)$l['proposta'];
                    if ($soma[$c]['proposta'] === null) {
                        $soma[$c]['proposta_origem'] = $l['proposta_origem'];
                    }
                }
            }
            $semMeta = array_merge($semMeta, $m['meses_sem_meta']);
            if ($m['atualizado_em']) {
                $lancaram++;
            }
            if ($m['pessoas']['base']) {
                $pessoasSoma['base'] = $m['pessoas']['base'];
            }
            foreach ($m['pessoas']['por_ano'] as $anoP => $calc) {
                if (!$calc) {
                    continue;
                }
                $acc = &$pessoasSoma['por_ano'][$anoP];
                $acc ??= ['colaboradores' => 0.0, 'negocios' => 0,
                    'mensal' => array_fill_keys(['alimentacao', 'assiduidade', 'resultado', 'total'], 0.0),
                    'anual' => array_fill_keys(['alimentacao', 'assiduidade', 'resultado', 'total'], 0.0),
                    'sem_resultado_anual' => 0.0, 'maximo_anual' => 0.0];
                $acc['colaboradores'] += $calc['colaboradores'];
                $acc['negocios']++;
                foreach (['mensal', 'anual'] as $grupo) {
                    foreach ($calc[$grupo] as $k => $x) {
                        $acc[$grupo][$k] += $x;
                    }
                }
                $acc['sem_resultado_anual'] += $calc['sem_resultado_anual'];
                $acc['maximo_anual'] += $calc['maximo_anual'];
                unset($acc);
            }
        }
        return [
            'corporativo' => true,
            'ano' => $ano,
            'ano_planejamento' => $ano + 1,
            'mes_fechado_ate' => $corte,
            'negocio_id' => 0,
            'meses_sem_meta' => array_values(array_unique($semMeta)),
            'versao' => 0,
            'modo' => 'NEGOCIO',
            'filiais' => [],
            'filiais_corte' => null,
            'filial' => null,
            'atualizado_em' => null,
            'atualizado_por' => null,
            'negocios_consolidados' => count($negocios),
            'negocios_com_proposta' => $lancaram,
            'pessoas' => $pessoasSoma,
            // A visão do ano por negócio e o total — o mesmo quadro do Painel Balanço.
            'visao_ano_negocios' => self::visaoComPropostas(
                self::visaoComPropostas(
                    $this->visaoDoAno(array_map(static fn ($n) => (int)$n['id'], $negocios), $ano, $corte),
                    $propostaVisao
                ),
                $desafioVisao,
                'desafio'
            ),
            'visao_ano_total' => self::comProposta(
                self::comProposta(
                    $this->visaoDoAnoTotal(array_map(static fn ($n) => (int)$n['id'], $negocios), $ano, $corte),
                    self::somaDasPropostas($propostaVisao)
                ),
                array_filter($desafioVisao) ? self::somaDasPropostas($desafioVisao) : [],
                'desafio'
            ),
            'desafio' => $this->desafioCorporativo($ano + 1, $propostas, $desafios),
            'tributos_dre' => Reforma::somarComoEComoFica($tributosDre),
            // A coluna Desafio SOMADA (cliente, 2026-11-27): no Corporativo e no agrupamento,
            // o desafio de cada negócio somado conta a conta — só leitura. Só ADMIN (os
            // `desafio_linhas` de cada negócio só vêm para ele).
            'desafio_linhas' => $desafioLinhas ? self::somarLinhasDesafio($desafioLinhas) : [],
            'desafio_lancados' => [],
            'desafio_tributos_dre' => $desafioLinhas ? Reforma::somarComoEComoFica($desafioTributos) : null,
            // Por negócio, os totais do quadro único (itens + eventos pontuais).
            'oportunidades_negocios' => $oportunidades,
            // Por negócio, o montante do estoque (perdas, acerto, parados).
            'estoque_negocios' => $estoque,
            // Por negócio, os juros recebidos e os lançamentos acima de R$ 30 mil.
            'juros_negocios' => $juros,
            // Quantos questionamentos (parou / começou, 2025 × 2026) por negócio.
            'questionamentos_negocios' => $questionamentos,
            // Onde as Despesas com Pessoal ficaram abaixo do mínimo, por negócio.
            'pessoal_alertas_negocios' => $pessoalAlertas,
            // O nível 3 é a soma dos negócios, conta reduzida a conta reduzida.
            'nivel3' => Projecao::nivel3(array_map(static fn ($n) => (int)$n['id'], $negocios), $ano),
            'linhas' => array_values($soma),
        ];
    }

    /**
     * Tudo o que a tela precisa de um negócio: a DRE, a proposta (do negócio
     * ou somada das filiais, conforme o modo), as premissas, as filiais e a
     * filial aberta. Mora num método só porque as gravações respondem com a
     * tela inteira — recalcular de outro jeito ali seria a segunda cópia da
     * regra que esta seção evita desde o começo.
     */
    private function montar(int $negocioId, int $ano, int $corte, int $filialId = 0, ?array $rateios = null): array
    {
        $linhas = $this->linhasDaDre($negocioId, $ano, $corte);

        // Mês aberto SEM orçamento lançado deixa a projeção incompleta. A tela
        // precisa dizer QUAL mês falta: somar o que tem e apresentar como ano é
        // o erro silencioso clássico de controladoria.
        $comMeta = Database::todos(
            'SELECT DISTINCT mes FROM dre_valor
              WHERE negocio_id = ? AND ano = ? AND tipo = \'META\' AND mes > ? AND valor <> 0',
            [$negocioId, $ano, $corte]
        );
        $temMeta = array_map(static fn ($l) => (int)$l['mes'], $comMeta);
        $semMeta = array_values(array_diff(range($corte + 1, 12), $temMeta));

        $proj = $this->projecaoDoNegocio($negocioId, $ano + 1);
        $projId = (int)($proj['id'] ?? 0);
        $modo = $proj['modo'] ?? 'NEGOCIO';
        $filiais = $this->filiaisDoNegocio($negocioId);

        // Em que corte a DRE das filiais deste negócio foi extraída. Ela é
        // ACUMULADA e só vale para um corte; quando o fechamento anda e o
        // negócio é recarregado antes das filiais (a re-extração é cara: uma
        // coluna por filial), as duas ficam em meses diferentes e a tela
        // mostraria realizado de setembro ao lado do de outubro sem dizer. O
        // número vai para a tela, que avisa — esconder as filiais, em troca,
        // faria o total do modo filial sumir calado.
        $cortesFilial = array_map('intval', array_column(Database::todos(
            'SELECT DISTINCT v.corte
               FROM dre_valor_filial v JOIN filial f ON f.id = v.filial_id
              WHERE f.negocio_id = ? AND v.ano = ?',
            [$negocioId, $ano]
        ), 'corte'));
        $filiaisCorte = $cortesFilial ? min($cortesFilial) : null;

        // As premissas digitadas são do NEGÓCIO e de CADA ANO. As derivadas (Cresc.
        // Total, Cresc. Real, % Custos) só existem no ano da proposta, porque
        // saem das linhas dela — por isso `premissas()` recebe as digitadas
        // desse ano, e a tela ganha as dos outros em `premissas_por_ano`.
        $anoProposta = $ano + 1;
        $anosPremissa = $this->anosDasPremissas($anoProposta);
        $porAno = $this->premissasPorAno($negocioId, $anosPremissa);
        $digitadas = $porAno[(string)$anoProposta];
        // As premissas decidem quanto a Receita e as Despesas com Pessoal crescem
        // por omissão (`Projecao::crescimentosPadrao`). A filial herda as do negócio:
        // o mesmo conjunto vale nas duas contas, ou a soma das filiais deixaria de
        // fechar com o negócio.
        // E a diretriz do ano manda por cima (IPCA, PIB, pessoal), com a conta da
        // despesa de pessoal no lugar do reajuste puro — `crescimentosDoNegocio`
        // é a mesma do Painel.
        $p85 = 0.0;
        foreach ($linhas as $l) {
            if ($l['codigo'] === Projecao::LINHA_PESSOAL) {
                $p85 = (float)$l['projecao'];
            }
        }
        [$cresc, $pessoalDiretriz, $minimoPessoal] = Projecao::crescimentosDoNegocio(
            $anoProposta,
            $digitadas,
            $p85,
            Projecao::dadosDePessoal([$negocioId], $ano, $anoProposta)[$negocioId] ?? null
        );
        // O DRIVER do faturamento (pedido do cliente, 2026-11-23): nos negócios que
        // derivam de outro (Pecuária, Leite, Postos de leite, Rações), a Receita de
        // Vendas padrão — e o piso dela — é a do volume, não IPCA + PIB.
        $codNegocio = (string)(Database::um('SELECT cod_negocio FROM negocio WHERE id = ?', [$negocioId])['cod_negocio'] ?? '');
        [$cresc, $driver] = DesafioDrivers::aplicar($cresc, $codNegocio, $anoProposta, $linhas);

        // A proposta de CADA filial, sempre — ela serve a duas perguntas: no
        // modo filial é ela que forma o total do negócio; no modo negócio é
        // ela que diz quanto já foi distribuído.
        // Os tributos de 2026 do negócio (PIS/COFINS, mistura sócio × não sócio) e
        // a taxa de PIS/COFINS sobre a Receita de Vendas: o formato de 2027 da tela
        // (a CBS fora das deduções) e o que se lança na 1.1 e na 2.3. A filial usa a
        // taxa do negócio.
        $tributacao = Oportunidades::tributos([$negocioId], $ano)[$negocioId] ?? null;
        $taxaPc = Reforma::pisCofinsDoNegocio($linhas, $tributacao, $corte)['taxa'];
        // A 3227 (acerto do PPR) de cada filial: a 8.5 da filial cresce sobre a
        // projeção DELA sem a 3227 (`Projecao::crescimentosDaFilial`).
        $acertosFilial = Projecao::pprRealizado([$negocioId], $ano)[$negocioId]['filiais'] ?? [];
        // A depreciação carregada do ano da proposta (8.10, conta 3128): travada
        // no negócio e em cada filial (`Projecao::fixosDaDepreciacao`).
        $depreciacao = Projecao::depreciacao([$negocioId], $ano + 1)[$negocioId] ?? null;
        $porFilial = [];
        foreach ($filiais as $f) {
            $base = $this->linhasDaFilial((int)$f['id'], $ano, $corte);
            $lf = Projecao::aplicar(
                $base,
                $this->lancados($projId, (int)$f['id']),
                Projecao::crescimentosDaFilial($cresc, $base, (float)($acertosFilial[(int)$f['id']] ?? 0.0), $minimoPessoal),
                $taxaPc,
                Projecao::fixosDaDepreciacao($depreciacao, (int)$f['id'], $codNegocio)
            );
            $porFilial[(int)$f['id']] = $lf;
        }
        // O RATEIO ADMINISTRATIVO (16.1) do negócio: a parte dele no critério de
        // todos (`Projecao::rateioAdm`), travada. Nas filiais, dividido pela parte
        // de cada uma no critério do negócio — que só se conhece agora, com as
        // propostas delas.
        // E o RATEIO NEGÓCIO (14.3), pelos critérios da planilha de cada centro de
        // custo (`Projecao::rateioNegocios`) — no negócio e em cada filial.
        // `$rateios` vem pronto do Corporativo, que monta os 11 negócios de uma vez.
        $rateios ??= Projecao::rateios($ano, $corte, $ano + 1);
        $rateioAdm = $rateios['adm'];
        $rateioNeg = $rateioAdm['negocios'][$negocioId] ?? null;
        $rateioNegocio = $rateios['neg']['negocios'][$negocioId] ?? null;
        // E o RATEIO FINANCEIRO (14.2), da carga por negócio; nas filiais, pela
        // projeção de 2026 da 14.2 de cada uma.
        $rateioFin = Projecao::rateioFinDoNegocio($negocioId, $ano + 1);
        // E o RATEIO MATRIZ/CENTRAL (14.1), zerado em todo negócio e filial.
        $critFilial = array_map([Projecao::class, 'criterioRateioAdm'], $porFilial);
        $totFilial = array_sum($critFilial);
        $finFilial = array_map(static fn ($lf) => (float)(array_column($lf, 'projecao', 'codigo')[Projecao::LINHA_RATEIO_FIN] ?? 0.0), $porFilial);
        $totFin = array_sum($finFilial);
        foreach ($porFilial as $fid => $lf) {
            $porFilial[$fid] = Projecao::fixar($lf,
                Projecao::fixosDoRateioMatriz()
                + Projecao::fixosDoRateioAdm($rateioNeg, $totFilial > 0 ? $critFilial[$fid] / $totFilial : 0.0)
                + Projecao::fixosDoRateioNeg($rateioNegocio, (int)$fid)
                + Projecao::fixosDoRateioFin($rateioFin, abs($totFin) > 0 ? $finFilial[$fid] / $totFin : 0.0));
        }
        $propostasFilial = array_map(
            static fn ($ls) => array_column($ls, 'proposta', 'codigo'),
            $porFilial
        );

        // O DESAFIO é a proposta padrão: a mesma base e os mesmos crescimentos,
        // sem nenhum lançamento — o ponto de partida de onde a proposta anda. Com
        // uma diferença (2026-11-23): nele as despesas operacionais acompanham a
        // receita (`Projecao::acompanhamReceitaNoDesafio`).
        $fixos = Projecao::fixosDaDepreciacao($depreciacao, 0, $codNegocio) + Projecao::fixosDoRateioMatriz()
            + Projecao::fixosDoRateioAdm($rateioNeg)
            + Projecao::fixosDoRateioNeg($rateioNegocio) + Projecao::fixosDoRateioFin($rateioFin);
        // E, desde 2026-11-25, com o que o ADMIN lançou na coluna Desafio da DRE,
        // conta a conta, pelo mesmo motor da proposta (`DesafioAjuste`).
        $lancDesafio = DesafioAjuste::lancados($negocioId, $ano + 1);
        $linhasDesafio = Projecao::aplicar($linhas, $lancDesafio, $cresc, $taxaPc, $fixos, Projecao::acompanhamReceitaNoDesafio());
        $linhas = $modo === 'FILIAL' && $filiais
            ? Projecao::somarFiliais($linhas, $propostasFilial, $fixos)
            : Projecao::aplicar($linhas, $this->lancados($projId), $cresc, $taxaPc, $fixos);
        // O MÍNIMO das Despesas com Pessoal (pedido do cliente, 2026-11-23): no
        // negócio, o padrão com o quadro planejado; na filial, o quadro atual
        // dela × o custo por pessoa dela × o reajuste. Abaixo dele a linha grava
        // e sai com o ⚠.
        $quadros = Projecao::quadroDasFiliais(array_keys($porFilial), $ano);
        [$linhas, $porFilial] = Projecao::minimoPessoalDoNegocio(
            $linhas, $porFilial, $minimoPessoal, $quadros, $modo === 'FILIAL' && (bool)$filiais, $acertosFilial
        );
        // Onde está abaixo, para a tela listar: as filiais só no modo filial,
        // que é quando o total do negócio sai delas.
        $alertasPessoal = Projecao::alertasPessoal($linhas, $modo === 'FILIAL' ? $porFilial : []);
        // Quanto das filiais já está lançado, conta a conta: é o que permite à
        // tela mostrar o resíduo a distribuir sem travar nada — distribuir não
        // é requisito para salvar (decisão do cliente, 2026-11-05).
        foreach ($linhas as $i => $l) {
            $soma = 0.0;
            foreach ($propostasFilial as $p) {
                $soma += $p[$l['codigo']] ?? 0.0;
            }
            $linhas[$i]['distribuido'] = $filiais ? $soma : null;
        }
        $premissas = Projecao::premissas($linhas, $digitadas);

        // A filial aberta na tela, quando há uma. O id vem por PARÂMETRO e não
        // da querystring: quem grava também responde com a tela, e ali não há
        // `$_GET` nenhum — lê-lo aqui zerava a filial em toda gravação.
        $filialAberta = null;
        if ($filialId && isset($porFilial[$filialId])) {
            $filialAberta = [
                'id' => $filialId,
                'linhas' => $porFilial[$filialId],
                // IPCA, PIB e o crescimento desejado são do NEGÓCIO e a filial os
                // herda (pedido do cliente, 2026-11-16): o conjunto digitado é o
                // mesmo da linha de cima, e os três derivados saem das linhas
                // DELA.
                'premissas' => Projecao::premissas($porFilial[$filialId], $digitadas),
                // O PIS/COFINS da filial sai sempre pela taxa do negócio (a carga
                // de tributos é por negócio): corte −1 desliga o valor da carga.
                'tributos_dre' => Reforma::comoEComoFica($porFilial[$filialId], $tributacao, $corte, $taxaPc),
                // Os eventos pontuais DA FILIAL (pedido do cliente, 2026-11-18),
                // da DRE mensal dela (`dre_valor_filial_mes`).
                'picos' => Oportunidades::picos($negocioId, $ano, $corte, $filialId),
                'quadro' => Oportunidades::quadro($negocioId, $ano, $corte, $filialId),
                // Perdas, acerto e estoque parado DA FILIAL.
                'estoque' => Oportunidades::estoque($negocioId, $ano, $corte, $filialId),
                // Juros recebidos DA FILIAL e os lançamentos acima de R$ 30 mil.
                'juros' => Oportunidades::juros($negocioId, $ano,
                    (string)(Database::um('SELECT cod_filial FROM filial WHERE id = ?', [$filialId])['cod_filial'] ?? '')),
                // O nível 3 (conta reduzida) DA FILIAL.
                'nivel3' => Projecao::nivel3([$negocioId], $ano, $filialId),
                // O que parou ou começou de 2025 para 2026 NESTA filial.
                'questionamentos' => Oportunidades::questionamentos($negocioId, $ano, $corte, $filialId),
            ];
        }

        return [
            'ano' => $ano,
            'ano_planejamento' => $ano + 1,
            'mes_fechado_ate' => $corte,
            'negocio_id' => $negocioId,
            'meses_sem_meta' => $semMeta,
            'versao' => (int)($proj['versao'] ?? 0),
            'modo' => $modo,
            'premissas' => $premissas,
            // Os anos em que se lançam premissas e o que está lançado em cada
            // um, de uma vez: trocar de ano na tela não pede ida ao servidor.
            'anos_premissa' => $anosPremissa,
            'premissas_por_ano' => $porAno,
            // Só o digitado pelo negócio: separa o IPCA/PIB sugerido pela diretriz
            // do lançado.
            'premissas_digitadas_por_ano' => $this->premissasDigitadasPorAno($negocioId, $anosPremissa),
            // O que a diretriz fixa em cada ano (só leitura na tela) e a conta da
            // despesa de pessoal de 2027 que ela produz.
            'diretriz' => $this->diretrizesDosAnos($anosPremissa),
            'pessoal_diretriz' => $pessoalDiretriz,
            // O mínimo da 8.5 (quadro planejado × custo × reajuste) e onde ela
            // ficou abaixo dele — negócio (filial_id 0) e filiais.
            'pessoal_minimo' => $minimoPessoal,
            'pessoal_alertas' => $alertasPessoal,
            // O rateio administrativo travado na 16.1: a parte do negócio e o critério.
            'rateio_adm' => $rateioNeg ? [
                'parte' => $rateioNeg['parte'], 'criterio' => $rateioNeg['criterio'],
                'criterio_total' => $rateioAdm['criterio_total'], 'total' => $rateioAdm['total'],
                'valor' => $rateioNeg['valor'], 'linhas' => Projecao::CRITERIO_RATEIO_ADM,
            ] : null,
            // O rateio negócio travado na 14.3: quanto veio de cada centro de custo.
            'rateio_neg' => $rateioNegocio ? [
                'valor' => $rateioNegocio['valor'], 'centros' => $rateioNegocio['centros'],
                'total' => $rateios['neg']['total'],
            ] : null,
            // Pessoas e benefícios do NEGÓCIO, por ano (a filial não tem o seu: o
            // quadro e os valores são do negócio, que já traz a parte dele).
            'pessoas' => $this->pessoasDoNegocio($negocioId, $ano, $anosPremissa),
            // O nível 3 (conta reduzida), só leitura (pedido do cliente, 2026-11-21).
            'nivel3' => Projecao::nivel3([$negocioId], $ano),
            // Realizado × planejado do ano corrente e a visão realista, para a
            // receita bruta e o resultado líquido. Só leitura.
            'visao_ano' => self::comProposta(
                self::comProposta($this->visaoDoAno([$negocioId], $ano, $corte)[$negocioId] ?? null, self::propostaDaVisao($linhas)),
                self::propostaDaVisao($linhasDesafio),
                'desafio'
            ),
            // O desafio da Controladoria para o ano da proposta, ao lado do que a
            // proposta dá hoje. Só leitura.
            'desafio' => $this->desafioDoNegocio($negocioId, $ano + 1, $linhas, $linhasDesafio, $driver, $lancDesafio),
            // De onde veio a Receita de Vendas padrão (driver de volume ou premissas).
            'driver' => $driver,
            // Como é × como fica: o PIS/COFINS sai das deduções e vira a linha da
            // CBS acima da Receita Bruta (pedido do cliente, 2026-11-18).
            'tributos_dre' => Reforma::comoEComoFica($linhas, $tributacao, $corte),
            // Oportunidades e riscos de 2027: itens não recorrentes de 2026 e meses
            // fora do padrão nas despesas. Só leitura.
            'oportunidades' => [
                'itens' => Oportunidades::itens([$negocioId], $ano)[$negocioId] ?? null,
                'picos' => Oportunidades::picos($negocioId, $ano, $corte),
                // O quadro único (itens + eventos, com os meses e os totais).
                'quadro' => Oportunidades::quadro($negocioId, $ano, $corte),
                'grupos' => Oportunidades::GRUPOS,
                // Juros recebidos (sócio e não sócio) e onde se concentram.
                'juros' => Oportunidades::juros($negocioId, $ano),
                // O que parou ou começou de 2025 para 2026 (conta reduzida).
                'questionamentos' => Oportunidades::questionamentos($negocioId, $ano, $corte),
            ],
            // Cenário de faturamento carregado de planilha do negócio (pedido do
            // cliente, 2026-11-19): de onde veio a Receita de Vendas da proposta.
            'cenario' => $this->cenarioDoNegocio($negocioId, $ano + 1),
            // O montante de oportunidades do ESTOQUE (perdas e acerto acima da média,
            // produtos parados), totalizado abaixo da DRE (pedido do cliente,
            // 2026-11-18). Só leitura.
            'estoque' => Oportunidades::estoque($negocioId, $ano, $corte),
            'filiais' => $filiais,
            'filiais_corte' => $filiaisCorte,
            'filial' => $filialAberta,
            'atualizado_em' => $proj['atualizado_em'] ?? null,
            'atualizado_por' => $proj['atualizado_por_nome'] ?? null,
            'linhas' => $linhas,
        ] + $this->colunaDesafio($linhasDesafio, $lancDesafio, $tributacao, $corte);
    }

    /**
     * A coluna Desafio da DRE (pedido do cliente, 2026-11-25): as linhas do desafio
     * e o que o ADMIN lançou nelas — SÓ para o perfil ADMIN ("só estará visível
     * para os administradores"). Para os demais a resposta nem as traz.
     */
    private function colunaDesafio(array $linhasDesafio, array $lancados, ?array $tributacao, int $corte): array
    {
        if ((Auth::usuario()['perfil'] ?? '') !== 'ADMIN') {
            return [];
        }
        return [
            'desafio_linhas' => $linhasDesafio,
            'desafio_lancados' => array_keys($lancados),
            'desafio_tributos_dre' => Reforma::comoEComoFica($linhasDesafio, $tributacao, $corte),
        ];
    }

    /** O cenário de planilha lançado na proposta do ano, se houver. */
    private function cenarioDoNegocio(int $negocioId, int $anoPlan): ?array
    {
        $c = Database::um('SELECT * FROM projecao_cenario WHERE negocio_id = ? AND ano = ? ORDER BY aplicado_em DESC LIMIT 1',
            [$negocioId, $anoPlan]);
        if (!$c) {
            return null;
        }
        $itens = [];
        foreach (json_decode($c['itens'], true) ?: [] as $nome => [$p, $r, $o]) {
            $itens[] = ['nome' => $nome, 'pessimista' => (float)$p, 'realista' => (float)$r, 'otimista' => (float)$o];
        }
        return [
            'conta' => $c['conta'], 'cenario' => $c['cenario'], 'fonte' => $c['fonte'],
            'taxa' => (float)$c['taxa_pis_cofins'], 'faturamento' => (float)$c['faturamento_antigo'],
            'receita_vendas' => (float)$c['receita_vendas'], 'cbs' => (float)$c['faturamento_antigo'] - (float)$c['receita_vendas'],
            'aplicado_em' => $c['aplicado_em'], 'itens' => $itens,
            'totais' => [
                'pessimista' => array_sum(array_column($itens, 'pessimista')),
                'realista' => array_sum(array_column($itens, 'realista')),
                'otimista' => array_sum(array_column($itens, 'otimista')),
            ],
        ];
    }

    /**
     * O cabeçalho do desafio do ano (`desafio_ano`: meta de margem, método,
     * fonte). Null quando não há desafio para o ano — a tela some com o cartão
     * em vez de mostrar uma meta zerada.
     *
     * Desde 2026-11-23 o desafio é CALCULADO, não carregado (pedido do cliente:
     * "gere um novo desafio… e a partir dali ele continua fazendo a projeção"):
     * é a proposta padrão de cada negócio — a projeção do ano com as premissas
     * do painel e os drivers de volume (`DesafioDrivers`), sem lançamento. Quem
     * o monta é `montar`, que já tem tudo à mão; aqui fica só o cabeçalho.
     * `negocio_desafio` (o desafio carregado de 18/11) não é mais lido.
     */
    private function desafioDoAno(int $anoPlan): ?array
    {
        $cab = Database::um('SELECT ano, meta_margem, metodo, fonte, decidido_em FROM desafio_ano WHERE ano = ?', [$anoPlan]);
        if (!$cab) {
            return null;
        }
        return [
            'ano' => (int)$cab['ano'],
            'meta_margem' => (float)$cab['meta_margem'],
            'metodo' => $cab['metodo'],
            'fonte' => $cab['fonte'],
            'decidido_em' => $cab['decidido_em'],
        ];
    }

    /** Os três números do desafio a partir das linhas da proposta padrão, com a nota do driver. */
    private static function desafioDasLinhas(array $linhasDesafio, ?array $driver): array
    {
        $r = self::resumoDaProposta($linhasDesafio);
        return self::comMargem([
            'receita_bruta' => $r['receita_bruta'],
            'receita_liquida' => $r['receita_liquida'],
            'resultado' => $r['resultado'],
        ]) + [
            'nota' => $driver['nota'] ?? 'Projeção do ano × premissas (IPCA + PIB + crescimento).',
            'driver' => $driver['driver'] ?? 'PREMISSAS',
        ];
    }

    /** O desafio de UM negócio e a proposta dele hoje, lado a lado. */
    private function desafioDoNegocio(int $negocioId, int $anoPlan, array $linhas, array $linhasDesafio, ?array $driver, array $lancDesafio = []): ?array
    {
        $d = $this->desafioDoAno($anoPlan);
        if (!$d) {
            return null;
        }
        $desafio = self::desafioDasLinhas($linhasDesafio, $driver);
        $prop = self::resumoDaProposta($linhas);
        $trib = Oportunidades::tributos([$negocioId], $anoPlan - 1)[$negocioId] ?? null;
        $escada = self::escadaDoDesafio($linhasDesafio, $linhas);
        // O desafio da coluna da DRE (2026-11-25) já está nas linhas: o cartão só
        // diz se houve lançamento da administração.
        $desafio['ajustado'] = (bool)$lancDesafio;
        $desafio['pis_cofins'] = self::pisCofinsSobre($desafio['receita_bruta'], $trib);
        $prop['pis_cofins'] = self::pisCofinsSobre($prop['receita_bruta'], $trib);
        $u = Auth::usuario();
        return $d + ['desafio' => $desafio, 'proposta' => $prop, 'tributos' => $trib,
            'escada' => $escada, 'pode_ajustar' => ($u['perfil'] ?? '') === 'ADMIN'];
    }

    /**
     * A ESCADA da DRE no cartão do desafio (pedido do cliente, 2026-11-18):
     * faturamento, receita líquida, margem bruta, despesas, resultado antes dos
     * rateios (linha 13), rateios do negócio (14), resultado do negócio (15),
     * rateios administrativos (16), resultado líquido (18) e margem líquida — do
     * desafio e da proposta, na estrutura NOVA da DRE (a que vale em 2027).
     *
     * Desde que o desafio é a proposta padrão (2026-11-23), os degraus do meio
     * saem da DRE dele, linha a linha — nenhum é mais estimado. `estimados` fica
     * vazio (a tela ainda sabe marcá-los, se um desafio carregado voltar).
     */
    private static function escadaDoDesafio(array $linhasDesafio, array $linhas): array
    {
        $degraus = static function (array $ls): array {
            $p = array_column($ls, 'proposta', 'codigo');
            $v = static fn ($c) => isset($p[$c]) && $p[$c] !== null ? (float)$p[$c] : null;
            $pct = static fn ($a, $b) => $a !== null && $b ? $a / $b * 100 : null;
            $rol = $v('3');
            return [
                'receita_bruta' => $v('1'), 'receita_liquida' => $rol,
                'lucro_bruto' => $v('5'), 'margem_bruta' => $pct($v('5'), $rol),
                'despesas' => $v('7'),
                'resultado_antes_rateios' => $v(Projecao::LINHA_APOS_IMPOSTOS),
                'margem_antes_rateios' => $pct($v(Projecao::LINHA_APOS_IMPOSTOS), $rol),
                'rateios_negocio' => $v(Projecao::LINHA_RATEIOS_NEGOCIO),
                'resultado_negocio' => $v(Projecao::LINHA_RESULTADO_NEGOCIO),
                'margem_negocio' => $pct($v(Projecao::LINHA_RESULTADO_NEGOCIO), $rol),
                'rateios_adm' => $v(Projecao::LINHA_RATEIOS_ADM),
                'resultado' => $v(Projecao::LINHA_RESULTADO),
                'margem' => $pct($v(Projecao::LINHA_RESULTADO), $rol),
            ];
        };
        return ['desafio' => $degraus($linhasDesafio), 'proposta' => $degraus($linhas), 'estimados' => []];
    }

    /**
     * O desafio no Corporativo: cada negócio (desafio × proposta), o total, e a
     * meta de margem líquida da direção sobre os dois totais — quanto resultado
     * ela pediria e quanto falta.
     *
     * @param array<int,array> $propostas resumo da proposta de cada negócio consolidado
     */
    private function desafioCorporativo(int $anoPlan, array $propostas, array $desafios): ?array
    {
        $d = $this->desafioDoAno($anoPlan);
        if ($d) {
            $d['por_negocio'] = $desafios;
        }
        if (!$d) {
            return null;
        }
        $zero = ['receita_bruta' => 0.0, 'receita_liquida' => 0.0, 'resultado' => 0.0];
        $totDesafio = $zero;
        $totProposta = $zero + ['resultado_negocio' => 0.0, 'resultado_antes_rateios' => 0.0];
        $pcDesafio = 0.0;
        $pcProposta = 0.0;
        $tribs = Oportunidades::tributos(array_keys($propostas), $anoPlan - 1);
        $tribTotal = ['venda_socio' => 0.0, 'venda_nao_socio' => 0.0, 'pis' => 0.0, 'cofins' => 0.0, 'icms' => 0.0];
        $negocios = [];
        foreach ($propostas as $id => $p) {
            $desafio = $d['por_negocio'][$id] ?? null;
            foreach (array_keys($zero) as $k) {
                $totProposta[$k] += (float)($p[$k] ?? 0);
                if ($desafio) {
                    $totDesafio[$k] += $desafio[$k];
                }
            }
            $totProposta['resultado_negocio'] += (float)($p['resultado_negocio'] ?? 0);
            $totProposta['resultado_antes_rateios'] += (float)($p['resultado_antes_rateios'] ?? 0);
            $trib = $tribs[$id] ?? null;
            if ($desafio) {
                $desafio['pis_cofins'] = self::pisCofinsSobre($desafio['receita_bruta'], $trib);
                $pcDesafio += $desafio['pis_cofins']['total'] ?? 0.0;
            }
            $p['pis_cofins'] = self::pisCofinsSobre($p['receita_bruta'], $trib);
            $pcProposta += $p['pis_cofins']['total'] ?? 0.0;
            if ($trib) {
                foreach (array_keys($tribTotal) as $k) {
                    $tribTotal[$k] += $trib[$k];
                }
            }
            $negocios[$id] = ['desafio' => $desafio, 'proposta' => $p, 'tributos' => $trib];
        }
        $vendas = $tribTotal['venda_socio'] + $tribTotal['venda_nao_socio'];
        $tribTotal['parte_nao_socio'] = $vendas > 0 ? $tribTotal['venda_nao_socio'] / $vendas : null;
        $tribTotal['taxa_pis_cofins'] = $vendas > 0 ? ($tribTotal['pis'] + $tribTotal['cofins']) / $vendas : null;
        $tribTotal['taxa_pis_cofins_nao_socio'] = $tribTotal['venda_nao_socio'] > 0
            ? ($tribTotal['pis'] + $tribTotal['cofins']) / $tribTotal['venda_nao_socio'] : null;
        $tribTotal['mes_ate'] = $tribs ? reset($tribs)['mes_ate'] : null;
        $meta = $d['meta_margem'];
        $alvo = static fn (array $t) => [
            'resultado_meta' => $t['receita_liquida'] * $meta / 100,
            'falta' => $t['receita_liquida'] * $meta / 100 - $t['resultado'],
        ];
        unset($d['por_negocio']);
        return $d + [
            'negocios' => $negocios,
            'total' => [
                'desafio' => self::comMargem($totDesafio) + $alvo($totDesafio) + ['pis_cofins' => ['total' => $pcDesafio]],
                'proposta' => self::comMargem($totProposta) + $alvo($totProposta) + [
                    'pis_cofins' => ['total' => $pcProposta],
                    'margem_negocio' => $totProposta['receita_liquida']
                        ? $totProposta['resultado_negocio'] / $totProposta['receita_liquida'] * 100 : null,
                    'margem_antes_rateios' => $totProposta['receita_liquida']
                        ? $totProposta['resultado_antes_rateios'] / $totProposta['receita_liquida'] * 100 : null,
                ],
                'tributos' => $tribTotal,
            ],
        ];
    }

    /**
     * Receita bruta (1), receita líquida (3) e resultado líquido (16) da coluna
     * Proposta, com a margem. Conta sem proposta vale null — e a margem também.
     */
    private static function resumoDaProposta(array $linhas): array
    {
        $p = array_column($linhas, 'proposta', 'codigo');
        $v = static fn ($c) => isset($p[$c]) && $p[$c] !== null ? (float)$p[$c] : null;
        $r = self::comMargem([
            'receita_bruta' => $v('1'),
            'receita_liquida' => $v('3'),
            'resultado' => $v(Projecao::LINHA_RESULTADO),
        ]);
        // Estrutura nova da DRE: a margem ANTES dos rateios (linha 13, "11 -
        // RESULTADO APÓS IMPOSTOS") e a margem DO NEGÓCIO (linha 15, "14 -
        // RESULTADO DO NEGÓCIO", depois dos rateios do próprio negócio e antes
        // dos administrativos).
        $r['resultado_antes_rateios'] = $v(Projecao::LINHA_APOS_IMPOSTOS);
        $r['margem_antes_rateios'] = $r['receita_liquida'] && $r['resultado_antes_rateios'] !== null
            ? $r['resultado_antes_rateios'] / $r['receita_liquida'] * 100 : null;
        $r['resultado_negocio'] = $v(Projecao::LINHA_RESULTADO_NEGOCIO);
        $r['margem_negocio'] = $r['receita_liquida'] && $r['resultado_negocio'] !== null
            ? $r['resultado_negocio'] / $r['receita_liquida'] * 100 : null;
        return $r;
    }

    /**
     * PIS e COFINS estimados sobre uma receita bruta, pela taxa que o negócio
     * pagou de jan até o corte (`Oportunidades::tributos`). Conta MACRO, para o
     * cliente refazer com a regra da reforma: supõe a mesma mistura de vendas a
     * sócio e a não sócio de 2026.
     */
    private static function pisCofinsSobre(?float $receitaBruta, ?array $trib): ?array
    {
        if ($receitaBruta === null || !$trib || $trib['taxa_pis_cofins'] === null) {
            return null;
        }
        return [
            'pis' => $receitaBruta * $trib['taxa_pis'],
            'cofins' => $receitaBruta * $trib['taxa_cofins'],
            'total' => $receitaBruta * $trib['taxa_pis_cofins'],
            'taxa' => $trib['taxa_pis_cofins'],
        ];
    }

    /** Margem líquida em %: resultado ÷ receita líquida. Sem receita, não há margem. */
    private static function comMargem(array $t): array
    {
        $t['margem'] = $t['receita_liquida'] && $t['resultado'] !== null
            ? $t['resultado'] / $t['receita_liquida'] * 100
            : null;
        return $t;
    }

    /** A resposta de toda gravação: a tela inteira, recalculada. */
    private function responderComATela(int $negocioId, int $ano, int $corte, int $filialId): void
    {
        Json::ok(self::propostaNoQuadro(self::paraTela($this->montar($negocioId, $ano, $corte, $filialId))));
    }

    /**
     * A tela no FORMATO DE 2027 (`Reforma::formatoNovo`): a DRE do negócio, da
     * filial ou do Corporativo com a CBS acima da Receita Bruta. É a última
     * coisa antes da resposta — `montar` segue no formato da carga, que é o que o
     * Corporativo soma e o que o desafio e o Painel usam.
     */
    private static function paraTela(array $p): array
    {
        if (!empty($p['linhas']) && !empty($p['tributos_dre'])) {
            $p['linhas'] = Reforma::formatoNovo($p['linhas'], $p['tributos_dre']);
            $p['nivel3'] = Projecao::nivel3FormatoNovo($p['nivel3'] ?? [], $p['tributos_dre']);
        }
        if (!empty($p['desafio_linhas']) && !empty($p['desafio_tributos_dre'])) {
            $p['desafio_linhas'] = Reforma::formatoNovo($p['desafio_linhas'], $p['desafio_tributos_dre']);
        }
        if (!empty($p['filial']['linhas']) && !empty($p['filial']['tributos_dre'])) {
            $p['filial']['linhas'] = Reforma::formatoNovo($p['filial']['linhas'], $p['filial']['tributos_dre']);
            $p['filial']['nivel3'] = Projecao::nivel3FormatoNovo($p['filial']['nivel3'] ?? [], $p['filial']['tributos_dre']);
        }
        return $p;
    }

    private function filialDoNegocio(int $filialId, int $negocioId): bool
    {
        return (bool)Database::um(
            'SELECT 1 AS ok FROM filial WHERE id = ? AND negocio_id = ?',
            [$filialId, $negocioId]
        );
    }

    /**
     * Troca o modo de planejamento do negócio (decisão do cliente: cada um
     * escolhe). Trocar NÃO apaga lançamento nenhum — o que foi lançado no
     * negócio e o que foi lançado nas filiais continuam onde estavam, e o modo
     * só diz qual dos dois forma o total. Apagar seria perder trabalho por uma
     * troca de visão.
     */
    public function gravarModo(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $modo = ($corpo['modo'] ?? '') === 'FILIAL' ? 'FILIAL' : 'NEGOCIO';

        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $anoPlan = (int)$fech['ano'] + 1;
        if ($modo === 'FILIAL' && !$this->filiaisDoNegocio($negocioId)) {
            Json::erro(
                'Este negócio ainda não tem a DRE das filiais carregada — '
                . 'peça a carga à controladoria para planejar por filial.',
                409
            );
        }

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        if (!$proj) {
            Database::executar(
                'INSERT INTO projecao (negocio_id, ano, modo, atualizado_em, atualizado_por)
                 VALUES (?, ?, ?, NOW(), ?)',
                [$negocioId, $anoPlan, $modo, $u['id']]
            );
        } else {
            Database::executar(
                'UPDATE projecao SET modo = ?, versao = versao + 1, atualizado_em = NOW(),
                        atualizado_por = ? WHERE id = ?',
                [$modo, $u['id'], (int)$proj['id']]
            );
        }
        $this->responderComATela(
            $negocioId,
            (int)$fech['ano'],
            (int)$fech['mes_fechado_ate'],
            (int)($corpo['filial_id'] ?? 0)
        );
    }

    /**
     * Lança a proposta de UMA linha (fatia 2).
     *
     * Grava o que foi digitado — valor ou percentual —, e devolve a tabela
     * inteira recalculada. Devolver a tabela, e não só a célula, é o que
     * mantém os blocos derivados fiéis sem a tela refazer a soma: a regra de
     * derivação mora em `App\Services\Projecao`, de um lado só.
     */
    public function gravarValor(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $codigo = trim((string)($corpo['conta'] ?? ''));
        $filialId = (int)($corpo['filial_id'] ?? 0);

        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $anoPlan = (int)$fech['ano'] + 1;

        $conta = Database::um('SELECT * FROM dre_conta WHERE codigo = ?', [$codigo]);
        if (!$conta) {
            Json::erro('Conta não encontrada.', 404);
        }
        // Bloco não recebe lançamento: ele é a SOMA das subcontas. Aceitar aqui
        // deixaria a linha de despesas valendo um total diferente da soma das
        // despesas lançadas logo abaixo dela — e as duas na mesma tela.
        if (!Projecao::editavel($conta)) {
            Json::erro(
                'Esta linha é calculada a partir das subcontas: lance nas linhas de baixo.',
                422
            );
        }

        // As Despesas com Pessoal se lançam (desde 2026-11-23; entre 21 e 23/11
        // foram recusadas aqui): abaixo do mínimo, gravam com o ⚠ (`montar`).

        // A DEPRECIAÇÃO carregada não se lança (pedido do cliente, 2026-11-23):
        // é o total da depreciação, por negócio, filial e mês. Apagar (valor
        // vazio) continua aceito, para limpar um lançamento de antes da carga.
        $vazio = !isset($corpo['valor']) || $corpo['valor'] === '';
        // O RATEIO ADMINISTRATIVO (16.1) também não se lança (pedido do cliente,
        // 2026-11-24): é a parte do negócio no rateio corporativo administrativo.
        if ($codigo === Projecao::LINHA_RATEIO_MATRIZ && !$vazio) {
            Json::erro('O Rateio Matriz/Central vale zero na proposta: a conta fecha em zero na cooperativa, e o '
                . 'resíduo que sobra em cada negócio não é meta. Não se lança.', 422);
        }
        if ($codigo === Projecao::LINHA_RATEIO_FIN && !$vazio && Projecao::rateioFinDoNegocio($negocioId, $anoPlan) !== null) {
            Json::erro(
                'O Rateio Financeiro vem do rateio financeiro de ' . $anoPlan . ' por negócio e não se lança.',
                422
            );
        }
        if ($codigo === Projecao::LINHA_RATEIO_NEG && !$vazio && Projecao::cargaRateioNegocios($anoPlan)) {
            Json::erro(
                'O Rateio Corporativo Negócio vem do rateio dos centros de custo de ' . $anoPlan
                . ' pelos critérios de cada um e não se lança.',
                422
            );
        }
        if ($codigo === Projecao::LINHA_RATEIO_ADM && !$vazio && Projecao::cargaRateioAdm($anoPlan)) {
            Json::erro(
                'O Rateio Corporativo Administrativo vem do rateio corporativo de ' . $anoPlan
                . ' (proporção das despesas orçadas de cada negócio) e não se lança.',
                422
            );
        }
        if ($codigo === Projecao::LINHA_DEPRECIACAO && !$vazio
            && !Projecao::depreciacaoEditavel((string)(Database::um('SELECT cod_negocio FROM negocio WHERE id = ?', [$negocioId])['cod_negocio'] ?? ''))
            && Projecao::depreciacao([$negocioId], $anoPlan)) {
            Json::erro(
                'A Depreciação (conta ' . Projecao::CONTA_DEPRECIACAO . ') vem da carga de depreciação '
                . 'por negócio, filial e mês e não se lança: é o total da depreciação.',
                422
            );
        }

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        if ($filialId && !$this->filialDoNegocio($filialId, $negocioId)) {
            Json::erro('Filial não é deste negócio.', 403);
        }
        // No modo FILIAL a linha do negócio é a SOMA das filiais: aceitar
        // lançamento nela daria dois totais discordando na mesma tela — o
        // mesmo motivo pelo qual o bloco não recebe lançamento.
        if (!$filialId && ($proj['modo'] ?? 'NEGOCIO') === 'FILIAL') {
            Json::erro(
                'Esta projeção está no modo filial: a linha do negócio é a soma das filiais. '
                . 'Lance na filial, ou troque o modo.',
                422
            );
        }
        // Trava otimista. Só vale quando a projeção JÁ existe: a primeira
        // gravação cria a linha, e exigir versão de algo que não existe
        // impediria qualquer começo.
        if ($proj && array_key_exists('versao', $corpo)
            && (int)$corpo['versao'] !== (int)$proj['versao']) {
            Json::erro(
                'Alguém gravou nesta projeção enquanto você editava. Recarregue a tela '
                . 'para ver o que mudou antes de lançar de novo.',
                409
            );
        }
        $entrada = ($corpo['entrada'] ?? 'VALOR') === 'PERCENTUAL' ? 'PERCENTUAL' : 'VALOR';
        $bruto = $corpo['valor'] ?? null;
        if ($bruto !== null && $bruto !== '') {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            if ($entrada === 'PERCENTUAL' && abs((float)$bruto) > 1000) {
                Json::erro('Percentual fora de faixa: informe entre -1000% e 1000%.', 422);
            }
        }

        if (!$proj) {
            Database::executar(
                'INSERT INTO projecao (negocio_id, ano, atualizado_em, atualizado_por)
                 VALUES (?, ?, NOW(), ?)',
                [$negocioId, $anoPlan, $u['id']]
            );
            $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        }

        if ($bruto === null || $bruto === '') {
            // Apagar a célula devolve a linha à projeção (crescimento zero), que
            // é o estado de quem nunca a tocou — e não zero, que seria uma
            // proposta de fechar o negócio.
            Database::executar(
                'DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = ? AND conta_id = ?',
                [(int)$proj['id'], $filialId, (int)$conta['id']]
            );
        } else {
            $v = (float)$bruto;
            Database::executar(
                'INSERT INTO projecao_valor (projecao_id, filial_id, conta_id, entrada, valor, percentual)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE entrada = VALUES(entrada), valor = VALUES(valor),
                                         percentual = VALUES(percentual)',
                [(int)$proj['id'], $filialId, (int)$conta['id'], $entrada,
                 $entrada === 'VALOR' ? $v : null,
                 $entrada === 'PERCENTUAL' ? $v : null]
            );
        }
        Database::executar(
            'UPDATE projecao SET versao = versao + 1, atualizado_em = NOW(), atualizado_por = ?
              WHERE id = ?',
            [$u['id'], (int)$proj['id']]
        );

        // O PISO do faturamento TRAVA NA DIGITAÇÃO (pedido do cliente,
        // 2026-11-21): a Receita de Vendas lançada abaixo de IPCA + PIB +
        // crescimento desejado não fica gravada — a linha volta ao piso (a
        // premissa) e a resposta diz por quê. A conta é a do motor (`aplicar`,
        // `abaixo_piso`, com a CBS e o formato de cada entrada), por isso se
        // grava, se mede e, abaixo, se apaga: refazê-la aqui seria uma segunda
        // conta para o mesmo número. O lançamento que JÁ estava abaixo (a carga
        // da Pecuária, ou a premissa que subiu depois) não é apagado, mas também
        // não vale: `aplicar` põe a linha no piso (decisão do cliente,
        // 2026-11-21, "já faça para as filiais também").
        $tela = $this->montar($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate'], $filialId);
        if ($codigo === Projecao::LINHA_RECEITA && $bruto !== null && $bruto !== '') {
            $linhasEscopo = $filialId ? ($tela['filial']['linhas'] ?? []) : $tela['linhas'];
            $rec = array_column($linhasEscopo, null, 'codigo')[Projecao::LINHA_RECEITA] ?? null;
            if ($rec && !empty($rec['piso_aplicado'])) {
                Database::executar(
                    'DELETE FROM projecao_valor WHERE projecao_id = ? AND filial_id = ? AND conta_id = ?',
                    [(int)$proj['id'], $filialId, (int)$conta['id']]
                );
                $tela = $this->montar($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate'], $filialId);
                $comDriver = isset($tela['driver']['crescimento']);
                $tela['aviso_piso'] = sprintf(
                    'A Receita de Vendas não pode ficar abaixo %s (%s%%). '
                    . 'O valor digitado foi recusado e a linha voltou ao piso.',
                    $comDriver ? 'do driver de volume do desafio' : 'de IPCA + PIB + crescimento desejado',
                    number_format((float)$rec['piso_percentual'], 2, ',', '.')
                );
            }
        }
        Json::ok(self::propostaNoQuadro(self::paraTela($tela)));
    }

    /**
     * Lança uma premissa do painel: IPCA, PIB ou o crescimento desejado.
     *
     * Só estas três: "Cresc. Total", "Cresc. Real" e "% Custos" são LEITURA da
     * proposta, pela mesma conta que a planilha faz no consolidado. Aceitar
     * lançamento nelas criaria uma segunda fonte para um número que já tem
     * dono — a célula da linha.
     *
     * **A premissa é do NEGÓCIO e vale para todas as filiais dele** (pedido do
     * cliente, 2026-11-16). Gravar uma por filial é RECUSADO, e não redirecionado
     * calado para o negócio: quem pensa estar ajustando uma filial e sobrescreve
     * o valor do negócio inteiro não tem como perceber — o número muda em todas
     * as telas e a causa não está em nenhuma delas. A tela já não oferece o
     * campo na filial; a recusa é para quem chama a API.
     *
     * **E é de um ANO** (pedido do cliente, 2026-11-17): o corpo traz `ano`, do
     * ano da proposta ao fim do ciclo, e sem ele vale o ano da proposta — o que
     * a tela e quem chamava antes de existir ano já esperavam. Ano fora dessa
     * faixa é recusado: um IPCA de 2019 gravado "no ano errado" apareceria em
     * lugar nenhum, e ninguém acharia o número.
     */
    public function gravarPremissa(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $chave = strtoupper(trim((string)($corpo['chave'] ?? '')));
        $filialId = (int)($corpo['filial_id'] ?? 0);

        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        if ($filialId !== 0) {
            Json::erro(
                'IPCA, PIB, o crescimento desejado e o das despesas com pessoas valem para o '
                . 'negócio inteiro e todas as filiais dele: lance-os no negócio, e não na filial.',
                422
            );
        }
        if (!in_array($chave, Projecao::PREMISSAS, true)) {
            Json::erro(
                'Esta linha do painel é lida da proposta, não lançada: '
                . 'o crescimento se lança na própria linha da DRE.',
                422
            );
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $anoPlan = (int)$fech['ano'] + 1;
        $anos = $this->anosDasPremissas($anoPlan);

        $anoPedido = $corpo['ano'] ?? null;
        if ($anoPedido === null || $anoPedido === '') {
            $ano = $anoPlan;
        } elseif (!is_numeric($anoPedido) || (int)$anoPedido != $anoPedido) {
            Json::erro('Ano inválido: informe um ano, como ' . $anoPlan . '.', 422);
        } else {
            $ano = (int)$anoPedido;
        }
        if (!in_array($ano, $anos, true)) {
            Json::erro(
                'Ano fora do ciclo: lance premissas de ' . $anos[0] . ' a ' . end($anos) . '.',
                422
            );
        }

        // A diretriz do ano fixa esta premissa para todos os negócios.
        $fixa = Projecao::fixadaPelaDiretriz($ano, $chave);
        if ($fixa !== null) {
            Json::erro(
                'Fixado pela diretriz de ' . $ano . ' (' . Projecao::diretriz($ano)['fonte'] . '): '
                . number_format($fixa, 2, ',', '.') . '% para todos os negócios.',
                422
            );
        }

        // Valida ANTES de escrever qualquer coisa: com a conferência depois, um
        // valor inválido já tinha criado a projeção do ano e deixado o rastro.
        $bruto = $corpo['valor'] ?? null;
        $apagar = $bruto === null || $bruto === '';
        if (!$apagar) {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            // IPCA e PIB são positivos (pedido do cliente, 2026-11-18): o zero vale.
            // O CRESCIMENTO desejado do faturamento aceita NEGATIVO (pedido do
            // cliente, 2026-11-19): um negócio pode planejar encolher a receita.
            $minimo = $chave === 'CRESCIMENTO' ? -100.0 : 0.0;
            if ((float)$bruto < $minimo || (float)$bruto > 100) {
                Json::erro($chave === 'CRESCIMENTO'
                    ? 'Percentual fora de faixa: o crescimento do faturamento vai de −100% a 100%.'
                    : 'Percentual fora de faixa: o percentual de crescimento deve ser positivo, entre 0% e 100%.', 422);
            }
        }

        if ($apagar) {
            Database::executar(
                'DELETE FROM negocio_premissa WHERE negocio_id = ? AND ano = ? AND chave = ?',
                [$negocioId, $ano, $chave]
            );
        } else {
            Database::executar(
                'INSERT INTO negocio_premissa (negocio_id, ano, chave, percentual)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE percentual = VALUES(percentual)',
                [$negocioId, $ano, $chave, (float)$bruto]
            );
        }

        // A projeção é a PROPOSTA do ano de planejamento, e só ela tem versão e
        // autoria. Lançar premissa do ano da proposta continua marcando-a — quem
        // tem a tela aberta é avisado de que algo mudou. Premissa de OUTRO ano
        // não toca na projeção: não há proposta ali para versionar, e criar uma
        // projeção de 2029 só para carregar um IPCA seria inventar um ano.
        if ($ano === $anoPlan) {
            $this->subirVersao($negocioId, $anoPlan, (int)$u['id']);
        }

        $this->responderComATela($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate'], 0);
    }

    /**
     * Marca a proposta do ano como alterada (cria a projeção se ainda não há):
     * quem tem a tela aberta fica sabendo que os números mudaram.
     */
    private function subirVersao(int $negocioId, int $anoPlan, int $usuarioId): void
    {
        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        if (!$proj) {
            Database::executar(
                'INSERT INTO projecao (negocio_id, ano, atualizado_em, atualizado_por)
                 VALUES (?, ?, NOW(), ?)',
                [$negocioId, $anoPlan, $usuarioId]
            );
            $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        }
        Database::executar(
            'UPDATE projecao SET versao = versao + 1, atualizado_em = NOW(), atualizado_por = ?
              WHERE id = ?',
            [$usuarioId, (int)$proj['id']]
        );
    }

    /**
     * Lança um valor do planejamento de pessoas e benefícios: o número de
     * colaboradores planejado, os valores por colaborador/mês ou os percentuais
     * de previsão (`Projecao::BENEFICIOS`).
     *
     * É do NEGÓCIO e de UM ANO, como as premissas. No ano da proposta ele MEXE
     * na proposta: as Despesas com Pessoal (8.5) são a conta da diretriz com os
     * benefícios deste cartão (`Projecao::pessoalDaDiretriz`), então a versão
     * sobe como na premissa e a resposta traz a DRE recalculada. Valor vazio
     * apaga o lançado e devolve o padrão.
     */
    public function gravarBeneficio(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $chave = strtoupper(trim((string)($corpo['chave'] ?? '')));

        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        if (!Database::um('SELECT id FROM negocio WHERE id = ?', [$negocioId])) {
            Json::erro('Negócio não encontrado.', 404);
        }
        if (!in_array($chave, Projecao::BENEFICIOS, true)) {
            Json::erro('Campo de pessoas e benefícios desconhecido: ' . $chave . '.', 422);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $anoPlan = (int)$fech['ano'] + 1;
        $anos = $this->anosDasPremissas($anoPlan);
        $anoPedido = $corpo['ano'] ?? null;
        if ($anoPedido === null || $anoPedido === '') {
            $ano = $anoPlan;
        } elseif (!is_numeric($anoPedido) || (int)$anoPedido != $anoPedido) {
            Json::erro('Ano inválido: informe um ano, como ' . $anoPlan . '.', 422);
        } else {
            $ano = (int)$anoPedido;
        }
        if (!in_array($ano, $anos, true)) {
            Json::erro('Ano fora do ciclo: lance de ' . $anos[0] . ' a ' . end($anos) . '.', 422);
        }

        $fixa = Projecao::fixadaPelaDiretriz($ano, $chave);
        if ($fixa !== null) {
            Json::erro(
                'Fixado pela diretriz de ' . $ano . ' (' . Projecao::diretriz($ano)['fonte'] . '): '
                . ($chave === 'PPR_SALARIOS'
                    ? number_format($fixa, 1, ',', '.') . ' salário de PPR'
                    : 'R$ ' . number_format($fixa, 2, ',', '.') . ' por colaborador ao mês')
                . ', para todos os negócios.',
                422
            );
        }

        // Valida ANTES de escrever.
        $bruto = $corpo['valor'] ?? null;
        $apagar = $bruto === null || $bruto === '';
        if (!$apagar) {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            $v = (float)$bruto;
            if (str_starts_with($chave, 'PCT_')) {
                if ($v < 0 || $v > 100) {
                    Json::erro('Percentual fora de faixa: informe entre 0% e 100%.', 422);
                }
            } elseif ($chave === 'PPR_SALARIOS') {
                if ($v < 0 || $v > Projecao::PPR_SALARIOS_MAXIMO) {
                    Json::erro('PPR fora de faixa: informe entre 0 e 12 salários.', 422);
                }
            } elseif ($v < 0 || $v > 100000) {
                Json::erro(
                    $chave === 'COLABORADORES'
                        ? 'Número de colaboradores fora de faixa: informe entre 0 e 100.000.'
                        : 'Valor fora de faixa: informe entre R$ 0 e R$ 100.000 por colaborador ao mês.',
                    422
                );
            }
        }

        // O TETO da diretriz (o PPR, pedido do cliente, 2026-11-23): menos pode,
        // mais é recusado.
        $teto = Projecao::maximoDaDiretriz($ano, $chave);
        if (!$apagar && $teto !== null && (float)$bruto > $teto + 1e-9) {
            Json::erro(
                'O PPR de ' . $ano . ' não pode passar da diretriz (' . Projecao::diretriz($ano)['fonte'] . '): até '
                . number_format($teto, 1, ',', '.') . ' salário. Valor menor pode.',
                422
            );
        }

        if ($apagar) {
            Database::executar(
                'DELETE FROM negocio_beneficio WHERE negocio_id = ? AND ano = ? AND chave = ?',
                [$negocioId, $ano, $chave]
            );
        } else {
            Database::executar(
                'INSERT INTO negocio_beneficio (negocio_id, ano, chave, valor) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE valor = VALUES(valor)',
                [$negocioId, $ano, $chave, (float)$bruto]
            );
        }
        if ($ano === $anoPlan) {
            $this->subirVersao($negocioId, $anoPlan, (int)$u['id']);
        }
        $this->responderComATela($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate'], 0);
    }

    /**
     * AVALIA uma linha das oportunidades para o ano do planejamento (pedido do
     * cliente, 2026-11-23; estendido a TODAS as linhas em 2026-11-24): o item não
     * recorrente pela conta contábil, o mês fora do padrão pela linha da DRE
     * (`LINHA:8.15`, `Oportunidades::chaveAvaliacao`). `decisao` CONSIDERAR (vai se
     * repetir: tratar no planejamento), DESCONSIDERAR (não se repete) ou vazia
     * (desmarca). Grava quem e quando. Não muda a DRE: a resposta, se for o caso,
     * se lança na linha.
     */
    /**
     * O desafio CONTA A CONTA (pedido do cliente, 2026-11-25): só o perfil ADMIN
     * lança na coluna Desafio da DRE de UM negócio, para o ano todo — valor, ou %
     * sobre a projeção. Vazio apaga e devolve a proposta padrão naquela conta.
     * As mesmas linhas da proposta: blocos não (são a soma) e as travadas
     * (rateios, depreciação carregada) também não.
     */
    public function gravarDesafio(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] !== 'ADMIN') {
            Json::erro('Só administradores lançam o desafio.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $codigo = trim((string)($corpo['conta'] ?? ''));
        if (!Database::um('SELECT id FROM negocio WHERE id = ?', [$negocioId])) {
            Json::erro('Negócio não encontrado.', 404);
        }
        $conta = Database::um('SELECT * FROM dre_conta WHERE codigo = ?', [$codigo]);
        if (!$conta) {
            Json::erro('Conta não encontrada.', 404);
        }
        if (!Projecao::editavel($conta)) {
            Json::erro('Esta linha é calculada a partir das subcontas: desafie nas linhas de baixo.', 422);
        }
        $entrada = ($corpo['entrada'] ?? 'VALOR') === 'PERCENTUAL' ? 'PERCENTUAL' : 'VALOR';
        $bruto = $corpo['valor'] ?? null;
        $valor = null;
        if ($bruto !== null && $bruto !== '') {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            if ($entrada === 'PERCENTUAL' && abs((float)$bruto) > 1000) {
                Json::erro('Percentual fora de faixa: informe entre -1000% e 1000%.', 422);
            }
            $valor = (float)$bruto;
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $ano = (int)$fech['ano'];
        $corte = (int)$fech['mes_fechado_ate'];
        if ($valor !== null) {
            // Rateios e depreciação carregada são travados também no desafio.
            $tela = $this->montar($negocioId, $ano, $corte, 0);
            $linha = array_column($tela['desafio_linhas'] ?? [], null, 'codigo')[$codigo] ?? null;
            if (!$linha || !empty($linha['proposta_travada'])) {
                Json::erro('Esta linha é travada (rateio ou depreciação carregada) e não se desafia.', 422);
            }
        }
        DesafioAjuste::gravar($negocioId, $ano + 1, (int)$conta['id'], $entrada, $valor, (int)$u['id']);
        $this->responderComATela($negocioId, $ano, $corte, 0);
    }

    public function gravarAvaliacao(): void
    {
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $corpo = Json::corpo();
        $negocioId = (int)($corpo['negocio_id'] ?? 0);
        $conta = trim((string)($corpo['conta'] ?? ''));
        $decisao = strtoupper(trim((string)($corpo['decisao'] ?? '')));
        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        if (!Database::um('SELECT id FROM negocio WHERE id = ?', [$negocioId])) {
            Json::erro('Negócio não encontrado.', 404);
        }
        $linha = str_starts_with($conta, Oportunidades::PREFIXO_LINHA)
            ? substr($conta, strlen(Oportunidades::PREFIXO_LINHA)) : null;
        $conhecido = $linha !== null
            ? (bool)Database::um('SELECT id FROM dre_conta WHERE codigo = ? AND nivel = 2', [$linha])
            : isset(Oportunidades::CONTAS[$conta]);
        if (!$conhecido) {
            Json::erro('Item desconhecido: ' . $conta . '.', 422);
        }
        if ($decisao !== '' && !in_array($decisao, ['CONSIDERAR', 'DESCONSIDERAR'], true)) {
            Json::erro('Decisão inválida: Considerar ou Desconsiderar.', 422);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Nenhuma DRE carregada.', 409);
        }
        $anoPlan = (int)$fech['ano'] + 1;
        if ($decisao === '') {
            Database::executar('DELETE FROM negocio_item_avaliacao WHERE negocio_id = ? AND ano = ? AND conta = ?',
                [$negocioId, $anoPlan, $conta]);
        } else {
            Database::executar(
                'INSERT INTO negocio_item_avaliacao (negocio_id, ano, conta, decisao, avaliado_por, avaliado_em)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE decisao = VALUES(decisao), avaliado_por = VALUES(avaliado_por), avaliado_em = NOW()',
                [$negocioId, $anoPlan, $conta, $decisao, (int)$u['id']]
            );
        }
        $this->responderComATela($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate'], 0);
    }

    /**
     * A visão do ano corrente por negócio: realizado até o corte, planejado
     * antes e depois dele e a visão realista (`Projecao::visaoRealista`), para a
     * receita bruta (`1`) e o resultado líquido (`16`).
     *
     * @param int[] $negocioIds
     * @return array<int,array{1:array,18:array}>
     */
    private function visaoDoAno(array $negocioIds, int $ano, int $corte): array
    {
        $brutos = $this->brutosDoAno($negocioIds, $ano, $corte);
        $saida = [];
        foreach ($brutos as $id => $porConta) {
            foreach (Projecao::LINHAS_VISAO_ANO as $c) {
                $saida[$id][$c] = Projecao::visaoRealista(
                    $porConta[$c] ?? ['realizado' => 0, 'plan_ate' => 0, 'plan_resto' => 0],
                    $c,
                    $corte
                );
            }
        }
        return $saida;
    }

    /**
     * O total dos negócios: a SOMA das colunas de cada um — inclusive a visão
     * realista. Aplicar a regra sobre os totais daria outro número (a aderência
     * do total não é a média das aderências), e o quadro deixaria de somar.
     */
    private function visaoDoAnoTotal(array $negocioIds, int $ano, int $corte): array
    {
        $tot = [];
        foreach ($this->visaoDoAno($negocioIds, $ano, $corte) as $porConta) {
            foreach ($porConta as $c => $v) {
                foreach ($v as $k => $x) {
                    if ($k === 'dif_pct') {
                        continue;
                    }
                    $tot[$c][$k] = ($tot[$c][$k] ?? 0.0) + (float)$x;
                }
            }
        }
        foreach ($tot as $c => $v) {
            $tot[$c]['dif_pct'] = $v['plan_ano'] != 0.0 ? $v['dif'] / abs($v['plan_ano']) : null;
        }
        return $tot;
    }

    /**
     * A PROPOSTA do ano seguinte nas linhas do quadro "realizado, planejado e visão
     * realista" (pedido do cliente, 2026-11-26: "traga o projetado para 2027 e compare
     * com o projetado 2026, para cada um dos indicadores"). É a mesma coluna Proposta
     * da DRE do negócio — nada se recalcula aqui.
     *
     * @return array<string,float|null> conta → proposta
     */
    private static function propostaDaVisao(array $linhas): array
    {
        $p = array_column($linhas, 'proposta', 'codigo');
        $out = [];
        foreach (Projecao::LINHAS_VISAO_ANO as $c) {
            $out[$c] = isset($p[$c]) && $p[$c] !== null ? (float)$p[$c] : null;
        }
        // A receita bruta do quadro vem da DRE carregada, COM o PIS/COFINS; na DRE do
        // formato 2027 ele sai da Receita Bruta e vira a linha informativa CBS. Para a
        // proposta ficar na mesma régua do "Real + Plan.", a CBS proposta volta à soma.
        if ($out['1'] !== null && isset($p['CBS']) && $p['CBS'] !== null) {
            $out['1'] += (float)$p['CBS'];
        }
        return $out;
    }

    /**
     * A comparação com a Proposta do ano seguinte no quadro do ano é SÓ DO ADMIN e só
     * com o modo apresentação desligado (cliente, 2026-11-26: "essa tela deve ser apenas
     * para os administradores […] a flag deve estar desativada" — a de Cadastros ›
     * Configurações). Fora disso, as propostas nem saem na resposta: a trava é do
     * servidor, não só do botão. `pode_comparar_proposta` diz à tela se mostra o seletor.
     */
    private static function propostaNoQuadro(array $d): array
    {
        $u = Auth::usuario();
        $pode = ($u['perfil'] ?? '') === 'ADMIN'
            && ConfiguracaoController::analisesLiberadas();
        $d['pode_comparar_proposta'] = $pode;
        if ($pode) {
            $anoPlan = (int)($d['ano_planejamento'] ?? 0);
            $dir = Projecao::diretriz($anoPlan);
            $doc = Projecao::DIRETRIZES[$anoPlan] ?? null;
            $d['diretriz_simulavel'] = $dir ? self::diretrizSimulavel($dir) : null;
            $d['diretriz_documento'] = $doc ? self::diretrizSimulavel($doc) : null;
            $d['diretriz_alterada'] = $dir['alterada'] ?? null;
            return $d;
        }
        $tirar = static function (?array $v): ?array {
            if ($v === null) {
                return null;
            }
            foreach ($v as $c => $x) {
                unset($v[$c]['proposta'], $v[$c]['desafio']);
            }
            return $v;
        };
        if (isset($d['visao_ano'])) {
            $d['visao_ano'] = $tirar($d['visao_ano']);
        }
        if (isset($d['visao_ano_total'])) {
            $d['visao_ano_total'] = $tirar($d['visao_ano_total']);
        }
        if (isset($d['visao_ano_negocios'])) {
            foreach ($d['visao_ano_negocios'] as $id => $v) {
                $d['visao_ano_negocios'][$id] = $tirar($v);
            }
        }
        return $d;
    }

    /** As linhas que o simulador de diretrizes compara (e a receita líquida, para o %). */
    private const LINHAS_SIMULADOR = ['3', '7', '8.5', '15', '18'];

    /** Os cinco números da diretriz que o simulador deixa trocar. */
    private static function diretrizSimulavel(array $dir): array
    {
        return [
            'ppr' => (float)($dir['maximos']['PPR_SALARIOS'] ?? $dir['beneficios']['PPR_SALARIOS'] ?? 0),
            'pessoas' => (float)($dir['premissas']['PESSOAS'] ?? 0),
            'alimentacao' => (float)($dir['beneficios']['ALIMENTACAO'] ?? 0),
            'assiduidade' => (float)($dir['beneficios']['ASSIDUIDADE'] ?? 0),
            'resultado' => (float)($dir['beneficios']['RESULTADO'] ?? 0),
        ];
    }

    /**
     * SIMULADOR DE DIRETRIZES (cliente, 2026-11-26: "se eu, como diretor, quiser visualizar
     * o impacto no resultado de todos os negócios apenas reduzindo o PPR de 1,5 para 1, ou
     * aumentando para 2 […]"; prévia aprovada, com a escolha da linha: despesas
     * operacionais, despesas com pessoal, resultado do negócio e resultado líquido).
     *
     * Refaz a conta de cada negócio com a diretriz pedida — o MESMO motor da DRE, inclusive
     * o rateio administrativo, que se distribui pelas despesas propostas — e devolve, por
     * negócio, as linhas com a diretriz de hoje e com a simulada. **Nada se grava**: a
     * diretriz simulada vive só nesta requisição (`Projecao::$diretrizSimulada`). Pedir a
     * diretriz vigente dá impacto zero. Lançamento digitado não muda.
     *
     * Só ADMIN e com o modo apresentação desligado — a mesma regra do "Comparar com".
     */
    public function simular(): void
    {
        $u = Auth::exigirLogin();
        if (($u['perfil'] ?? '') !== 'ADMIN' || !ConfiguracaoController::analisesLiberadas()) {
            Json::erro('O simulador de diretrizes é dos administradores, com o modo apresentação desligado.', 403);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        if (!$fech) {
            Json::erro('Sem DRE carregada.', 422);
        }
        $ano = (int)$fech['ano'];
        $corte = (int)$fech['mes_fechado_ate'];
        $anoPlan = $ano + 1;
        $vig = Projecao::diretriz($anoPlan);
        if (!$vig) {
            Json::erro("Não há diretriz de {$anoPlan} para simular.", 422);
        }
        $negocios = Database::todos(
            "SELECT n.id, CONCAT(n.cod_negocio, ' - ', n.nome) AS rotulo FROM negocio n
              WHERE n.ativo = 1 AND EXISTS (SELECT 1 FROM dre_valor v WHERE v.negocio_id = n.id AND v.ano = ?)
              ORDER BY CAST(n.cod_negocio AS UNSIGNED)",
            [$ano]
        );
        $ids = array_map(static fn ($n) => (int)$n['id'], $negocios);
        $grupo = self::grupoPedido((string)($_GET['negocios'] ?? ''), $ids);
        if ($grupo) {
            $negocios = array_values(array_filter($negocios, static fn ($n) => in_array((int)$n['id'], $grupo, true)));
        }

        $vigente = self::diretrizSimulavel($vig);
        $pedida = self::diretrizPedida($_GET, $vigente);

        $atual = $this->linhasDoSimulador($negocios, $ano, $corte);
        $sim = $vig;
        $sim['premissas']['PESSOAS'] = $pedida['pessoas'];
        $sim['beneficios']['ALIMENTACAO'] = $pedida['alimentacao'];
        $sim['beneficios']['ASSIDUIDADE'] = $pedida['assiduidade'];
        $sim['beneficios']['RESULTADO'] = $pedida['resultado'];
        $sim['beneficios']['PPR_SALARIOS'] = $pedida['ppr'];
        unset($sim['maximos']['PPR_SALARIOS']);
        Projecao::$diretrizSimulada = [$anoPlan => $sim];
        try {
            $simulado = $this->linhasDoSimulador($negocios, $ano, $corte);
        } finally {
            Projecao::$diretrizSimulada = null;
        }
        $saida = [];
        $total = ['atual' => [], 'simulado' => []];
        foreach ($negocios as $n) {
            $id = (int)$n['id'];
            // Quem DIGITOU a despesa com pessoal não muda no motor (o lançamento vale), e o
            // impacto sairia zero — "isso não faz sentido" (cliente, 2026-11-27). O simulador
            // põe sobre o lançamento a diferença que a diretriz provoca na conta de pessoal
            // DELE (PPR sobre a folha, reajuste, vale e abonos × o % de atingimento previsto
            // pelo negócio), e marca o negócio: "Aplicar" não muda lançamento.
            $lancadoAMao = false;
            $ajuste = 0.0;
            $dDir = isset($simulado[$id]['diretriz_85'], $atual[$id]['diretriz_85'])
                ? $simulado[$id]['diretriz_85'] - $atual[$id]['diretriz_85'] : null;
            $dMotor = $simulado[$id]['8.5'] - $atual[$id]['8.5'];
            if ($dDir !== null && abs($dDir - $dMotor) > 1.0) {
                $ajuste = $dDir - $dMotor;
                foreach (['8.5', '7', '15', '18'] as $c) {
                    $simulado[$id][$c] += $ajuste;
                }
                $lancadoAMao = true;
            }
            unset($atual[$id]['diretriz_85'], $simulado[$id]['diretriz_85']);
            // `ajuste_lancado`: a parte do impacto que vem SÓ de o negócio ter digitado o pessoal —
            // a que "Aplicar" não traz (o lançamento vale); ele chega se o negócio relançar.
            $saida[] = ['id' => $id, 'rotulo' => $n['rotulo'], 'atual' => $atual[$id], 'simulado' => $simulado[$id],
                'pessoal_lancado' => $lancadoAMao, 'ajuste_lancado' => $ajuste];
            foreach (self::LINHAS_SIMULADOR as $c) {
                $total['atual'][$c] = ($total['atual'][$c] ?? 0.0) + (float)$atual[$id][$c];
                $total['simulado'][$c] = ($total['simulado'][$c] ?? 0.0) + (float)$simulado[$id][$c];
            }
        }
        Json::ok([
            'ano' => $anoPlan,
            'diretriz' => $vigente,
            'simulada' => $pedida,
            'grupo' => $grupo,
            'negocios' => $saida,
            'total' => $total,
        ]);
    }

    /** Chave do simulador → chave da diretriz. */
    private const CHAVES_DIRETRIZ = ['ppr' => 'PPR_SALARIOS', 'pessoas' => 'PESSOAS',
        'alimentacao' => 'ALIMENTACAO', 'assiduidade' => 'ASSIDUIDADE', 'resultado' => 'RESULTADO'];

    /** Os valores pedidos (vírgula ou ponto), validados; o que não veio fica como em `$base`. */
    private static function diretrizPedida(array $entrada, array $base): array
    {
        $limites = ['ppr' => [0, Projecao::PPR_SALARIOS_MAXIMO], 'pessoas' => [-50, 100],
            'alimentacao' => [0, 100000], 'assiduidade' => [0, 100000], 'resultado' => [0, 100000]];
        $pedida = $base;
        foreach ($limites as $k => [$min, $max]) {
            $bruto = trim((string)($entrada[$k] ?? ''));
            if ($bruto === '') {
                continue;
            }
            $v = str_replace(',', '.', $bruto);
            if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) {
                Json::erro("Valor inválido em {$k}: use de {$min} a {$max}.", 422);
            }
            $pedida[$k] = (float)$v;
        }
        return $pedida;
    }

    /**
     * APLICA a diretriz do ano da proposta para TODOS os negócios (cliente, 2026-11-26:
     * "faça as diretrizes editáveis pelo administrador, com o botão Aplicar"). Grava em
     * `diretriz_valor` só o que difere do documento — o que volta ao documento é apagado —,
     * e `{restaurar: true}` volta tudo ao documento. Muda a proposta das linhas de pessoal
     * que os negócios NÃO digitaram; lançamento digitado não é tocado. Só ADMIN, com o
     * modo apresentação desligado.
     */
    public function aplicarDiretriz(): void
    {
        $u = Auth::exigirLogin();
        if (($u['perfil'] ?? '') !== 'ADMIN' || !ConfiguracaoController::analisesLiberadas()) {
            Json::erro('Só os administradores alteram a diretriz, com o modo apresentação desligado.', 403);
        }
        $fech = Database::um('SELECT * FROM dre_fechamento ORDER BY ano DESC LIMIT 1');
        $anoPlan = $fech ? (int)$fech['ano'] + 1 : 0;
        $doc = Projecao::DIRETRIZES[$anoPlan] ?? null;
        if (!$doc) {
            Json::erro("Não há diretriz de {$anoPlan} para alterar.", 422);
        }
        $corpo = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
        $documento = self::diretrizSimulavel($doc);
        $nova = !empty($corpo['restaurar'])
            ? $documento
            : self::diretrizPedida($corpo, self::diretrizSimulavel(Projecao::diretriz($anoPlan)));
        $pdo = Database::conn();
        $pdo->beginTransaction();
        try {
            foreach (self::CHAVES_DIRETRIZ as $k => $chave) {
                if (abs($nova[$k] - $documento[$k]) < 1e-9) {
                    Database::executar('DELETE FROM diretriz_valor WHERE ano = ? AND chave = ?', [$anoPlan, $chave]);
                } else {
                    Database::executar(
                        'INSERT INTO diretriz_valor (ano, chave, valor, atualizado_por, atualizado_em) VALUES (?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por),
                           atualizado_em = VALUES(atualizado_em)',
                        [$anoPlan, $chave, $nova[$k], (int)$u['id']]
                    );
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Projecao::esquecerDiretrizesGravadas();
        $dir = Projecao::diretriz($anoPlan);
        Json::ok([
            'ano' => $anoPlan,
            'diretriz' => self::diretrizSimulavel($dir),
            'documento' => $documento,
            'alterada' => $dir['alterada'] ?? null,
        ]);
    }

    /** A proposta das linhas do simulador, por negócio, com a diretriz que estiver valendo. */
    private function linhasDoSimulador(array $negocios, int $ano, int $corte): array
    {
        $rateios = Projecao::rateios($ano, $corte, $ano + 1);
        $out = [];
        foreach ($negocios as $n) {
            $m = $this->montar((int)$n['id'], $ano, $corte, 0, $rateios);
            $p = array_column($m['linhas'], 'proposta', 'codigo');
            foreach (self::LINHAS_SIMULADOR as $c) {
                $out[(int)$n['id']][$c] = (float)($p[$c] ?? 0.0);
            }
            // A despesa com pessoal PELA DIRETRIZ (o padrão da 8.5), mesmo quando o negócio
            // digitou a sua: é dela que sai o impacto de quem lançou à mão.
            $out[(int)$n['id']]['diretriz_85'] = isset($m['pessoal_diretriz']['proposta'])
                ? (float)$m['pessoal_diretriz']['proposta'] : null;
        }
        return $out;
    }

    /** O desafio de vários negócios somado conta a conta (a coluna Desafio do Corporativo, só leitura). */
    private static function somarLinhasDesafio(array $listas): array
    {
        $soma = [];
        foreach ($listas as $linhas) {
            foreach ($linhas as $l) {
                $c = $l['codigo'];
                if (!isset($soma[$c])) {
                    $soma[$c] = $l;
                    $soma[$c]['proposta_travada'] = true;
                    continue;
                }
                if ($l['proposta'] !== null && !$l['percentual']) {
                    $soma[$c]['proposta'] = (float)($soma[$c]['proposta'] ?? 0) + (float)$l['proposta'];
                }
            }
        }
        return array_values($soma);
    }

    /** Põe `proposta` em cada conta da visão do ano (de um negócio ou do total). */
    private static function comProposta(?array $visao, array $proposta, string $campo = 'proposta'): ?array
    {
        if ($visao === null) {
            return null;
        }
        foreach ($visao as $c => $v) {
            $visao[$c][$campo] = $proposta[$c] ?? null;
        }
        return $visao;
    }

    /** A visão do ano de cada negócio, com a proposta dele. */
    private static function visaoComPropostas(array $porNegocio, array $propostas, string $campo = 'proposta'): array
    {
        foreach ($porNegocio as $id => $v) {
            $porNegocio[$id] = self::comProposta($v, $propostas[$id] ?? [], $campo);
        }
        return $porNegocio;
    }

    /** A proposta do total: a soma das propostas dos negócios, conta a conta. */
    private static function somaDasPropostas(array $propostas): array
    {
        $tot = [];
        foreach ($propostas as $p) {
            foreach ($p as $c => $v) {
                $tot[$c] = ($tot[$c] ?? 0.0) + (float)$v;
            }
        }
        return $tot;
    }

    /** realizado / planejado até o corte / planejado depois dele, por negócio e conta. */
    private function brutosDoAno(array $negocioIds, int $ano, int $corte): array
    {
        if (!$negocioIds) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($negocioIds), '?'));
        $linhas = Database::todos(
            "SELECT v.negocio_id, c.codigo,
                    COALESCE(SUM(CASE WHEN v.tipo = 'REAL' AND v.mes <= ? THEN v.valor END), 0) AS realizado,
                    COALESCE(SUM(CASE WHEN v.tipo = 'META' AND v.mes <= ? THEN v.valor END), 0) AS plan_ate,
                    COALESCE(SUM(CASE WHEN v.tipo = 'META' AND v.mes >  ? THEN v.valor END), 0) AS plan_resto
               FROM dre_valor v JOIN dre_conta c ON c.id = v.conta_id
              WHERE v.ano = ? AND c.codigo IN ('1', '3', '5', '7', '13', '18') AND v.negocio_id IN ($marcas)
              GROUP BY v.negocio_id, c.codigo",
            array_merge([$corte, $corte, $corte, $ano], $negocioIds)
        );
        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int)$l['negocio_id']][$l['codigo']] = [
                'realizado' => (float)$l['realizado'],
                'plan_ate' => (float)$l['plan_ate'],
                'plan_resto' => (float)$l['plan_resto'],
            ];
        }
        return $saida;
    }

    /**
     * Pessoas e benefícios de UM negócio: o quadro da DRE (último mês do ano
     * atual), o que foi digitado em cada ano e a previsão de cada ano.
     *
     * A previsão sai de `Projecao::pessoas` — a conta mora num lugar só, e é a
     * mesma que o Corporativo soma.
     *
     * @param int[] $anos
     */
    /** A diretriz de cada ano do painel (null onde não há). */
    private function diretrizesDosAnos(array $anos): array
    {
        $out = [];
        foreach ($anos as $a) {
            $out[(string)$a] = Projecao::diretriz((int)$a);
        }
        return $out;
    }

    private function pessoasDoNegocio(int $negocioId, int $anoBase, array $anos): array
    {
        $b = Database::um(
            'SELECT ano, mes, colaboradores FROM negocio_pessoas_mes
              WHERE negocio_id = ? AND ano = ? ORDER BY mes DESC LIMIT 1',
            [$negocioId, $anoBase]
        );
        $base = $b ? ['ano' => (int)$b['ano'], 'mes' => (int)$b['mes'],
            'colaboradores' => (int)$b['colaboradores']] : null;

        $digitados = [];
        foreach ($anos as $a) {
            $digitados[(string)$a] = [];
        }
        foreach (Database::todos(
            'SELECT ano, chave, valor FROM negocio_beneficio WHERE negocio_id = ?',
            [$negocioId]
        ) as $l) {
            if (isset($digitados[(string)$l['ano']])) {
                $digitados[(string)$l['ano']][$l['chave']] = (float)$l['valor'];
            }
        }
        $porAno = [];
        foreach ($anos as $a) {
            $porAno[(string)$a] = Projecao::pessoas($base, Projecao::beneficiosEfetivos((int)$a, $digitados[(string)$a]));
        }
        return ['base' => $base, 'digitados' => $digitados, 'por_ano' => $porAno,
            'padrao' => Projecao::BENEFICIOS_PADRAO];
    }

    /**
     * Os anos em que se lançam premissas: do ano da proposta ao fim do ciclo
     * que o cobre. A conta está em `Projecao::anosDasPremissas`; aqui só se
     * pergunta ao banco onde o ciclo termina.
     *
     * @return int[]
     */
    private function anosDasPremissas(int $anoProposta): array
    {
        $ciclo = Database::um(
            'SELECT MAX(ano_fim) AS fim FROM ciclo WHERE ? BETWEEN ano_base AND ano_fim',
            [$anoProposta]
        );
        return Projecao::anosDasPremissas($anoProposta, isset($ciclo['fim']) ? (int)$ciclo['fim'] : null);
    }

    /**
     * As premissas lançadas do NEGÓCIO, por ANO: IPCA, PIB e o crescimento
     * desejado. Todo ano pedido volta com uma lista, vazia quando nada foi
     * lançado — a tela não precisa distinguir "ano sem premissa" de "ano
     * desconhecido".
     *
     * Sem parâmetro de filial, e é de propósito: a filial herda o conjunto do
     * negócio (pedido do cliente, 2026-11-16). Lê SÓ `negocio_premissa`; a
     * tabela antiga, `projecao_premissa`, é legado inerte — inclusive as linhas
     * por filial que alguém gravou antes da decisão, que ficam lá, intactas, e
     * NINGUÉM lê. Não reintroduza a leitura "para respeitar o valor antigo": a
     * filial voltaria a ter um IPCA que o negócio não conhece.
     *
     * @param  int[] $anos
     * @return array<string,array<string,float>> ano => (chave => percentual digitado)
     */
    private function premissasPorAno(int $negocioId, array $anos): array
    {
        $out = $this->premissasDigitadasPorAno($negocioId, $anos);
        // O que VALE em cada ano: a sugestão da diretriz por baixo do digitado, e
        // o que ela fixa por cima.
        foreach ($out as $a => $dig) {
            $out[$a] = Projecao::premissasEfetivas((int)$a, $dig);
        }
        return $out;
    }

    /**
     * Só o que o NEGÓCIO digitou, por ano — sem a diretriz. A tela precisa disto
     * para saber se o IPCA de 4,50% que mostra é o da diretriz (selo) ou um
     * valor que o negócio lançou e que, por acaso, é igual.
     */
    private function premissasDigitadasPorAno(int $negocioId, array $anos): array
    {
        $out = [];
        foreach ($anos as $a) {
            $out[$a] = [];
        }
        foreach (Database::todos(
            'SELECT ano, chave, percentual FROM negocio_premissa WHERE negocio_id = ?',
            [$negocioId]
        ) as $l) {
            $a = (int)$l['ano'];
            if (isset($out[$a])) {
                $out[$a][$l['chave']] = (float)$l['percentual'];
            }
        }
        return $out;
    }

    private function projecaoDoNegocio(int $negocioId, int $ano): ?array
    {
        return Database::um(
            'SELECT p.*, u.nome AS atualizado_por_nome
               FROM projecao p LEFT JOIN usuario u ON u.id = p.atualizado_por
              WHERE p.negocio_id = ? AND p.ano = ?',
            [$negocioId, $ano]
        );
    }

    /** @return array<string,array> codigo => lançamento (filial 0 = o negócio) */
    private function lancados(int $projecaoId, int $filialId = 0): array
    {
        if (!$projecaoId) {
            return [];
        }
        $linhas = Database::todos(
            'SELECT c.codigo, v.entrada, v.valor, v.percentual
               FROM projecao_valor v JOIN dre_conta c ON c.id = v.conta_id
              WHERE v.projecao_id = ? AND v.filial_id = ?',
            [$projecaoId, $filialId]
        );
        $out = [];
        foreach ($linhas as $l) {
            $out[$l['codigo']] = $l;
        }
        return $out;
    }

    private function filiaisDoNegocio(int $negocioId): array
    {
        return Database::todos(
            'SELECT f.id, f.cod_filial, f.nome
               FROM filial f
              WHERE f.negocio_id = ?
                AND EXISTS (SELECT 1 FROM dre_valor_filial v WHERE v.filial_id = f.id)
              ORDER BY CAST(f.cod_filial AS UNSIGNED)',
            [$negocioId]
        );
    }

    /**
     * A DRE de uma filial.
     *
     * Vem ACUMULADA da carga (ver `dre_valor_filial`), então aqui não há corte
     * de mês a aplicar: o corte já está embutido nos números, e a carga recusa
     * um arquivo cujo corte não seja o do fechamento gravado.
     */
    private function linhasDaFilial(int $filialId, int $ano, int $corte): array
    {
        $linhas = Database::todos(
            'SELECT c.codigo, c.nome, c.nivel, c.pai_codigo, c.percentual,
                    COALESCE(v.realizado, 0) AS realizado,
                    COALESCE(v.projetado, 0) AS projetado
               FROM dre_conta c
               LEFT JOIN dre_valor_filial v
                      ON v.conta_id = c.id AND v.filial_id = ? AND v.ano = ?
              ORDER BY c.ordem',
            [$filialId, $ano]
        );
        foreach ($linhas as &$l) {
            $l['nivel'] = (int)$l['nivel'];
            $l['percentual'] = (int)$l['percentual'];
            $l['realizado'] = (float)$l['realizado'];
            $l['projetado'] = (float)$l['projetado'];
            $l['projecao'] = $l['realizado'] + $l['projetado'];
        }
        return $linhas;
    }

    /**
     * A DRE do negócio: `realizado` é jan–corte do ano e `projetado` é o
     * PLANEJADO (orçado, `tipo = META`) dos meses depois do corte no MESMO ano;
     * `projecao` — a base da proposta, do piso e do desafio — é a soma: a
     * projeção do ano. (De 23/11/2026 por algumas horas a coluna do meio foi o
     * realizado de nov–dez do ano anterior, a "base de 12 meses"; o cliente
     * voltou atrás no mesmo dia: "jan–out deve ser de 2026 e nov–dez deve pegar
     * o planejado de 2026".)
     */
    private function linhasDaDre(int $negocioId, int $ano, int $corte): array
    {
        $linhas = Database::todos(
            'SELECT c.codigo, c.nome, c.nivel, c.pai_codigo, c.percentual,
                    COALESCE(SUM(CASE WHEN v.tipo = \'REAL\' AND v.mes <= ? THEN v.valor END), 0) AS realizado,
                    COALESCE(SUM(CASE WHEN v.tipo = \'META\' AND v.mes >  ? THEN v.valor END), 0) AS projetado
               FROM dre_conta c
               LEFT JOIN dre_valor v
                      ON v.conta_id = c.id AND v.negocio_id = ? AND v.ano = ?
              GROUP BY c.id, c.codigo, c.nome, c.nivel, c.pai_codigo, c.percentual, c.ordem
              ORDER BY c.ordem',
            [$corte, $corte, $negocioId, $ano]
        );
        foreach ($linhas as &$l) {
            $l['nivel'] = (int)$l['nivel'];
            $l['percentual'] = (int)$l['percentual'];
            $l['realizado'] = (float)$l['realizado'];
            $l['projetado'] = (float)$l['projetado'];
            $l['projecao'] = $l['realizado'] + $l['projetado'];
        }
        return $linhas;
    }
}

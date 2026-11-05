<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

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

        $linhas = Database::todos(
            'SELECT c.codigo, c.nome, c.nivel, c.pai_codigo, c.percentual,
                    COALESCE(SUM(CASE WHEN v.tipo = \'REAL\' AND v.mes <= ? THEN v.valor END), 0) AS realizado,
                    COALESCE(SUM(CASE WHEN v.tipo = \'META\' AND v.mes >  ? THEN v.valor END), 0) AS projetado,
                    COALESCE(SUM(CASE WHEN v.tipo = \'META\' THEN v.valor END), 0) AS meta_ano
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
            $l['meta_ano'] = (float)$l['meta_ano'];
            $l['projecao'] = $l['realizado'] + $l['projetado'];
        }
        unset($l);

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

        Json::ok([
            'ano' => $ano,
            'ano_planejamento' => $ano + 1,
            'mes_fechado_ate' => $corte,
            'extraido_em' => $fech['extraido_em'],
            'fonte' => $fech['fonte'],
            'negocio_id' => $negocioId,
            'negocios' => $negocios,
            'meses_sem_meta' => $semMeta,
            'linhas' => $linhas,
        ]);
    }
}

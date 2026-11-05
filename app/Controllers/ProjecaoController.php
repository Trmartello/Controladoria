<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Projecao;

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
        $linhas = Projecao::aplicar($linhas, $this->lancados($proj['id'] ?? 0));
        $premissas = Projecao::premissas($linhas, $this->premissasLancadas((int)($proj['id'] ?? 0)));

        Json::ok([
            'ano' => $ano,
            'ano_planejamento' => $ano + 1,
            'mes_fechado_ate' => $corte,
            'extraido_em' => $fech['extraido_em'],
            'fonte' => $fech['fonte'],
            'negocio_id' => $negocioId,
            'negocios' => $negocios,
            'meses_sem_meta' => $semMeta,
            'pode_editar' => $u['perfil'] !== 'LEITURA',
            'versao' => (int)($proj['versao'] ?? 0),
            'modo' => $proj['modo'] ?? 'NEGOCIO',
            'premissas' => $premissas,
            'atualizado_em' => $proj['atualizado_em'] ?? null,
            'atualizado_por' => $proj['atualizado_por_nome'] ?? null,
            'linhas' => $linhas,
        ]);
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

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
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
        if (!$proj) {
            Database::executar(
                'INSERT INTO projecao (negocio_id, ano, atualizado_em, atualizado_por)
                 VALUES (?, ?, NOW(), ?)',
                [$negocioId, $anoPlan, $u['id']]
            );
            $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        }

        $entrada = ($corpo['entrada'] ?? 'VALOR') === 'PERCENTUAL' ? 'PERCENTUAL' : 'VALOR';
        $bruto = $corpo['valor'] ?? null;
        if ($bruto === null || $bruto === '') {
            // Apagar a célula devolve a linha à projeção (crescimento zero), que
            // é o estado de quem nunca a tocou — e não zero, que seria uma
            // proposta de fechar o negócio.
            Database::executar(
                'DELETE FROM projecao_valor WHERE projecao_id = ? AND conta_id = ?',
                [(int)$proj['id'], (int)$conta['id']]
            );
        } else {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            $v = (float)$bruto;
            if ($entrada === 'PERCENTUAL' && abs($v) > 1000) {
                Json::erro('Percentual fora de faixa: informe entre -1000% e 1000%.', 422);
            }
            Database::executar(
                'INSERT INTO projecao_valor (projecao_id, conta_id, entrada, valor, percentual)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE entrada = VALUES(entrada), valor = VALUES(valor),
                                         percentual = VALUES(percentual)',
                [(int)$proj['id'], (int)$conta['id'], $entrada,
                 $entrada === 'VALOR' ? $v : null,
                 $entrada === 'PERCENTUAL' ? $v : null]
            );
        }
        Database::executar(
            'UPDATE projecao SET versao = versao + 1, atualizado_em = NOW(), atualizado_por = ?
              WHERE id = ?',
            [$u['id'], (int)$proj['id']]
        );

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        $linhas = Projecao::aplicar(
            $this->linhasDaDre($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate']),
            $this->lancados((int)$proj['id'])
        );
        Json::ok([
            'versao' => (int)$proj['versao'],
            'atualizado_em' => $proj['atualizado_em'],
            'atualizado_por' => $proj['atualizado_por_nome'],
            'linhas' => $linhas,
            'premissas' => Projecao::premissas($linhas, $this->premissasLancadas((int)$proj['id'])),
        ]);
    }

    /**
     * Lança uma premissa macro (IPCA, PIB).
     *
     * Só estas duas: "Cresc. Total", "Cresc. Real" e "% Custos" são LEITURA da
     * proposta, pela mesma conta que a planilha faz no consolidado. Aceitar
     * lançamento nelas criaria uma segunda fonte para um número que já tem
     * dono — a célula da linha.
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

        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
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

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        if (!$proj) {
            Database::executar(
                'INSERT INTO projecao (negocio_id, ano, atualizado_em, atualizado_por)
                 VALUES (?, ?, NOW(), ?)',
                [$negocioId, $anoPlan, $u['id']]
            );
            $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        }

        $bruto = $corpo['valor'] ?? null;
        if ($bruto === null || $bruto === '') {
            Database::executar(
                'DELETE FROM projecao_premissa WHERE projecao_id = ? AND chave = ?',
                [(int)$proj['id'], $chave]
            );
        } else {
            if (!is_numeric($bruto)) {
                Json::erro('Informe um número.', 422);
            }
            $v = (float)$bruto;
            if (abs($v) > 100) {
                Json::erro('Percentual fora de faixa: informe entre -100% e 100%.', 422);
            }
            Database::executar(
                'INSERT INTO projecao_premissa (projecao_id, chave, percentual) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE percentual = VALUES(percentual)',
                [(int)$proj['id'], $chave, $v]
            );
        }
        Database::executar(
            'UPDATE projecao SET versao = versao + 1, atualizado_em = NOW(), atualizado_por = ?
              WHERE id = ?',
            [$u['id'], (int)$proj['id']]
        );

        $proj = $this->projecaoDoNegocio($negocioId, $anoPlan);
        $linhas = Projecao::aplicar(
            $this->linhasDaDre($negocioId, (int)$fech['ano'], (int)$fech['mes_fechado_ate']),
            $this->lancados((int)$proj['id'])
        );
        Json::ok([
            'versao' => (int)$proj['versao'],
            'atualizado_em' => $proj['atualizado_em'],
            'atualizado_por' => $proj['atualizado_por_nome'],
            'linhas' => $linhas,
            'premissas' => Projecao::premissas($linhas, $this->premissasLancadas((int)$proj['id'])),
        ]);
    }

    /** @return array<string,float> chave => percentual digitado */
    private function premissasLancadas(int $projecaoId): array
    {
        if (!$projecaoId) {
            return [];
        }
        $out = [];
        foreach (Database::todos(
            'SELECT chave, percentual FROM projecao_premissa WHERE projecao_id = ?',
            [$projecaoId]
        ) as $l) {
            $out[$l['chave']] = (float)$l['percentual'];
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

    /** @return array<string,array> codigo => lançamento */
    private function lancados(int $projecaoId): array
    {
        if (!$projecaoId) {
            return [];
        }
        $linhas = Database::todos(
            'SELECT c.codigo, v.entrada, v.valor, v.percentual
               FROM projecao_valor v JOIN dre_conta c ON c.id = v.conta_id
              WHERE v.projecao_id = ?',
            [$projecaoId]
        );
        $out = [];
        foreach ($linhas as $l) {
            $out[$l['codigo']] = $l;
        }
        return $out;
    }

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

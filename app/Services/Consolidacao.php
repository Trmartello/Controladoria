<?php

namespace App\Services;

use App\Core\Database;

/**
 * Reconciliação do plano de ação: o que é consequência, e não digitado.
 *
 * Status "No prazo"/"Atrasada" das ações e o período/status dos projetos são
 * derivados — recalculados na LEITURA, porque o sistema não tem agendador.
 *
 * Mora num serviço, e não no ProjetoController, porque quem lê esses números
 * não é só a seção Projetos: o painel da direção e o relatório de status
 * mostravam os mesmos campos sem nunca reconciliar. Na prática, uma ação que
 * vencia sem ninguém abrir a seção Projetos aparecia como "no prazo" no painel,
 * e o relatório imprimia zero atraso — até alguém abrir Projetos e os números
 * mudarem sozinhos.
 */
class Consolidacao
{
    /** Roda a reconciliação inteira de um planejamento (ordem importa). */
    public static function reconciliar(int $planId): void
    {
        self::sincronizarAtrasos($planId);
        self::consolidarIniciativas($planId);
        self::consolidarProjetos($planId);
    }

    /**
     * "No prazo" e "Atrasada" nunca são escolhidos pelo usuário: são derivados
     * da data-limite da ação. Os demais status são manuais e não se mexe neles.
     */
    public static function sincronizarAtrasos(int $planId): void
    {
        Database::executar(
            "UPDATE desdobramento d JOIN projeto p ON p.id = d.projeto_id
             SET d.status = 'ATRASADO'
             WHERE p.planejamento_id = ? AND d.status IN ('NAO_INICIADO', 'EM_ANDAMENTO')
               AND d.data_fim IS NOT NULL AND d.data_fim < CURDATE()",
            [$planId]
        );
        Database::executar(
            "UPDATE desdobramento d JOIN projeto p ON p.id = d.projeto_id
             SET d.status = 'NAO_INICIADO'
             WHERE p.planejamento_id = ? AND d.status = 'ATRASADO'
               AND (d.data_fim IS NULL OR d.data_fim >= CURDATE())",
            [$planId]
        );
    }

    /**
     * O status da FRENTE (iniciativa) é consequência das ações dela, como o do
     * projeto: todas concluídas fecham a frente ("Concluída"), qualquer ação em
     * curso (ou atrasada, ou já concluída entre pendentes) a põe "Em andamento",
     * e sem ação nenhuma iniciada ela volta a "Aberta". Não existe frente
     * "Atrasada" no enum — o atraso aparece no panorama ("N atrasada(s)") e no
     * status do projeto. O campo deixou de ser editável no modal quando esta
     * regra entrou: um status digitado seria sobrescrito na primeira leitura.
     *
     * Frente sem ação não-cancelada (inclusive recém-criada) cai no LEFT JOIN
     * sem par e volta a ABERTA — a mesma lição do projeto congelado no status
     * antigo depois de todas as ações serem canceladas.
     */
    public static function consolidarIniciativas(int $planId): void
    {
        Database::executar(
            "UPDATE iniciativa i
             JOIN projeto p ON p.id = i.projeto_id AND p.planejamento_id = ?
             LEFT JOIN (
               SELECT d.iniciativa_id,
                      COUNT(*) AS n,
                      SUM(d.status = 'CONCLUIDO') AS concluidas,
                      SUM(d.status = 'ATRASADO') AS atrasadas,
                      SUM(d.status IN ('EM_ANDAMENTO', 'PAUSADO', 'AGUARDANDO_VALIDACAO')) AS ativas
               FROM desdobramento d
               JOIN projeto pr ON pr.id = d.projeto_id AND pr.planejamento_id = ?
               WHERE d.status <> 'CANCELADO' AND d.iniciativa_id IS NOT NULL
               GROUP BY d.iniciativa_id
             ) x ON x.iniciativa_id = i.id
             SET i.status = CASE
               WHEN x.iniciativa_id IS NULL THEN 'ABERTA'
               WHEN x.concluidas = x.n THEN 'CONCLUIDA'
               WHEN x.atrasadas > 0 OR x.ativas > 0 OR x.concluidas > 0 THEN 'EM_ANDAMENTO'
               ELSE 'ABERTA' END",
            [$planId, $planId]
        );
    }

    /**
     * O período e o status do projeto são consequência das ações: início =
     * menor data de início, fim = maior data de fim; o status agrega os das
     * ações. Projetos sem ações e os cancelados não são tocados.
     */
    public static function consolidarProjetos(int $planId): void
    {
        Database::executar(
            "UPDATE projeto p
             JOIN (
               SELECT d.projeto_id,
                      MIN(d.data_inicio) AS di, MAX(d.data_fim) AS df,
                      COUNT(*) AS n,
                      SUM(d.status = 'CONCLUIDO') AS concluidas,
                      SUM(d.status = 'ATRASADO') AS atrasadas,
                      SUM(d.status IN ('EM_ANDAMENTO', 'PAUSADO', 'AGUARDANDO_VALIDACAO')) AS ativas
               -- Ação cancelada não conta: com ela no total, 'concluidas = n'
               -- nunca fechava e um projeto com 3 de 4 ações prontas (a quarta
               -- cancelada) ficava EM_ANDAMENTO para sempre. O prazo também
               -- esticava, porque as datas dela entravam no MIN/MAX.
               -- O JOIN com projeto filtra o derivado pelo planejamento: sem
               -- ele, cada plano varria a tabela INTEIRA de ações, e o painel
               -- (que reconcilia um plano por negócio) pagava isso N vezes.
               FROM desdobramento d
               JOIN projeto pr ON pr.id = d.projeto_id AND pr.planejamento_id = ?
               WHERE d.status <> 'CANCELADO' GROUP BY d.projeto_id
             ) x ON x.projeto_id = p.id
             SET p.data_inicio = COALESCE(x.di, p.data_inicio),
                 p.data_fim = COALESCE(x.df, p.data_fim),
                 p.status = CASE
                   WHEN x.atrasadas > 0 THEN 'ATRASADO'
                   WHEN x.concluidas = x.n THEN 'CONCLUIDO'
                   WHEN x.ativas > 0 OR x.concluidas > 0 THEN 'EM_ANDAMENTO'
                   ELSE 'NAO_INICIADO' END
             WHERE p.planejamento_id = ? AND p.status <> 'CANCELADO'",
            [$planId, $planId]
        );
        // Projeto cujas ações foram TODAS canceladas some do derivado acima (o
        // JOIN não casa) e ficava congelado no status antigo — inclusive
        // ATRASADO, que o painel da direção continuava contando, sem forma de
        // limpar pela tela (o status do projeto não é editável). Vale a mesma
        // regra do projeto sem ação nenhuma: volta ao começo.
        Database::executar(
            "UPDATE projeto p
                SET p.status = 'NAO_INICIADO'
              WHERE p.planejamento_id = ? AND p.status <> 'CANCELADO'
                AND EXISTS (SELECT 1 FROM (SELECT projeto_id FROM desdobramento) d
                            WHERE d.projeto_id = p.id)
                AND NOT EXISTS (SELECT 1 FROM (SELECT projeto_id, status FROM desdobramento) d2
                                WHERE d2.projeto_id = p.id AND d2.status <> 'CANCELADO')",
            [$planId]
        );
    }

    /**
     * Planejamentos visíveis num ciclo, para reconciliar antes de um painel que
     * soma vários negócios de uma vez.
     */
    public static function reconciliarCiclo(int $cicloId, ?array $negociosVisiveis): void
    {
        $sql = 'SELECT id FROM planejamento WHERE ciclo_id = ?';
        $params = [$cicloId];
        if ($negociosVisiveis !== null) {
            if (!$negociosVisiveis) {
                return;
            }
            $marcas = implode(',', array_fill(0, count($negociosVisiveis), '?'));
            $sql .= " AND negocio_id IN ({$marcas})";
            $params = [...$params, ...$negociosVisiveis];
        }
        foreach (Database::todos($sql, $params) as $linha) {
            self::reconciliar((int)$linha['id']);
        }
    }
}

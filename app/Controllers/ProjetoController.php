<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class ProjetoController
{
    /** Status da ação: os dois primeiros são automáticos (regidos pela data-limite). */
    private const STATUS = [
        'NAO_INICIADO', 'ATRASADO',
        'EM_ANDAMENTO', 'CONCLUIDO', 'CANCELADO', 'PAUSADO', 'AGUARDANDO_VALIDACAO',
    ];
    private const STATUS_PROJETO = ['NAO_INICIADO', 'EM_ANDAMENTO', 'CONCLUIDO', 'ATRASADO', 'CANCELADO'];
    private const STATUS_INICIATIVA = ['ABERTA', 'EM_ANDAMENTO', 'CONCLUIDA'];
    private const PRIORIDADES = ['ALTA', 'MEDIA', 'BAIXA'];

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $this->sincronizarAtrasos($planId);
        $this->consolidarProjetos($planId);
        $projetos = Database::todos(
            'SELECT p.*, h.nome AS horizonte_nome, ce.escolha AS escolha_origem
             FROM projeto p
             LEFT JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN cascata_escolha ce ON ce.id = p.cascata_id
             WHERE p.planejamento_id = ?
             ORDER BY p.ano, p.id',
            [$planId]
        );
        foreach ($projetos as &$p) {
            $p['iniciativas'] = Database::todos(
                'SELECT * FROM iniciativa WHERE projeto_id = ? ORDER BY ordem, id',
                [$p['id']]
            );
            $p['desdobramentos'] = Database::todos(
                'SELECT * FROM desdobramento WHERE projeto_id = ? ORDER BY ordem, id',
                [$p['id']]
            );
            foreach ($p['iniciativas'] as &$i) {
                $i['acoes'] = array_values(array_filter(
                    $p['desdobramentos'],
                    fn($d) => (int)$d['iniciativa_id'] === (int)$i['id']
                ));
            }
            unset($i);
        }
        Json::ok($projetos);
    }

    /**
     * "No prazo" e "Atrasada" nunca são escolhidos pelo usuário: são derivados
     * da data-limite da ação. Os demais status são manuais e não se mexe neles.
     * A reconciliação acontece na leitura (sem agendador).
     */
    private function sincronizarAtrasos(int $planId): void
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
     * O período e o status do projeto são consequência das ações: início =
     * menor data de início, fim = maior data de fim; o status agrega os das
     * ações. Recalculado na leitura, cobre também mudanças vindas do diário.
     * Projetos sem ações e os cancelados não são tocados.
     */
    private function consolidarProjetos(int $planId): void
    {
        Database::executar(
            "UPDATE projeto p
             JOIN (
               SELECT projeto_id,
                      MIN(data_inicio) AS di, MAX(data_fim) AS df,
                      COUNT(*) AS n,
                      SUM(status = 'CONCLUIDO') AS concluidas,
                      SUM(status = 'ATRASADO') AS atrasadas,
                      SUM(status IN ('EM_ANDAMENTO', 'PAUSADO', 'AGUARDANDO_VALIDACAO')) AS ativas
               FROM desdobramento GROUP BY projeto_id
             ) x ON x.projeto_id = p.id
             SET p.data_inicio = COALESCE(x.di, p.data_inicio),
                 p.data_fim = COALESCE(x.df, p.data_fim),
                 p.status = CASE
                   WHEN x.atrasadas > 0 THEN 'ATRASADO'
                   WHEN x.concluidas = x.n THEN 'CONCLUIDO'
                   WHEN x.ativas > 0 OR x.concluidas > 0 THEN 'EM_ANDAMENTO'
                   ELSE 'NAO_INICIADO' END
             WHERE p.planejamento_id = ? AND p.status <> 'CANCELADO'",
            [$planId]
        );
    }

    /** Resolve o status de uma ação na gravação, respeitando os manuais. */
    private function resolverStatus(string $status, ?string $dataFim): string
    {
        $atrasada = $dataFim !== null && $dataFim < date('Y-m-d');
        if (in_array($status, ['NAO_INICIADO', 'EM_ANDAMENTO'], true) && $atrasada) {
            return 'ATRASADO';
        }
        if ($status === 'ATRASADO' && !$atrasada) {
            return 'NAO_INICIADO';
        }
        return $status;
    }

    public function salvarIniciativa(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $projetoId = (int)($d['projeto_id'] ?? 0);
        $this->exigirProjeto($projetoId, $planId);

        $titulo = trim($d['titulo'] ?? '');
        if ($titulo === '') {
            Json::erro('Informe o título da iniciativa.');
        }
        $status = $d['status'] ?? 'ABERTA';
        if (!in_array($status, self::STATUS_INICIATIVA, true)) {
            Json::erro('Status da iniciativa inválido.');
        }
        $descricao = trim($d['descricao'] ?? '');

        if ($id) {
            $this->exigirIniciativa($id, $planId);
            Database::executar(
                'UPDATE iniciativa SET titulo = ?, descricao = ?, status = ?, ordem = ? WHERE id = ?',
                [$titulo, $descricao, $status, (int)($d['ordem'] ?? 0), $id]
            );
        } else {
            // ordem = quantidade atual, como no plano de ação de referência
            $ordem = (int)(Database::um(
                'SELECT COUNT(*) AS n FROM iniciativa WHERE projeto_id = ?',
                [$projetoId]
            )['n'] ?? 0);
            $id = (int)Database::executar(
                'INSERT INTO iniciativa (projeto_id, titulo, descricao, status, ordem) VALUES (?, ?, ?, ?, ?)',
                [$projetoId, $titulo, $descricao, $status, $ordem]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluirIniciativa(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirIniciativa($id, $planId);
        // As ações da iniciativa saem junto (FK ON DELETE CASCADE)
        Database::executar('DELETE FROM iniciativa WHERE id = ?', [$id]);
        Json::ok();
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        // O tipo virou legado (a divisão plurianual/anual saiu da interface);
        // o que classifica o projeto agora é o ano do planejamento
        $tipo = $d['tipo'] ?? 'ESTRATEGICO';
        if (!in_array($tipo, ['ESTRATEGICO', 'OPERACIONAL'], true)) {
            $tipo = 'ESTRATEGICO';
        }
        $titulo = trim($d['titulo'] ?? '');
        if ($titulo === '') {
            Json::erro('Informe o título do projeto.');
        }
        $ano = (int)($d['ano'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            Json::erro('Informe o ano do planejamento do projeto.');
        }
        $responsavel = mb_substr(trim($d['responsavel'] ?? ''), 0, 255);
        if ($responsavel === '') {
            Json::erro('Informe o responsável pelo projeto.');
        }
        $descricao = trim($d['descricao'] ?? '');

        // O horizonte não é escolhido: é o que contempla o ano informado
        // (ex.: H1 2027–2030 → ações de 2027 caem obrigatoriamente no H1)
        $horizonte = Database::um(
            'SELECT id FROM horizonte WHERE ciclo_id = ? AND ? BETWEEN ano_inicio AND ano_fim
             ORDER BY ordem, id',
            [(int)$plan['ciclo_id'], $ano]
        );
        if (!$horizonte) {
            Json::erro("Nenhum horizonte do ciclo contempla o ano {$ano}. Ajuste os anos dos horizontes em Cadastros.");
        }
        $horizonteId = (int)$horizonte['id'];

        // O cadastro pede só ano, título, descrição e responsável; datas e
        // status vêm das ações (consolidarProjetos) e o restante é legado,
        // preservado como está nos projetos antigos
        if ($id) {
            $this->exigirProjeto($id, $planId);
            Database::executar(
                'UPDATE projeto SET tipo = ?, ano = ?, titulo = ?, descricao = ?,
                   responsavel = ?, horizonte_id = ? WHERE id = ?',
                [$tipo, $ano, $titulo, $descricao, $responsavel, $horizonteId, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO projeto (planejamento_id, tipo, ano, titulo, descricao,
                   responsavel, horizonte_id, classificacao, status, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, \'NORMAL\', \'NAO_INICIADO\', 0)',
                [$planId, $tipo, $ano, $titulo, $descricao, $responsavel, $horizonteId]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirProjeto($id, $planId);
        // Investimentos vinculados perdem o vínculo (a FK não tem ON DELETE)
        Database::executar('UPDATE investimento SET projeto_id = NULL WHERE projeto_id = ?', [$id]);
        Database::executar('DELETE FROM projeto WHERE id = ?', [$id]);
        Json::ok();
    }

    public function salvarDesdobramento(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $projetoId = (int)($d['projeto_id'] ?? 0);
        $this->exigirProjeto($projetoId, $planId);
        $iniciativaId = (int)($d['iniciativa_id'] ?? 0);
        $iniciativa = $this->exigirIniciativa($iniciativaId, $planId);
        if ((int)$iniciativa['projeto_id'] !== $projetoId) {
            Json::erro('A iniciativa não pertence a este projeto.');
        }

        $oQue = trim($d['o_que'] ?? '');
        if ($oQue === '') {
            Json::erro('Descreva a ação (O quê?).');
        }
        $quem = mb_substr(trim($d['quem'] ?? ''), 0, 255);
        if ($quem === '') {
            Json::erro('Informe o responsável pela ação (Quem?).');
        }
        $status = $d['status'] ?? 'NAO_INICIADO';
        if (!in_array($status, self::STATUS, true)) {
            Json::erro('Status inválido.');
        }
        $prioridade = $d['prioridade'] ?? 'MEDIA';
        if (!in_array($prioridade, self::PRIORIDADES, true)) {
            Json::erro('Prioridade inválida.');
        }
        $progresso = max(0, min(100, (int)($d['progresso'] ?? 0)));
        $quanto = ($d['quanto'] ?? '') !== '' && $d['quanto'] !== null ? (float)$d['quanto'] : null;

        [$inicio, $fim] = $this->periodo($d);
        $status = $this->resolverStatus($status, $fim);
        // Marca (ou limpa) a conclusão conforme o status final
        $anterior = $id ? Database::um('SELECT status, concluido_em FROM desdobramento WHERE id = ?', [$id]) : null;
        $concluidoEm = $status === 'CONCLUIDO'
            ? ($anterior['concluido_em'] ?? null) ?: date('Y-m-d H:i:s')
            : null;

        $params = [
            $projetoId, $iniciativaId, $oQue, trim($d['por_que'] ?? ''),
            $quem,
            mb_substr(trim($d['quando_'] ?? ''), 0, 60),
            $inicio, $fim,
            mb_substr(trim($d['onde'] ?? ''), 0, 120),
            trim($d['como'] ?? ''),
            $quanto, $status, $prioridade, $progresso, $concluidoEm, (int)($d['ordem'] ?? 0),
        ];
        if ($id) {
            $this->exigirDesdobramento($id, $planId);
            Database::executar(
                'UPDATE desdobramento SET projeto_id = ?, iniciativa_id = ?, o_que = ?, por_que = ?, quem = ?,
                   quando_ = ?, data_inicio = ?, data_fim = ?, onde = ?, como = ?,
                   quanto = ?, status = ?, prioridade = ?, progresso = ?, concluido_em = ?, ordem = ?
                 WHERE id = ?',
                [...$params, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO desdobramento (projeto_id, iniciativa_id, o_que, por_que, quem, quando_,
                   data_inicio, data_fim, onde, como, quanto, status, prioridade, progresso, concluido_em, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluirDesdobramento(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirDesdobramento($id, $planId);
        Database::executar('DELETE FROM desdobramento WHERE id = ?', [$id]);
        Json::ok();
    }

    /**
     * Valida o período informado no calendário e devolve [inicio, fim].
     * Datas em branco viram NULL; o fim nunca pode anteceder o início.
     */
    private function periodo(array $d): array
    {
        $ler = static function (?string $valor): ?string {
            $valor = trim((string)$valor);
            if ($valor === '') {
                return null;
            }
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
            if (!$data || $data->format('Y-m-d') !== $valor) {
                Json::erro('Data inválida — use o calendário para escolher.');
            }
            return $valor;
        };
        $inicio = $ler($d['data_inicio'] ?? null);
        $fim = $ler($d['data_fim'] ?? null);
        if ($inicio !== null && $fim !== null && $fim < $inicio) {
            Json::erro('A data de fim não pode ser anterior à de início.');
        }
        return [$inicio, $fim];
    }

    private function exigirProjeto(int $id, int $planId): array
    {
        $projeto = Database::um(
            'SELECT * FROM projeto WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$projeto) {
            Json::erro('Projeto não encontrado neste planejamento.', 404);
        }
        return $projeto;
    }

    private function exigirIniciativa(int $id, int $planId): array
    {
        $iniciativa = Database::um(
            'SELECT i.* FROM iniciativa i JOIN projeto p ON p.id = i.projeto_id
             WHERE i.id = ? AND p.planejamento_id = ?',
            [$id, $planId]
        );
        if (!$iniciativa) {
            Json::erro('Iniciativa não encontrada neste planejamento.', 404);
        }
        return $iniciativa;
    }

    private function exigirDesdobramento(int $id, int $planId): void
    {
        if (!Database::um(
            'SELECT d.id FROM desdobramento d JOIN projeto p ON p.id = d.projeto_id
             WHERE d.id = ? AND p.planejamento_id = ?',
            [$id, $planId]
        )) {
            Json::erro('Desdobramento não encontrado neste planejamento.', 404);
        }
    }
}

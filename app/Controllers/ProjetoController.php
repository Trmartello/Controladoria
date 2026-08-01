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
    private const RECORRENCIAS = ['NENHUMA', 'SEMANAL', 'MENSAL'];

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

    /**
     * Próxima data de uma ação recorrente a partir de uma data-base.
     * SEMANAL: próximo dia da semana escolhido (1=segunda … 7=domingo).
     * MENSAL: mesmo dia no mês seguinte, ajustado quando o mês é mais curto
     * (dia 31 em abril vira 30).
     */
    private function proximaOcorrencia(string $base, string $recorrencia, int $dia): ?string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $base);
        if (!$d) {
            return null;
        }
        if ($recorrencia === 'SEMANAL') {
            $alvo = max(1, min(7, $dia));
            $atual = (int)$d->format('N');
            $somar = ($alvo - $atual + 7) % 7;
            return $d->modify('+' . ($somar === 0 ? 7 : $somar) . ' days')->format('Y-m-d');
        }
        if ($recorrencia === 'MENSAL') {
            $alvo = max(1, min(31, $dia));
            $mes = $d->modify('first day of next month');
            $ultimo = (int)$mes->format('t');
            return $mes->setDate((int)$mes->format('Y'), (int)$mes->format('n'), min($alvo, $ultimo))
                ->format('Y-m-d');
        }
        return null;
    }

    /**
     * Ação recorrente concluída não encerra: registra a conclusão no diário e
     * reabre na próxima data prevista. Devolve os campos já ajustados, ou null
     * quando não há recorrência (ou o limite foi atingido).
     */
    private function reagendarRecorrente(array $acao, string $recorrencia, ?int $dia, ?string $ate, ?string $fim): ?array
    {
        if ($recorrencia === 'NENHUMA' || !$dia) {
            return null;
        }
        $base = $fim ?: date('Y-m-d');
        $proxima = $this->proximaOcorrencia($base, $recorrencia, $dia);
        if (!$proxima || ($ate !== null && $proxima > $ate)) {
            return null; // passou do limite: a ação encerra de vez
        }
        // Mantém a mesma janela entre início e fim na próxima ocorrência
        $novoInicio = null;
        if (!empty($acao['data_inicio']) && $fim) {
            $dias = (int)((new \DateTimeImmutable($fim))->diff(new \DateTimeImmutable($acao['data_inicio']))->days);
            $novoInicio = (new \DateTimeImmutable($proxima))->modify("-{$dias} days")->format('Y-m-d');
        }
        return ['data_inicio' => $novoInicio, 'data_fim' => $proxima];
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

        // Repetição da ação (ex.: toda segunda-feira, ou todo dia 5)
        $recorrencia = $d['recorrencia'] ?? 'NENHUMA';
        if (!in_array($recorrencia, self::RECORRENCIAS, true)) {
            Json::erro('Repetição inválida.');
        }
        $recDia = $recorrencia === 'NENHUMA' ? null : (int)($d['recorrencia_dia'] ?? 0);
        if ($recorrencia !== 'NENHUMA') {
            $limite = $recorrencia === 'SEMANAL' ? 7 : 31;
            if ($recDia < 1 || $recDia > $limite) {
                Json::erro($recorrencia === 'SEMANAL'
                    ? 'Escolha o dia da semana da repetição.'
                    : 'Escolha o dia do mês da repetição.');
            }
            if ($fim === null) {
                Json::erro('Ação que se repete precisa de uma data de fim — é dela que sai a próxima data.');
            }
        }
        $recAte = null;
        if ($recorrencia !== 'NENHUMA' && trim((string)($d['recorrencia_ate'] ?? '')) !== '') {
            [$recAte] = $this->periodo(['data_inicio' => $d['recorrencia_ate']]);
        }

        // Marca (ou limpa) a conclusão conforme o status final
        $anterior = $id ? Database::um('SELECT * FROM desdobramento WHERE id = ?', [$id]) : null;
        $concluidoEm = $status === 'CONCLUIDO'
            ? ($anterior['concluido_em'] ?? null) ?: date('Y-m-d H:i:s')
            : null;

        // Concluir uma ação recorrente reabre na próxima data prevista
        $reagendou = null;
        if ($status === 'CONCLUIDO' && $recorrencia !== 'NENHUMA'
            && ($anterior === null || $anterior['status'] !== 'CONCLUIDO')) {
            $reagendou = $this->reagendarRecorrente(
                ['data_inicio' => $inicio],
                $recorrencia,
                $recDia,
                $recAte,
                $fim
            );
            if ($reagendou !== null) {
                $inicio = $reagendou['data_inicio'];
                $fim = $reagendou['data_fim'];
                $status = $this->resolverStatus('NAO_INICIADO', $fim);
                $progresso = 0;
                $concluidoEm = null;
            }
        }

        $params = [
            $projetoId, $iniciativaId, $oQue, trim($d['por_que'] ?? ''),
            $quem, $this->usuarioPorNome($quem),
            $recorrencia, $recDia, $recAte,
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
                   quem_usuario_id = ?, recorrencia = ?, recorrencia_dia = ?, recorrencia_ate = ?,
                   quando_ = ?, data_inicio = ?, data_fim = ?, onde = ?, como = ?,
                   quanto = ?, status = ?, prioridade = ?, progresso = ?, concluido_em = ?, ordem = ?
                 WHERE id = ?',
                [...$params, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO desdobramento (projeto_id, iniciativa_id, o_que, por_que, quem, quem_usuario_id,
                   recorrencia, recorrencia_dia, recorrencia_ate, quando_,
                   data_inicio, data_fim, onde, como, quanto, status, prioridade, progresso, concluido_em, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                $params
            );
        }

        // A conclusão de uma ocorrência fica registrada no diário de bordo
        if ($reagendou !== null) {
            Database::executar(
                "INSERT INTO diario_bordo (ref_tipo, ref_id, data_reg, autor_id, texto, status_atual, progresso)
                 VALUES ('DESDOBRAMENTO', ?, CURDATE(), ?, ?, 'CONCLUIDO', 100)",
                [
                    $id,
                    (int)Auth::usuario()['id'],
                    'Ocorrência concluída; próxima prevista para '
                        . date('d/m/Y', strtotime($reagendou['data_fim'])) . '.',
                ]
            );
        }
        Json::ok(['id' => $id, 'reagendada_para' => $reagendou['data_fim'] ?? null]);
    }

    /** Casa o nome digitado em "Quem?" com um usuário ativo de mesmo nome. */
    private function usuarioPorNome(string $nome): ?int
    {
        if ($nome === '') {
            return null;
        }
        $u = Database::um('SELECT id FROM usuario WHERE ativo = 1 AND nome = ?', [$nome]);
        return $u ? (int)$u['id'] : null;
    }

    /**
     * Ajuste rápido do progresso pela barra do próprio cartão, sem abrir o
     * formulário. Mexe só no percentual — status, prazo e recorrência seguem
     * como estão (concluir continua sendo uma decisão explícita no formulário).
     */
    public function atualizarProgresso(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirDesdobramento($id, $planId);
        if (!array_key_exists('progresso', $d) || !is_numeric($d['progresso'])) {
            Json::erro('Informe o progresso.');
        }
        $progresso = max(0, min(100, (int)$d['progresso']));
        Database::executar('UPDATE desdobramento SET progresso = ? WHERE id = ?', [$progresso, $id]);
        Json::ok(['progresso' => $progresso]);
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

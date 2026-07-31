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
        $projetos = Database::todos(
            'SELECT p.*, h.nome AS horizonte_nome, ce.escolha AS escolha_origem
             FROM projeto p
             LEFT JOIN horizonte h ON h.id = p.horizonte_id
             LEFT JOIN cascata_escolha ce ON ce.id = p.cascata_id
             WHERE p.planejamento_id = ?
             ORDER BY p.ano, p.ordem, p.id',
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
        $status = $d['status'] ?? 'NAO_INICIADO';
        if (!in_array($status, self::STATUS_PROJETO, true)) {
            Json::erro('Status inválido.');
        }
        $impacto = $d['impacto'] ?? null;
        if ($impacto !== null && $impacto !== ''
            && !in_array($impacto, ['RENTABILIDADE', 'FATURAMENTO', 'SUSTENTABILIDADE', 'PESSOAS'], true)) {
            Json::erro('Impacto inválido.');
        }
        $classificacao = $d['classificacao'] ?? 'NORMAL';
        if (!in_array($classificacao, ['PRIORITARIO', 'NORMAL'], true)) {
            Json::erro('Classificação inválida.');
        }

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
        $cascataId = !empty($d['cascata_id']) ? (int)$d['cascata_id'] : null;
        if ($cascataId !== null && !Database::um(
            'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            [$cascataId, $planId]
        )) {
            Json::erro('Escolha da cascata não pertence a este planejamento.');
        }

        [$inicio, $fim] = $this->periodo($d);

        $params = [
            $tipo, $ano, $titulo,
            mb_substr(trim($d['responsavel'] ?? ''), 0, 255),
            mb_substr(trim($d['prazo'] ?? ''), 0, 60),
            $inicio, $fim,
            $horizonteId, $cascataId, $impacto ?: null, $classificacao, $status,
            (int)($d['ordem'] ?? 0),
        ];
        if ($id) {
            $this->exigirProjeto($id, $planId);
            Database::executar(
                'UPDATE projeto SET tipo = ?, ano = ?, titulo = ?, responsavel = ?, prazo = ?,
                   data_inicio = ?, data_fim = ?,
                   horizonte_id = ?, cascata_id = ?, impacto = ?, classificacao = ?,
                   status = ?, ordem = ? WHERE id = ?',
                [...$params, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO projeto (planejamento_id, tipo, ano, titulo, responsavel, prazo,
                   data_inicio, data_fim,
                   horizonte_id, cascata_id, impacto, classificacao, status, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$planId, ...$params]
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
            mb_substr(trim($d['quem'] ?? ''), 0, 255),
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

    private function exigirProjeto(int $id, int $planId): void
    {
        if (!Database::um(
            'SELECT id FROM projeto WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        )) {
            Json::erro('Projeto não encontrado neste planejamento.', 404);
        }
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

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Governança de investimentos — o funil "do plano ao capital":
 * envelope (quanto há) → papel (agrupa antes de ordenar) → ranking por taxa
 * de retorno → decisão com critério registrado → auditoria +12M.
 */
class InvestimentoController
{
    private const PAPEIS = ['OBRIGATORIO', 'MANUTENCAO', 'EFICIENCIA', 'CRESCIMENTO', 'ESTRATEGICO'];
    /**
     * Transições permitidas na EDIÇÃO (decidir e auditar têm ações próprias).
     *
     * Antes as três situações "básicas" (PROPOSTO, RANQUEADO, EXECUTADO) eram
     * um conjunto livremente intercambiável, e por ali vazava justamente o que
     * a regra proíbe: um PROPOSTO pulava direto para EXECUTADO sem passar por
     * decisão nenhuma, e um EXECUTADO — já com critério e data de decisão
     * gravados — voltava a PROPOSTO e sumia do comprometido do painel.
     */
    private const TRANSICOES = [
        'PROPOSTO'  => ['RANQUEADO'],
        'RANQUEADO' => ['PROPOSTO'],
        'APROVADO'  => ['EXECUTADO'],
        'EXECUTADO' => [],
        'REPROVADO' => [],
        'AUDITADO'  => [],
    ];

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);

        $horizontes = Database::todos(
            'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
            [(int)$plan['ciclo_id']]
        );
        $envelopes = Database::todos(
            'SELECT e.*, h.nome AS horizonte_nome FROM envelope_capital e
             JOIN horizonte h ON h.id = e.horizonte_id
             WHERE e.planejamento_id = ?',
            [$planId]
        );
        // `comentarios` conta o que a exclusão leva junto: o comentário é
        // polimórfico (`ref_tipo`/`ref_id`), não tem FK, e quem o apaga é o
        // `excluir` daqui — em silêncio, até a tela passar a dizê-lo antes.
        // Subconsulta na listagem que já existe, não uma chamada por cartão.
        $investimentos = Database::todos(
            "SELECT i.*, h.nome AS horizonte_nome, p.titulo AS projeto_titulo,
                    (SELECT COUNT(*) FROM comentario c
                      WHERE c.ref_tipo = 'INVESTIMENTO' AND c.ref_id = i.id) AS comentarios
             FROM investimento i
             LEFT JOIN horizonte h ON h.id = i.horizonte_id
             LEFT JOIN projeto p ON p.id = i.projeto_id
             WHERE i.planejamento_id = ?
             ORDER BY i.papel, i.taxa_retorno DESC, i.id",
            [$planId]
        );

        Json::ok([
            'horizontes'    => $horizontes,
            'envelopes'     => $envelopes,
            'investimentos' => $investimentos,
        ]);
    }

    /** Envelope de capital por horizonte (um por horizonte). */
    public function salvarEnvelope(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $horizonteId = (int)($d['horizonte_id'] ?? 0);
        if (!Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        $valor = (float)($d['valor_limite'] ?? 0);
        if ($valor <= 0) {
            Json::erro('Informe o valor limite do envelope.');
        }
        $flex = (float)($d['flex_percentual'] ?? 0);
        $regras = trim($d['regras'] ?? '');

        $existente = Database::um(
            'SELECT id FROM envelope_capital WHERE planejamento_id = ? AND horizonte_id = ?',
            [$planId, $horizonteId]
        );
        if ($existente) {
            if ($id && (int)$existente['id'] !== $id) {
                // Editar o envelope A movendo-o para o horizonte do envelope B
                // sobrescreveria B em silêncio — bloqueia.
                Json::erro('Este horizonte já tem envelope definido. Edite o envelope do próprio horizonte.');
            }
            $id = (int)$existente['id']; // um envelope por horizonte: atualiza o existente
        }

        if ($id) {
            Database::executar(
                'UPDATE envelope_capital SET horizonte_id = ?, valor_limite = ?, flex_percentual = ?, regras = ?
                 WHERE id = ? AND planejamento_id = ?',
                [$horizonteId, $valor, $flex, $regras, $id, $planId]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO envelope_capital (planejamento_id, horizonte_id, valor_limite, flex_percentual, regras)
                 VALUES (?, ?, ?, ?, ?)',
                [$planId, $horizonteId, $valor, $flex, $regras]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);

        $descricao = trim($d['descricao'] ?? '');
        $ano = (int)($d['ano'] ?? 0);
        $valor = (float)($d['valor'] ?? 0);
        if ($descricao === '' || !$ano || $valor <= 0) {
            Json::erro('Informe descrição, ano e valor do investimento.');
        }
        $papel = $d['papel'] ?? '';
        if ($papel !== '' && !in_array($papel, self::PAPEIS, true)) {
            Json::erro('Papel do investimento inválido.');
        }
        $taxa = ($d['taxa_retorno'] ?? '') !== '' && $d['taxa_retorno'] !== null
            ? (float)$d['taxa_retorno'] : null;

        $horizonteId = !empty($d['horizonte_id']) ? (int)$d['horizonte_id'] : null;
        if ($horizonteId !== null && !Database::um(
            'SELECT id FROM horizonte WHERE id = ? AND ciclo_id = ?',
            [$horizonteId, (int)$plan['ciclo_id']]
        )) {
            Json::erro('Horizonte não pertence ao ciclo deste planejamento.');
        }
        $projetoId = !empty($d['projeto_id']) ? (int)$d['projeto_id'] : null;
        if ($projetoId !== null && !Database::um(
            'SELECT id FROM projeto WHERE id = ? AND planejamento_id = ?',
            [$projetoId, $planId]
        )) {
            Json::erro('Projeto não pertence a este planejamento.');
        }

        if ($id) {
            $inv = $this->exigirInvestimento($id, $planId);
            // Situação básica editável; decisão e auditoria têm ações próprias.
            // Um investimento decidido nunca volta a PROPOSTO por edição:
            // APROVADO só avança para EXECUTADO; REPROVADO/AUDITADO ficam como estão.
            $situacao = $inv['situacao'];
            $nova = $d['situacao'] ?? '';
            if ($nova !== '' && $nova !== $situacao) {
                // Transição fora da tabela é RECUSADA, não ignorada: a tela
                // mostrava "salvo" com a situação intocada.
                if (!in_array($nova, self::TRANSICOES[$situacao] ?? [], true)) {
                    Json::erro("Um investimento {$situacao} não pode passar a {$nova}."
                        . ' Decisão e auditoria têm ações próprias.', 409, 'TRANSICAO_INVALIDA');
                }
                $situacao = $nova;
            }
            Database::executar(
                'UPDATE investimento SET descricao = ?, papel = ?, ano = ?, valor = ?,
                   taxa_retorno = ?, horizonte_id = ?, projeto_id = ?, situacao = ?
                 WHERE id = ?',
                [$descricao, $papel ?: null, $ano, $valor, $taxa, $horizonteId, $projetoId, $situacao, $id]
            );
        } else {
            $situacao = $taxa !== null ? 'RANQUEADO' : 'PROPOSTO';
            $id = (int)Database::executar(
                'INSERT INTO investimento (planejamento_id, descricao, papel, ano, valor,
                   taxa_retorno, horizonte_id, projeto_id, situacao)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$planId, $descricao, $papel ?: null, $ano, $valor, $taxa, $horizonteId, $projetoId, $situacao]
            );
        }
        Json::ok(['id' => $id]);
    }

    /** Decisão: aprova ou reprova, com critério registrado. */
    public function decidir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $inv = $this->exigirInvestimento($id, $planId);

        $situacao = $d['situacao'] ?? '';
        if (!in_array($situacao, ['APROVADO', 'REPROVADO'], true)) {
            Json::erro('A decisão deve ser APROVADO ou REPROVADO.');
        }
        // Decidir é o passo que SAI de PROPOSTO/RANQUEADO. Sem esta guarda um
        // investimento já EXECUTADO ou AUDITADO podia ser reprovado: o valor
        // saía do "comprometido" do painel e do relatório (que somam APROVADO,
        // EXECUTADO e AUDITADO), o envelope de capital passava a mostrar folga
        // que não existe, e a auditoria ficava pendurada numa linha reprovada.
        // É a mesma regra que `salvar()` já aplica pela tabela TRANSICOES —
        // ela só não alcançava este caminho.
        if (!in_array($inv['situacao'], ['PROPOSTO', 'RANQUEADO'], true)) {
            Json::erro(
                'Este investimento já foi decidido e está como ' . $inv['situacao']
                    . '. Decisão só a partir de proposto ou ranqueado.',
                409,
                'JA_DECIDIDO'
            );
        }
        $criterio = trim($d['decisao_criterio'] ?? '');
        if ($criterio === '') {
            Json::erro('Registre o critério da decisão — a cascata dá direção, não aprovação.');
        }
        $data = $d['decisao_data'] ?? date('Y-m-d');
        // Formato E calendário: "2026-13-40" passava pela regex e virava 500
        // no modo estrito do MySQL.
        $dt = is_string($data) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $data) : false;
        if (!$dt || $dt->format('Y-m-d') !== $data) {
            Json::erro('Data da decisão inválida.');
        }
        Database::executar(
            'UPDATE investimento SET situacao = ?, decisao_criterio = ?, decisao_data = ? WHERE id = ?',
            [$situacao, $criterio, $data, $id]
        );
        Json::ok();
    }

    /** Auditoria +12M: prometido × realizado. */
    public function auditar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $inv = $this->exigirInvestimento($id, $planId);

        if (!in_array($inv['situacao'], ['APROVADO', 'EXECUTADO'], true)) {
            Json::erro('Só é possível auditar investimentos aprovados ou executados.');
        }
        $realizado = (float)($d['valor_realizado'] ?? 0);
        if ($realizado <= 0) {
            Json::erro('Informe o valor realizado.');
        }
        Database::executar(
            'UPDATE investimento SET situacao = \'AUDITADO\', valor_realizado = ?, auditoria_nota = ?
             WHERE id = ?',
            [$realizado, trim($d['auditoria_nota'] ?? ''), $id]
        );
        Json::ok();
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirInvestimento($id, $planId);
        Database::executar(
            "DELETE FROM comentario WHERE ref_tipo = 'INVESTIMENTO' AND ref_id = ?", [$id]
        );
        Database::executar('DELETE FROM investimento WHERE id = ?', [$id]);
        Json::ok();
    }

    private function exigirInvestimento(int $id, int $planId): array
    {
        $inv = Database::um(
            'SELECT * FROM investimento WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$inv) {
            Json::erro('Investimento não encontrado neste planejamento.', 404);
        }
        return $inv;
    }
}

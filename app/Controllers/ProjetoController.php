<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Services\Consolidacao;
use App\Services\Recorrencia;

class ProjetoController
{
    /** Status da ação: os dois primeiros são automáticos (regidos pela data-limite). */
    private const STATUS = [
        'NAO_INICIADO', 'ATRASADO',
        'EM_ANDAMENTO', 'CONCLUIDO', 'CANCELADO', 'PAUSADO', 'AGUARDANDO_VALIDACAO',
    ];
    private const STATUS_PROJETO = ['NAO_INICIADO', 'EM_ANDAMENTO', 'CONCLUIDO', 'ATRASADO', 'CANCELADO'];
    private const PRIORIDADES = ['ALTA', 'MEDIA', 'BAIXA'];
    private const RECORRENCIAS = Recorrencia::TIPOS;

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        Consolidacao::reconciliar($planId);
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
        $descricao = trim($d['descricao'] ?? '');

        // O status da frente NÃO vem do corpo: ele é consequência das ações
        // (Consolidacao::consolidarIniciativas, recalculado em toda leitura).
        // Aceitá-lo aqui seria gravar um valor que a primeira leitura apaga.
        if ($id) {
            $this->exigirIniciativa($id, $planId);
            Database::executar(
                'UPDATE iniciativa SET titulo = ?, descricao = ?, ordem = ? WHERE id = ?',
                [$titulo, $descricao, (int)($d['ordem'] ?? 0), $id]
            );
        } else {
            // ordem = quantidade atual, como no plano de ação de referência
            $ordem = (int)(Database::um(
                'SELECT COUNT(*) AS n FROM iniciativa WHERE projeto_id = ?',
                [$projetoId]
            )['n'] ?? 0);
            $id = (int)Database::executar(
                "INSERT INTO iniciativa (projeto_id, titulo, descricao, status, ordem)
                 VALUES (?, ?, ?, 'ABERTA', ?)",
                [$projetoId, $titulo, $descricao, $ordem]
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
        // As ações da iniciativa saem junto (FK ON DELETE CASCADE) — e por
        // isso o que aponta para elas precisa ser solto ANTES do DELETE:
        // depois, a subconsulta não as encontraria mais
        $this->soltarAcoes('iniciativa_id = ?', [$id]);
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

        // Vínculo com a escolha da Cascata que originou o projeto. A coluna e o
        // JOIN da listagem já existiam ("↳ Escolha da cascata" no cartão), mas
        // nada gravava o valor — o campo era inalcançável pela interface.
        // A escolha precisa ser DESTE planejamento, senão um id de outro negócio
        // entraria pelo corpo da requisição.
        $cascataId = !empty($d['cascata_id']) ? (int)$d['cascata_id'] : null;
        if ($cascataId !== null && !Database::um(
            'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            [$cascataId, $planId]
        )) {
            Json::erro('A escolha da cascata não pertence a este planejamento.');
        }

        // O cadastro pede só ano, título, descrição e responsável; datas e
        // status vêm das ações (Consolidacao) e o restante é legado,
        // preservado como está nos projetos antigos
        if ($id) {
            $this->exigirProjeto($id, $planId);
            Database::executar(
                'UPDATE projeto SET tipo = ?, ano = ?, titulo = ?, descricao = ?,
                   responsavel = ?, horizonte_id = ?, cascata_id = ? WHERE id = ?',
                [$tipo, $ano, $titulo, $descricao, $responsavel, $horizonteId, $cascataId, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO projeto (planejamento_id, tipo, ano, titulo, descricao,
                   responsavel, horizonte_id, cascata_id, classificacao, status, ordem)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'NORMAL\', \'NAO_INICIADO\', 0)',
                [$planId, $tipo, $ano, $titulo, $descricao, $responsavel, $horizonteId, $cascataId]
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
        // Comentários do projeto (ref_tipo/ref_id é polimórfico e não tem FK
        // que os leve junto; os anexos descem com o comentário, por CASCADE)
        Database::executar(
            "DELETE FROM comentario WHERE ref_tipo = 'PROJETO' AND ref_id = ?", [$id]
        );
        // As ações do projeto saem em cascata: solta o que aponta para elas
        $this->soltarAcoes('projeto_id = ?', [$id]);
        Database::executar('DELETE FROM projeto WHERE id = ?', [$id]);
        Json::ok();
    }

    public function salvarDesdobramento(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $plan = Auth::exigirEdicaoPlanejamento($planId);
        // Valida e calcula TUDO que não depende de projeto/iniciativa antes de
        // criar qualquer linha. Criar o projeto/iniciativa na hora e só então
        // esbarrar numa validação da ação deixava projeto e iniciativa órfãos —
        // sem transação no repositório, eles ficavam para trás a cada erro,
        // acumulando projetos vazios a cada tentativa mal-sucedida.
        $oQue = trim($d['o_que'] ?? '');
        if ($oQue === '') {
            Json::erro('Descreva a ação (O quê?).');
        }
        // "Como?" e o período passaram a ser exigidos junto com o quê/quem: ação
        // sem caminho e sem prazo não é plano, é intenção — e o prazo é o que
        // alimenta o atraso automático, os avisos por e-mail e o painel. A
        // guarda mora AQUI e não só no asterisco da tela: o `obrigatorio` do
        // modal desenha o asterisco, não recusa o envio, e este mesmo endpoint
        // recebe também o direcionamento de uma ideia da Coleta.
        $como = trim($d['como'] ?? '');
        if ($como === '') {
            Json::erro('Descreva como a ação será feita (Como?).');
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
        $progresso = $this->arredondarProgresso((int)($d['progresso'] ?? 0));
        $quanto = ($d['quanto'] ?? '') !== '' && $d['quanto'] !== null ? (float)$d['quanto'] : null;

        [$inicio, $fim] = $this->periodo($d);
        if ($inicio === null || $fim === null) {
            Json::erro('Informe o período da ação (Quando?) — início e fim previsto.');
        }
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
            $reagendou = Recorrencia::reagendar($inicio, $recorrencia, $recDia, $recAte, $fim);
            if ($reagendou !== null) {
                $inicio = $reagendou['data_inicio'];
                $fim = $reagendou['data_fim'];
                $status = $this->resolverStatus('NAO_INICIADO', $fim);
                $progresso = 0;
                $concluidoEm = null;
            }
        }

        // Só agora — com a ação inteira validada — resolve projeto e iniciativa,
        // criando na hora quando a ação vem de uma ideia da coleta ("Plano de
        // ação"). Os helpers validam os próprios campos antes de inserir.
        // Em duas fases, e nesta ordem: primeiro CONFERE o par projeto+iniciativa
        // por inteiro, só depois insere. Criar o projeto antes de validar a
        // iniciativa deixava um projeto vazio no banco a cada tentativa inválida
        // — não há transação aqui, e Json::erro() encerra a execução na hora.
        $projetoId = (int)($d['projeto_id'] ?? 0);
        $projetoNovo = trim((string)($d['projeto_novo'] ?? ''));
        $iniciativaId = (int)($d['iniciativa_id'] ?? 0);
        $iniciativaNova = trim((string)($d['iniciativa_nova'] ?? ''));

        if (!$projetoId && $projetoNovo === '') {
            Json::erro('Escolha o projeto (ou informe um novo) para a ação.');
        }
        if (!$iniciativaId && $iniciativaNova === '') {
            Json::erro('Escolha a iniciativa (ou informe uma nova) para a ação.');
        }
        // Projeto que já existe é conferido agora; iniciativa que já existe
        // precisa pertencer a ele — e com projeto novo não pode haver
        // iniciativa antiga, que seria de outro projeto por definição
        if ($projetoId) {
            $this->exigirProjeto($projetoId, $planId);
        }
        if ($iniciativaId) {
            $iniciativa = $this->exigirIniciativa($iniciativaId, $planId);
            if (!$projetoId || (int)$iniciativa['projeto_id'] !== $projetoId) {
                Json::erro('A iniciativa não pertence a este projeto.');
            }
        }
        // Validado tudo, agora sim grava
        if (!$projetoId) {
            $projetoId = $this->criarProjetoRapido($plan, $planId, $d);
        }
        if (!$iniciativaId) {
            $iniciativaId = $this->criarIniciativaRapida($projetoId, $iniciativaNova);
        }

        $params = [
            $projetoId, $iniciativaId, $oQue, trim($d['por_que'] ?? ''),
            $quem, $this->usuarioPorNome($quem, $plan),
            $recorrencia, $recDia, $recAte,
            mb_substr(trim($d['quando_'] ?? ''), 0, 60),
            $inicio, $fim,
            mb_substr(trim($d['onde'] ?? ''), 0, 120),
            $como,
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
            // Ação criada a partir de uma ideia da coleta ("Plano de ação"):
            // fecha o vínculo para a ideia deixar de ficar pendente e apontar
            // para o desdobramento que nasceu dela
            // Fator da SWOT encaminhado ao plano: mesmo fechamento de vínculo,
            // e a mesma guarda no WHERE — só fecha o que estava mesmo na fila,
            // para um pedido repetido não sequestrar o vínculo de outra ação.
            $fatorId = (int)($d['fator_id'] ?? 0);
            if ($fatorId) {
                Database::executar(
                    "UPDATE fator SET desdobramento_id = ?
                     WHERE id = ? AND planejamento_id = ? AND etapa = 'SWOT'
                       AND acao_em IS NOT NULL AND desdobramento_id IS NULL",
                    [$id, $fatorId, $planId]
                );
            }
            // Cruzamento da SWOT (TOWS): terceira origem da mesma fila, mesmo
            // fechamento e mesma guarda no WHERE.
            $cruzamentoId = (int)($d['cruzamento_id'] ?? 0);
            if ($cruzamentoId) {
                Database::executar(
                    'UPDATE swot_cruzamento SET desdobramento_id = ?
                     WHERE id = ? AND planejamento_id = ?
                       AND acao_em IS NOT NULL AND desdobramento_id IS NULL',
                    [$id, $cruzamentoId, $planId]
                );
            }
            $coletaId = (int)($d['coleta_item_id'] ?? 0);
            if ($coletaId) {
                Database::executar(
                    "UPDATE coleta_item SET destino_id = ?
                     WHERE id = ? AND planejamento_id = ? AND destino_tipo = 'ACAO' AND destino_id IS NULL",
                    [$id, $coletaId, $planId]
                );
            }
        }

        // A conclusão de uma ocorrência fica registrada nos comentários: a ação
        // volta a NAO_INICIADO na data seguinte, e sem o registro não sobraria
        // rastro nenhum de que ela chegou a ser concluída uma vez.
        if ($reagendou !== null) {
            Database::executar(
                "INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto)
                 VALUES ('DESDOBRAMENTO', ?, ?, ?)",
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

    /**
     * Casa o nome digitado em "Quem?" com um usuário ativo de mesmo nome.
     * Só vale para quem enxerga o planejamento (mesma regra da lista sugerida
     * em UsuarioController::responsaveis) — senão um nome digitado à mão
     * amarraria a ação a alguém de outro negócio, que passaria a recebê-la
     * nos avisos por e-mail sem ter acesso ao planejamento. Fora do escopo,
     * o nome fica apenas como texto livre em `quem`.
     */
    private function usuarioPorNome(string $nome, array $plan): ?int
    {
        if ($nome === '') {
            return null;
        }
        $negocioId = $plan['negocio_id'] !== null ? (int)$plan['negocio_id'] : 0;
        $u = Database::um(
            "SELECT u.id FROM usuario u
             LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
             WHERE u.ativo = 1 AND u.nome = ?
               AND (u.perfil IN ('ADMIN', 'CONTROLADORIA', 'DIRECAO') OR un.negocio_id = ?)
             LIMIT 1",
            [$nome, $negocioId]
        );
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
        $progresso = $this->arredondarProgresso((int)$d['progresso']);
        Database::executar('UPDATE desdobramento SET progresso = ? WHERE id = ?', [$progresso, $id]);
        Json::ok(['progresso' => $progresso]);
    }

    /**
     * O progresso anda de 5 em 5 — na barra, no modal E aqui. Arredondar no
     * servidor é o que impede a tela e o banco de divergirem: um valor fora da
     * grade (legado, ou vindo de um cliente antigo) seria "encaixado" pelo
     * range do navegador e cada salvamento gravaria outro número.
     */
    private function arredondarProgresso(int $p): int
    {
        return (int)(5 * round(max(0, min(100, $p)) / 5));
    }

    public function excluirDesdobramento(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirEdicaoPlanejamento($planId);
        $this->exigirDesdobramento($id, $planId);
        $this->soltarAcoes('id = ?', [$id]);
        Database::executar('DELETE FROM desdobramento WHERE id = ?', [$id]);
        Json::ok();
    }

    /**
     * Solta o que aponta para ações que estão prestes a sair.
     *
     * O CASCADE do banco leva os desdobramentos, mas não alcança nem o
     * comentário nem a ideia da Coleta: os dois apontam por par polimórfico
     * (ref_tipo/ref_id e destino_tipo/destino_id), e par polimórfico não tem
     * FK que o carregue junto.
     *
     * Sem esta limpeza a ideia ficava apontando para uma ação que não existe
     * mais, num beco sem saída permanente: sumia da fila de "aguardando plano
     * de ação" (que filtra `destino_id IS NULL`), seguia marcada como "virou
     * ação", e tanto `reabrir` quanto `excluir` a recusavam justamente por
     * isso. Só `excluirDesdobramento` fazia a limpeza — apagar o PROJETO ou a
     * INICIATIVA derruba as ações por CASCADE sem passar por lá.
     *
     * O fator da SWOT não precisa de linha nenhuma: a FK dele é
     * ON DELETE SET NULL e o banco o devolve para a fila sozinho.
     *
     * @param string $onde   condição sobre `desdobramento`, literal do código
     * @param array  $params os valores dessa condição
     */
    private function soltarAcoes(string $onde, array $params): void
    {
        // A derivada `(SELECT ... FROM (...) x)` é o mesmo contorno já usado
        // aqui para o MySQL não reclamar da subconsulta no DELETE
        $alvo = "(SELECT id FROM (SELECT id FROM desdobramento WHERE {$onde}) x)";
        Database::executar(
            "DELETE FROM comentario WHERE ref_tipo = 'DESDOBRAMENTO' AND ref_id IN {$alvo}",
            $params
        );
        Database::executar(
            "UPDATE coleta_item SET destino_id = NULL
             WHERE destino_tipo = 'ACAO' AND destino_id IN {$alvo}",
            $params
        );
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

    /**
     * Cria um projeto na hora (ideia da coleta que não cabe em nenhum
     * existente). Mesmas regras do cadastro: ano precisa de um horizonte do
     * ciclo, e responsável obrigatório (herda o "Quem?" da ação se não vier).
     */
    private function criarProjetoRapido(array $plan, int $planId, array $d): int
    {
        $titulo = mb_substr(trim((string)$d['projeto_novo']), 0, 255);
        $ano = (int)($d['projeto_ano'] ?? 0);
        if ($ano < 2000 || $ano > 2100) {
            Json::erro('Informe o ano do novo projeto.');
        }
        $responsavel = mb_substr(trim((string)($d['projeto_responsavel'] ?? $d['quem'] ?? '')), 0, 255);
        if ($responsavel === '') {
            Json::erro('Informe o responsável do novo projeto.');
        }
        $horizonte = Database::um(
            'SELECT id FROM horizonte WHERE ciclo_id = ? AND ? BETWEEN ano_inicio AND ano_fim ORDER BY ordem, id',
            [(int)$plan['ciclo_id'], $ano]
        );
        if (!$horizonte) {
            Json::erro("Nenhum horizonte do ciclo contempla o ano {$ano}. Ajuste os horizontes em Cadastros.");
        }
        return (int)Database::executar(
            "INSERT INTO projeto (planejamento_id, tipo, ano, titulo, descricao, responsavel,
               horizonte_id, classificacao, status, ordem)
             VALUES (?, 'ESTRATEGICO', ?, ?, '', ?, ?, 'NORMAL', 'NAO_INICIADO', 0)",
            [$planId, $ano, $titulo, $responsavel, (int)$horizonte['id']]
        );
    }

    /** Cria uma iniciativa na hora sob o projeto informado (mesma ordem do cadastro). */
    private function criarIniciativaRapida(int $projetoId, string $titulo): int
    {
        $titulo = mb_substr(trim($titulo), 0, 255);
        if ($titulo === '') {
            Json::erro('Informe o nome da nova iniciativa.');
        }
        $ordem = (int)(Database::um('SELECT COUNT(*) AS n FROM iniciativa WHERE projeto_id = ?', [$projetoId])['n'] ?? 0);
        return (int)Database::executar(
            "INSERT INTO iniciativa (projeto_id, titulo, descricao, status, ordem) VALUES (?, ?, '', 'ABERTA', ?)",
            [$projetoId, $titulo, $ordem]
        );
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

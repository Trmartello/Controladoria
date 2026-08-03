<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Coleta de ideias — o passo 0 do diagnóstico.
 *
 * A coleta em si tem substituto barato (formulário, planilha, a própria
 * oficina); o que não tem substituto é a **tratativa**: sem ela alguém pega a
 * lista crua e redigita à mão dentro de Cenário/PESTEL/SWOT, e o vínculo entre
 * "o que o Fulano disse na oficina" e "o fator que entrou no plano" se perde.
 * É esse vínculo que este módulo guarda.
 */
class ColetaController
{
    private const DESTINOS_SUGERIDOS = ['CENARIO', 'PESTEL', 'PORTER', 'SWOT', 'NAO_SEI'];
    private const CATEGORIAS = [
        'PESTEL' => ['POLITICO', 'ECONOMICO', 'SOCIAL', 'TECNOLOGICO', 'ECOLOGICO', 'LEGAL'],
        'PORTER' => ['RIVALIDADE', 'NOVOS_ENTRANTES', 'SUBSTITUTOS', 'PODER_FORNECEDORES', 'PODER_CLIENTES'],
        'SWOT'   => ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'],
    ];
    private const TIPOS_CENARIO = ['SITUACAO_ATUAL', 'TENDENCIA'];

    public function listar(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $u = Auth::exigirLogin();
        // A coleta é anual, como todo o diagnóstico
        $ano = (int)($_GET['ano'] ?? 0);
        $filtroAno = $ano ? ' AND ci.ano = ?' : '';
        $params = $ano ? [$planId, $ano] : [$planId];

        $itens = Database::todos(
            "SELECT ci.id, ci.planejamento_id, ci.rodada_id, ci.ano, ci.autor_id,
                    ci.dividido_de_id, ci.agrupado_em_id, ci.adiado, ci.texto, ci.texto_tratado, ci.destino_sugerido,
                    ci.situacao, ci.impacto, ci.esforco, ci.votos, ci.destino_tipo,
                    ci.destino_id, ci.motivo, ci.triado_em, ci.criado_em,
                    COALESCE(a.nome, ci.autor_nome, 'Participante') AS autor, t.nome AS triador,
                    df.etapa AS destino_etapa
             FROM coleta_item ci
             LEFT JOIN usuario a ON a.id = ci.autor_id
             LEFT JOIN usuario t ON t.id = ci.triado_por
             -- A etapa do fator é o que dá nome à tag do destino na matriz:
             -- destino_tipo só diz FATOR, não se virou PESTEL, Porter ou SWOT
             LEFT JOIN fator df ON df.id = ci.destino_id AND ci.destino_tipo = 'FATOR'
             WHERE ci.planejamento_id = ?{$filtroAno}
             ORDER BY ci.situacao = 'NOVO' DESC, ci.criado_em, ci.id",
            $params
        );
        // O front decide o que cada um pode fazer sem repetir a regra de perfil
        foreach ($itens as &$i) {
            $i['minha'] = (int)$i['autor_id'] === (int)$u['id'];
        }
        Json::ok($itens);
    }

    /**
     * Ideias aceitas na triagem com destino "Plano de ação" que ainda não
     * viraram uma ação (desdobramento): a lista que o módulo Projetos mostra
     * para o condutor atribuí-las a uma iniciativa.
     */
    public function aguardandoAcao(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        // LEFT JOIN + COALESCE como em listar(): a ideia vinda da tempestade é
        // anônima (autor_id NULL, nome em autor_nome). Um INNER JOIN aqui
        // sumiria justamente com as ideias do brainstorm mandadas ao plano.
        $itens = Database::todos(
            "SELECT ci.id, ci.ano, ci.texto, ci.texto_tratado, ci.votos, ci.criado_em,
                    COALESCE(u.nome, ci.autor_nome, 'Participante') AS autor
             FROM coleta_item ci
             LEFT JOIN usuario u ON u.id = ci.autor_id
             WHERE ci.planejamento_id = ? AND ci.destino_tipo = 'ACAO'
               AND ci.destino_id IS NULL AND ci.situacao = 'ACEITO'
             ORDER BY ci.criado_em, ci.id",
            [$planId]
        );
        Json::ok($itens);
    }

    public function salvar(?int $id = null): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirRespostaColeta($planId);

        $texto = trim(is_string($d['texto'] ?? null) ? $d['texto'] : '');
        if ($texto === '') {
            Json::erro('Escreva a ideia.');
        }
        $destino = $d['destino_sugerido'] ?? 'NAO_SEI';
        if (!in_array($destino, self::DESTINOS_SUGERIDOS, true)) {
            Json::erro('Destino sugerido inválido.');
        }

        if ($id) {
            // Cada autor mexe só na própria ideia, e só antes de ela ser triada
            $item = $this->exigirItem($id, $planId);
            if ((int)$item['autor_id'] !== (int)$u['id']) {
                Json::erro('Só o autor pode alterar a própria ideia.', 403);
            }
            if ($item['situacao'] !== 'NOVO') {
                Json::erro('Esta ideia já foi triada e não pode mais ser alterada.');
            }
            Database::executar(
                'UPDATE coleta_item SET texto = ?, destino_sugerido = ? WHERE id = ?',
                [$texto, $destino, $id]
            );
        } else {
            $ano = (int)($d['ano'] ?? 0);
            if ($ano < 2000 || $ano > 2100) {
                Json::erro('Informe o ano da coleta.');
            }
            // Ideia cadastrada à mão durante uma tempestade entra NA rodada
            // aberta, para aparecer na nuvem. Só aceita rodada aberta deste
            // planejamento; qualquer outro valor cai para avulsa (NULL).
            $rodadaId = isset($d['rodada_id']) && $d['rodada_id'] !== '' ? (int)$d['rodada_id'] : null;
            if ($rodadaId !== null && !Database::um(
                "SELECT id FROM coleta_rodada WHERE id = ? AND planejamento_id = ? AND situacao = 'ABERTA'",
                [$rodadaId, $planId]
            )) {
                $rodadaId = null;
            }
            $id = (int)Database::executar(
                'INSERT INTO coleta_item (planejamento_id, rodada_id, ano, autor_id, texto, destino_sugerido)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$planId, $rodadaId, $ano, (int)$u['id'], $texto, $destino]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirRespostaColeta($planId);
        $item = $this->exigirItem($id, $planId);
        // O autor apaga a PRÓPRIA ideia enquanto ninguém a triou. Todo o resto
        // — ideia de participante, já classificada ou já encaminhada — é ato de
        // quem conduz, porque apaga também o que ela virou no diagnóstico.
        $proprioNovo = $item['autor_id'] !== null
            && (int)$item['autor_id'] === (int)$u['id']
            && $item['situacao'] === 'NOVO';
        if (!$proprioNovo) {
            Auth::exigirTriagemColeta($planId);
        }

        // Some a caixa inteira: excluir só o líder deixaria as outras órfãs
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));

        $destinos = Database::todos(
            "SELECT DISTINCT destino_tipo, destino_id FROM coleta_item
             WHERE id IN ({$marcas}) AND destino_tipo IS NOT NULL AND destino_id IS NOT NULL",
            $grupo
        );
        // Ação já criada num projeto tem vida própria: apagar por aqui a
        // deixaria órfã, sem rastro de onde veio
        foreach ($destinos as $dst) {
            if ($dst['destino_tipo'] === 'ACAO') {
                Json::erro('Esta ideia já virou uma ação num projeto: exclua por lá antes.');
            }
        }
        foreach ($destinos as $dst) {
            $destinoId = (int)$dst['destino_id'];
            if ($dst['destino_tipo'] === 'CENARIO') {
                Database::executar(
                    'DELETE FROM cenario_item WHERE id = ? AND planejamento_id = ?', [$destinoId, $planId]
                );
            } elseif ($dst['destino_tipo'] === 'FATOR') {
                // Fatores promovidos apontam para o de origem (sem ON DELETE):
                // saem antes. GUT e vínculo com a cascata caem por CASCADE.
                Database::executar('DELETE FROM fator WHERE promovido_de_id = ?', [$destinoId]);
                Database::executar(
                    'DELETE FROM fator WHERE id = ? AND planejamento_id = ?', [$destinoId, $planId]
                );
            }
        }

        // dividido_de_id e agrupado_em_id não têm chave estrangeira: sem soltar
        // aqui, sobrariam apontando para linhas que deixaram de existir
        Database::executar(
            "UPDATE coleta_item SET dividido_de_id = NULL WHERE dividido_de_id IN ({$marcas})", $grupo
        );
        Database::executar(
            "UPDATE coleta_item SET agrupado_em_id = NULL WHERE agrupado_em_id IN ({$marcas})", $grupo
        );
        // Os votos saem por ON DELETE CASCADE (fk_voto_item)
        Database::executar("DELETE FROM coleta_item WHERE id IN ({$marcas})", $grupo);
        Json::ok(['removidas' => count($grupo)]);
    }

    /** Limpa de uma vez as ideias ainda não tratadas de uma rodada. */
    public function limparRodada(int $rodadaId): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        if (!Database::um('SELECT id FROM coleta_rodada WHERE id = ? AND planejamento_id = ?',
            [$rodadaId, $planId])) {
            Json::erro('Rodada não encontrada neste planejamento.', 404);
        }
        $n = Database::afetadas(
            "DELETE FROM coleta_item WHERE rodada_id = ? AND situacao = 'NOVO' AND autor_id IS NULL",
            [$rodadaId]
        );
        Json::ok(['removidas' => $n]);
    }

    /**
     * Aceita a ideia e cria o registro no destino (item de cenário ou fator).
     *
     * Sem transação, por decisão: o repositório não usa `beginTransaction` em
     * lugar nenhum e `Json::erro()` encerra a execução, então abrir uma aqui
     * criaria um padrão novo justamente onde sair no meio é comum. Em vez
     * disso, **reserva atômica**: o UPDATE que marca a ideia como aceita só
     * afeta uma linha se ela ainda não tiver destino. O duplo clique perde a
     * corrida e o pior caso vira um registro órfão, nunca um vínculo perdido.
     */
    public function encaminhar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);

        // O texto complementado na bancada é a razão de ela existir: só cai
        // no texto cru se não houver complemento nenhum
        $texto = trim(is_string($d['texto_tratado'] ?? null) ? $d['texto_tratado'] : '')
            ?: ($item['texto_tratado'] ?: $item['texto']);
        $destino = $d['destino'] ?? '';

        if ($destino === 'CENARIO') {
            $tipo = $d['tipo'] ?? '';
            if (!in_array($tipo, self::TIPOS_CENARIO, true)) {
                Json::erro('Escolha se é situação atual ou tendência.');
            }
        } elseif ($destino === 'ACAO') {
            // Plano de ação: a ideia fica aceita e pendente; vira desdobramento
            // depois, no módulo Projetos. Nada a validar aqui.
        } elseif (isset(self::CATEGORIAS[$destino])) {
            $categoria = $d['categoria'] ?? '';
            if (!in_array($categoria, self::CATEGORIAS[$destino], true)) {
                Json::erro('Escolha a categoria do destino.');
            }
        } else {
            Json::erro('Destino inválido.');
        }

        // Quando três pessoas disseram o mesmo, a nuvem mostra "×3": tratar o
        // representante e deixar as outras para trás faria o condutor
        // encaminhar de novo e criar fatores duplicados
        $grupo = $this->grupo($id, $planId);
        $reservados = [];
        foreach ($grupo as $gid) {
            if ($this->reservar($gid, $planId, (int)$u['id'])) {
                $reservados[] = $gid;
            }
        }
        if (!$reservados) {
            Json::erro('Esta ideia já foi tratada por outra pessoa.');
        }

        // O ano vem da ideia, nunca do seletor da tela: herdar do seletor
        // corromperia a análise anual em silêncio
        $ano = (int)$item['ano'];
        if ($destino === 'CENARIO') {
            $destinoId = (int)Database::executar(
                'INSERT INTO cenario_item (planejamento_id, ano, tipo, ordem, descricao) VALUES (?, ?, ?, 0, ?)',
                [$planId, $ano, $d['tipo'], $texto]
            );
            $destinoTipo = 'CENARIO';
        } elseif ($destino === 'ACAO') {
            // Sem registro ainda: fica pendente até virar ação no plano (Projetos)
            $destinoId = null;
            $destinoTipo = 'ACAO';
        } else {
            $destinoId = (int)Database::executar(
                'INSERT INTO fator (planejamento_id, ano, etapa, categoria, descricao) VALUES (?, ?, ?, ?, ?)',
                [$planId, $ano, $destino, $d['categoria'], $texto]
            );
            $destinoTipo = 'FATOR';
        }
        // Todas as ideias do grupo apontam para o mesmo registro criado
        $marcas = implode(',', array_fill(0, count($reservados), '?'));
        Database::executar(
            "UPDATE coleta_item SET texto_tratado = ?, destino_tipo = ?, destino_id = ?
             WHERE id IN ({$marcas})",
            [$texto, $destinoTipo, $destinoId, ...$reservados]
        );
        Json::ok([
            'destino_tipo' => $destinoTipo, 'destino_id' => $destinoId,
            'secao' => $this->secao($destino), 'agrupadas' => count($reservados),
        ]);
    }

    /**
     * Descarta a ideia. O motivo é obrigatório e fica visível ao autor: é o
     * que transforma veto silencioso em aprendizado e dá legitimidade ao
     * processo — quem escreveu vê por que a ideia não entrou.
     */
    public function descartar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirTriagemColeta($planId);
        $this->exigirItem($id, $planId);

        $motivo = trim(is_string($d['motivo'] ?? null) ? $d['motivo'] : '');
        if ($motivo === '') {
            Json::erro('Explique por que a ideia não entra — o autor vê este motivo.');
        }
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
        $linhas = Database::afetadas(
            "UPDATE coleta_item SET situacao = 'DESCARTADO', motivo = ?, triado_por = ?, triado_em = NOW()
             WHERE id IN ({$marcas}) AND planejamento_id = ? AND situacao IN ('NOVO','SELECIONADO')",
            [$motivo, (int)$u['id'], ...$grupo, $planId]
        );
        if (!$linhas) {
            Json::erro('Esta ideia já foi tratada por outra pessoa.');
        }
        Json::ok(['agrupadas' => $linhas]);
    }

    /**
     * Posiciona a ideia na matriz de priorização da oficina.
     *
     * `impacto` e `esforco` vivem só aqui: ao virar fator, quem prioriza é a
     * Matriz GUT, com score e rastro. Copiar estes valores para o `fator`
     * criaria duas priorizações concorrentes (decisão registrada no backlog).
     */
    public function priorizar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        if (in_array($item['situacao'], ['DESCARTADO', 'DIVIDIDO'], true)) {
            Json::erro('Esta ideia já foi tratada.');
        }
        // Encaminhada continua na matriz e pode mudar de quadrante: ali só a
        // POSIÇÃO muda — a situação e o destino ficam intactos.
        $encaminhada = $item['situacao'] === 'ACEITO';

        // Tocar de novo no quadrante já escolhido DESMARCA: a classificação é
        // apagada e a ideia (o grupo inteiro) volta para a fila, como
        // "a tratar" — sem rota nova, é o inverso natural desta.
        if (!empty($d['limpar'])) {
            $grupo = $this->grupo($id, $planId);
            $marcas = implode(',', array_fill(0, count($grupo), '?'));
            // Encaminhada também sai da matriz — mas continua encaminhada: quem
            // decide se ela sai TAMBÉM da análise é o `reabrir`, pedido à parte.
            // Mexer na situação aqui apagaria o destino sem ninguém pedir.
            Database::executar($encaminhada
                ? "UPDATE coleta_item SET impacto = NULL, esforco = NULL WHERE id IN ({$marcas})"
                : "UPDATE coleta_item SET impacto = NULL, esforco = NULL, situacao = 'NOVO'
                   WHERE id IN ({$marcas})",
                [...$grupo]
            );
            Json::ok(['limpo' => true]);
        }

        $impacto = $d['impacto'] ?? null;
        $esforco = $d['esforco'] ?? null;
        if (!in_array($impacto, ['ALTO', 'BAIXO'], true) || !in_array($esforco, ['BAIXO', 'ALTO'], true)) {
            Json::erro('Escolha um quadrante da matriz.');
        }
        // Posicionar tira da caixa de "tratar depois" e vale para o grupo todo.
        // O quadrante "Descartar" (baixo impacto, alto esforço) é a exceção que
        // dá sentido à matriz "decidir o encaminhamento": ali a matriz decide
        // ESQUECER. Registra a posição mas NÃO marca como selecionada — o front
        // abre o descarte com o motivo. Os outros três quadrantes são "esta vai
        // ser tratada" (SELECIONADO), e a fila passa a ordenar por eles.
        $descartar = !$encaminhada && $impacto === 'BAIXO' && $esforco === 'ALTO';
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
        if ($encaminhada) {
            // Só reposiciona: mexer na situação aqui desfaria o encaminhamento
            // em silêncio, e o destino sumiria da tag sem ninguém pedir
            Database::executar(
                "UPDATE coleta_item SET impacto = ?, esforco = ?, adiado = 0 WHERE id IN ({$marcas})",
                [$impacto, $esforco, ...$grupo]
            );
        } else {
            $situacao = $descartar ? 'NOVO' : 'SELECIONADO';
            Database::executar(
                "UPDATE coleta_item SET impacto = ?, esforco = ?, situacao = ?, adiado = 0
                 WHERE id IN ({$marcas})",
                [$impacto, $esforco, $situacao, ...$grupo]
            );
        }
        Json::ok(['impacto' => $impacto, 'esforco' => $esforco, 'descartar' => $descartar]);
    }

    /** Texto complementado durante a discussão, antes de escolher o destino. */
    public function complementar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        if (in_array($item['situacao'], ['DESCARTADO', 'DIVIDIDO'], true)) {
            Json::erro('Esta ideia já foi tratada.');
        }
        $texto = trim(is_string($d['texto_tratado'] ?? null) ? $d['texto_tratado'] : '');
        if ($texto === '') {
            Json::erro('O texto não pode ficar vazio.');
        }
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));

        // A ideia encaminhada continua editável — ela não sai mais de vista ao
        // ganhar destino. Corrigir a redação aqui tem de corrigir TAMBÉM o
        // registro que ela virou no diagnóstico, senão os dois divergem e a
        // SWOT fica com o texto velho para sempre.
        foreach (Database::todos(
            "SELECT DISTINCT destino_tipo, destino_id FROM coleta_item
             WHERE id IN ({$marcas}) AND destino_id IS NOT NULL",
            $grupo
        ) as $dst) {
            if ($dst['destino_tipo'] === 'CENARIO') {
                Database::executar(
                    'UPDATE cenario_item SET descricao = ? WHERE id = ? AND planejamento_id = ?',
                    [$texto, (int)$dst['destino_id'], $planId]
                );
            } elseif ($dst['destino_tipo'] === 'FATOR') {
                Database::executar(
                    'UPDATE fator SET descricao = ? WHERE id = ? AND planejamento_id = ?',
                    [$texto, (int)$dst['destino_id'], $planId]
                );
            }
        }

        Database::executar(
            "UPDATE coleta_item SET texto_tratado = ? WHERE id IN ({$marcas})",
            [$texto, ...$grupo]
        );
        Json::ok();
    }

    /**
     * Quebra um despejo em várias ideias. A original é marcada como tratada
     * (dividida) e cada parte nasce como ideia nova apontando para o pai, para
     * a rastreabilidade não se perder no meio do caminho.
     */
    public function dividir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        if (!in_array($item['situacao'], ['NOVO', 'SELECIONADO'], true)) {
            Json::erro('Só uma ideia ainda não tratada pode ser dividida.');
        }
        $partes = array_values(array_filter(array_map(
            fn($t) => is_string($t) ? mb_substr(trim($t), 0, 400) : '',
            is_array($d['partes'] ?? null) ? $d['partes'] : []
        ), fn($t) => $t !== ''));
        if (count($partes) < 2) {
            Json::erro('Escreva pelo menos duas partes para dividir.');
        }

        $criados = [];
        foreach ($partes as $texto) {
            $criados[] = (int)Database::executar(
                'INSERT INTO coleta_item (planejamento_id, rodada_id, ano, autor_id, autor_nome,
                   dividido_de_id, texto, destino_sugerido)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $planId, $item['rodada_id'] !== null ? (int)$item['rodada_id'] : null,
                    (int)$item['ano'], $item['autor_id'] !== null ? (int)$item['autor_id'] : null,
                    $item['autor_nome'], $id, $texto, $item['destino_sugerido'],
                ]
            );
        }
        // DIVIDIDO e não DESCARTADO: a ideia entrou, em pedaços — marcá-la
        // como descartada mostraria "não entrou" ao autor e inflaria o contador
        Database::executar(
            "UPDATE coleta_item SET situacao = 'DIVIDIDO', motivo = ?, triado_por = ?, triado_em = NOW()
             WHERE id = ?",
            ['Dividida em ' . count($partes) . ' ideias.', (int)$u['id'], $id]
        );
        Json::ok(['criados' => $criados]);
    }

    /**
     * Ids tratados junto: o líder do grupo e tudo que foi arrastado para ele.
     * Vem do banco, e não do cliente — assim nenhuma lista forjada alcança
     * ideias de outro planejamento.
     */
    private function grupo(int $id, int $planId): array
    {
        $lider = (int)($this->exigirItem($id, $planId)['agrupado_em_id'] ?? 0) ?: $id;
        // ACEITO entra: a encaminhada continua na matriz e ainda é tratada em
        // grupo (mover de quadrante, desmarcar o destino). Sem ela aqui, só o
        // líder mudava e as outras ficavam para trás, apontando para um
        // registro já apagado. DESCARTADO e DIVIDIDO ficam de fora: já saíram.
        $linhas = Database::todos(
            "SELECT id FROM coleta_item
             WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)
               AND situacao IN ('NOVO','SELECIONADO','ACEITO')",
            [$planId, $lider, $lider]
        );
        return array_map(fn($l) => (int)$l['id'], $linhas) ?: [$id];
    }

    /**
     * Junta duas ideias de mesmo sentido: a arrastada passa a apontar para a
     * que ficou (e leva junto quem já apontava para ela).
     */
    public function agrupar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $alvo = (int)($d['alvo'] ?? 0);
        if ($alvo === $id) {
            Json::erro('Arraste sobre outra ideia.');
        }
        $origem = $this->exigirItem($id, $planId);
        $itemAlvo = $this->exigirItem($alvo, $planId);
        // Tanto a arrastada quanto o alvo podem já estar num grupo: o líder é
        // sempre o topo. A ficha arrastada costuma ser o REPRESENTANTE da caixa
        // (o item mais antigo), que nem sempre é o líder; resolver o líder de
        // origem garante que o grupo INTEIRO migra, sem deixar ideias órfãs.
        $liderOrigem = (int)($origem['agrupado_em_id'] ?? 0) ?: $id;
        $lider = (int)($itemAlvo['agrupado_em_id'] ?? 0) ?: $alvo;
        if ($lider === $liderOrigem) {
            Json::erro('Essas ideias já estão no mesmo grupo.');
        }
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = ? WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)',
            [$lider, $planId, $liderOrigem, $liderOrigem]
        );
        Json::ok(['lider' => $lider]);
    }

    /**
     * Reabre uma ideia já encaminhada para reclassificar ("reabrir e mover"):
     * remove o registro que ela virou no diagnóstico (item de cenário ou fator)
     * e devolve a ideia à bancada como SELECIONADO, preservando texto e
     * prioridade. Ao escolher o novo destino, um registro novo é criado.
     */
    public function reabrir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        $tipo = $item['destino_tipo'] ?? null;
        $destinoId = (int)($item['destino_id'] ?? 0);
        if ($tipo === 'ACAO') {
            // Plano de ação ainda pendente é só uma marca: nada a apagar. Depois
            // de virar ação num projeto, quem manda é o projeto — desfazer aqui
            // deixaria a ação órfã, sem rastro de onde veio.
            if ($destinoId) {
                Json::erro('Esta ideia já virou uma ação num projeto: desfaça por lá antes.');
            }
            $rotulo = 'Plano de ação';
        } elseif (in_array($tipo, ['CENARIO', 'FATOR'], true) && $destinoId) {
            // Rótulo da classificação atual, capturado antes de apagar o registro,
            // para a tela de reclassificação mostrar de onde a ideia está saindo
            $rotulo = $tipo === 'CENARIO' ? 'Análise de Cenário' : (match (
                (string)(Database::um('SELECT etapa FROM fator WHERE id = ?', [$destinoId])['etapa'] ?? '')
            ) {
                'PESTEL' => 'PESTEL',
                'PORTER' => 'Porter',
                'SWOT'   => 'SWOT',
                default  => 'diagnóstico',
            });
            if ($tipo === 'CENARIO') {
                Database::executar('DELETE FROM cenario_item WHERE id = ? AND planejamento_id = ?', [$destinoId, $planId]);
            } else {
                // Fatores promovidos apontam para o de origem (sem cascade): saem antes.
                // O restante (GUT, vínculo com cascata) cai por ON DELETE CASCADE.
                Database::executar('DELETE FROM fator WHERE promovido_de_id = ?', [$destinoId]);
                Database::executar('DELETE FROM fator WHERE id = ? AND planejamento_id = ?', [$destinoId, $planId]);
            }
        } else {
            Json::erro('Esta ideia não está numa análise para reclassificar.');
        }
        // Vale para o GRUPO inteiro, como o encaminhar: encaminhar marca todas
        // as ideias juntadas, e limpar só o líder deixaria as outras presas
        // como "aceitas" apontando para um registro que não existe mais.
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
        Database::executar(
            "UPDATE coleta_item SET situacao = 'SELECIONADO', destino_tipo = NULL, destino_id = NULL,
               triado_por = NULL, triado_em = NULL
             WHERE id IN ({$marcas}) AND planejamento_id = ?",
            [...$grupo, $planId]
        );
        Json::ok(['id' => $id, 'ano' => (int)$item['ano'], 'rotulo' => $rotulo]);
    }

    /** Desfaz o agrupamento de uma ideia (ou do grupo inteiro, pelo líder). */
    public function desagrupar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        // Dissolvida a caixa, cada FILHA volta ao próprio texto — o texto
        // tratado que carregam é só a cópia do título da caixa-mãe. O título
        // fica com o líder, que era a caixa.
        $lider = (int)($item['agrupado_em_id'] ?? 0) ?: $id;
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = NULL,
               texto_tratado = IF(id = ?, texto_tratado, NULL)
             WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)',
            [$lider, $planId, $lider, $lider]
        );
        Json::ok();
    }

    /**
     * Tira UMA palavra do grupo, sem desfazer o resto — para quando uma ideia
     * foi juntada por engano. Devolve o líder do grupo que sobrou, para a tela
     * manter o foco nele.
     *
     * Se quem sai é o líder, os demais precisam de um novo topo, senão
     * ficariam órfãos apontando para um líder que virou item solto.
     */
    public function removerDoGrupo(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        $lider = (int)($item['agrupado_em_id'] ?? 0) ?: (int)$item['id'];
        if ((int)$item['id'] === $lider && (int)($item['agrupado_em_id'] ?? 0) === 0
            && !Database::um('SELECT id FROM coleta_item WHERE agrupado_em_id = ?', [$lider])) {
            Json::erro('Esta ideia não está em nenhum grupo.');
        }

        $restante = $lider;
        if ((int)$item['id'] === $lider) {
            // Sai o líder: promove o próximo membro e reaponta os demais para ele
            $novo = Database::um(
                'SELECT id FROM coleta_item
                 WHERE planejamento_id = ? AND agrupado_em_id = ? AND id <> ? ORDER BY id LIMIT 1',
                [$planId, $lider, $lider]
            );
            $restante = $novo ? (int)$novo['id'] : null;
            if ($restante !== null) {
                Database::executar(
                    'UPDATE coleta_item SET agrupado_em_id = ?
                     WHERE planejamento_id = ? AND agrupado_em_id = ? AND id <> ?',
                    [$restante, $planId, $lider, $restante]
                );
                Database::executar('UPDATE coleta_item SET agrupado_em_id = NULL WHERE id = ?', [$restante]);
            }
            Database::executar('UPDATE coleta_item SET agrupado_em_id = NULL WHERE id = ?', [$lider]);
        } else {
            // Membro comum: só ele sai do grupo. O texto tratado é o TÍTULO da
            // caixa-mãe (o complementar copia para o grupo inteiro): quem sai
            // volta ao próprio texto, senão a ficha solta nasceria com o nome
            // da mãe.
            Database::executar(
                'UPDATE coleta_item SET agrupado_em_id = NULL, texto_tratado = NULL
                 WHERE id = ? AND planejamento_id = ?',
                [$id, $planId]
            );
        }
        Json::ok(['lider' => $restante]);
    }

    /** Manda o grupo para a caixa "tratar depois" — ou o traz de volta. */
    public function adiar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $adiado = !empty($d['adiado']) ? 1 : 0;
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
        Database::executar(
            "UPDATE coleta_item SET adiado = ? WHERE id IN ({$marcas})",
            [$adiado, ...$grupo]
        );
        Json::ok(['adiado' => (bool)$adiado]);
    }

    /** Reserva a ideia para quem chegou primeiro (ver encaminhar). */
    /**
     * Reserva a ideia para o encaminhamento (reserva atômica: a condição vai no
     * WHERE, não numa transação).
     *
     * O que protege a ideia é ter um registro JÁ CRIADO no diagnóstico
     * (`destino_id`), não a situação: com `destino_id` nulo não existe fator
     * nem item de cenário para ficar órfão, e o `encaminhar()` regrava
     * `destino_tipo`/`destino_id` do grupo inteiro logo em seguida.
     *
     * Por isso `ACEITO` sem `destino_id` também é reservável. Sem isso a ideia
     * ficava num beco sem saída — recusada com "já foi tratada por outra
     * pessoa" em dois caminhos comuns: quando o fator/item de cenário dela foi
     * excluído no diagnóstico, e quando ela estava parada em "Plano de ação"
     * (que grava `destino_tipo='ACAO'` com `destino_id` nulo) e o condutor quis
     * mandá-la para uma análise. Quem tem `destino_id` continua saindo só pelo
     * "Desmarcar" (`reabrir()`), que apaga o registro antes.
     */
    private function reservar(int $id, int $planId, int $usuarioId): bool
    {
        return Database::afetadas(
            "UPDATE coleta_item
                SET situacao = 'ACEITO', destino_tipo = NULL, triado_por = ?, triado_em = NOW()
             WHERE id = ? AND planejamento_id = ? AND destino_id IS NULL
               AND situacao IN ('NOVO','SELECIONADO','ACEITO')",
            [$usuarioId, $id, $planId]
        ) === 1;
    }

    /** Seção do menu que mostra o registro criado, para o front navegar até ele. */
    private function secao(string $destino): string
    {
        return match ($destino) {
            'CENARIO' => 'cenario',
            'PESTEL'  => 'pestel',
            'PORTER'  => 'porter',
            'ACAO'    => 'projetos',
            default   => 'swot',
        };
    }

    private function exigirItem(int $id, int $planId): array
    {
        $item = Database::um(
            'SELECT * FROM coleta_item WHERE id = ? AND planejamento_id = ?',
            [$id, $planId]
        );
        if (!$item) {
            Json::erro('Ideia não encontrada neste planejamento.', 404);
        }
        return $item;
    }
}

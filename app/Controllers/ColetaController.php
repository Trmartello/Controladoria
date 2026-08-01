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
                    COALESCE(a.nome, ci.autor_nome, 'Participante') AS autor, t.nome AS triador
             FROM coleta_item ci
             LEFT JOIN usuario a ON a.id = ci.autor_id
             LEFT JOIN usuario t ON t.id = ci.triado_por
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
            $id = (int)Database::executar(
                'INSERT INTO coleta_item (planejamento_id, ano, autor_id, texto, destino_sugerido)
                 VALUES (?, ?, ?, ?, ?)',
                [$planId, $ano, (int)$u['id'], $texto, $destino]
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
        // Ideia vinda da tempestade não tem autor cadastrado: quem tria é quem
        // pode apagar, senão um despejo de participante ficaria para sempre
        $doParticipante = $item['autor_id'] === null;
        if (!$doParticipante && (int)$item['autor_id'] !== (int)$u['id']) {
            Json::erro('Só o autor pode excluir a própria ideia.', 403);
        }
        if ($doParticipante) {
            Auth::exigirTriagemColeta($planId);
        }
        if ($item['situacao'] !== 'NOVO') {
            Json::erro('Esta ideia já foi triada e não pode mais ser excluída.');
        }
        Database::executar('DELETE FROM coleta_item WHERE id = ?', [$id]);
        Json::ok();
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
        if (in_array($item['situacao'], ['ACEITO', 'DESCARTADO'], true)) {
            Json::erro('Esta ideia já foi tratada.');
        }

        $impacto = $d['impacto'] ?? null;
        $esforco = $d['esforco'] ?? null;
        if (!in_array($impacto, ['ALTO', 'BAIXO'], true) || !in_array($esforco, ['BAIXO', 'ALTO'], true)) {
            Json::erro('Escolha um quadrante da matriz.');
        }
        // Posicionar já é dizer "esta vai ser tratada"
        // Posicionar tira da caixa de "tratar depois" e vale para o grupo todo
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
        Database::executar(
            "UPDATE coleta_item SET impacto = ?, esforco = ?, situacao = 'SELECIONADO', adiado = 0
             WHERE id IN ({$marcas})",
            [$impacto, $esforco, ...$grupo]
        );
        Json::ok(['impacto' => $impacto, 'esforco' => $esforco]);
    }

    /** Texto complementado durante a discussão, antes de escolher o destino. */
    public function complementar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $item = $this->exigirItem($id, $planId);
        if (in_array($item['situacao'], ['ACEITO', 'DESCARTADO'], true)) {
            Json::erro('Esta ideia já foi tratada.');
        }
        $texto = trim(is_string($d['texto_tratado'] ?? null) ? $d['texto_tratado'] : '');
        if ($texto === '') {
            Json::erro('O texto não pode ficar vazio.');
        }
        $grupo = $this->grupo($id, $planId);
        $marcas = implode(',', array_fill(0, count($grupo), '?'));
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
        $linhas = Database::todos(
            "SELECT id FROM coleta_item
             WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)
               AND situacao IN ('NOVO','SELECIONADO')",
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
        $this->exigirItem($id, $planId);
        $itemAlvo = $this->exigirItem($alvo, $planId);
        // O alvo pode ele mesmo estar agrupado: o líder é sempre o topo
        $lider = (int)($itemAlvo['agrupado_em_id'] ?? 0) ?: $alvo;
        if ($lider === $id) {
            Json::erro('Essas ideias já estão no mesmo grupo.');
        }
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = ? WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)',
            [$lider, $planId, $id, $id]
        );
        Json::ok(['lider' => $lider]);
    }

    /** Desfaz o agrupamento de uma ideia (ou do grupo inteiro, pelo líder). */
    public function desagrupar(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        Auth::exigirTriagemColeta($planId);
        $this->exigirItem($id, $planId);
        Database::executar(
            'UPDATE coleta_item SET agrupado_em_id = NULL
             WHERE planejamento_id = ? AND (id = ? OR agrupado_em_id = ?)',
            [$planId, $id, $id]
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
            // Membro comum: só ele sai do grupo
            Database::executar(
                'UPDATE coleta_item SET agrupado_em_id = NULL WHERE id = ? AND planejamento_id = ?',
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
    private function reservar(int $id, int $planId, int $usuarioId): bool
    {
        return Database::afetadas(
            "UPDATE coleta_item SET situacao = 'ACEITO', triado_por = ?, triado_em = NOW()
             WHERE id = ? AND planejamento_id = ? AND situacao IN ('NOVO','SELECIONADO')
               AND destino_id IS NULL",
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

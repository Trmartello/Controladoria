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
            "SELECT ci.*, a.nome AS autor, t.nome AS triador
             FROM coleta_item ci
             JOIN usuario a ON a.id = ci.autor_id
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

        $texto = trim($d['texto'] ?? '');
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
        if ((int)$item['autor_id'] !== (int)$u['id']) {
            Json::erro('Só o autor pode excluir a própria ideia.', 403);
        }
        if ($item['situacao'] !== 'NOVO') {
            Json::erro('Esta ideia já foi triada e não pode mais ser excluída.');
        }
        Database::executar('DELETE FROM coleta_item WHERE id = ?', [$id]);
        Json::ok();
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

        $texto = trim($d['texto_tratado'] ?? '') ?: $item['texto'];
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

        if (!$this->reservar($id, $planId, (int)$u['id'])) {
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
        Database::executar(
            'UPDATE coleta_item SET texto_tratado = ?, destino_tipo = ?, destino_id = ? WHERE id = ?',
            [$texto, $destinoTipo, $destinoId, $id]
        );
        Json::ok(['destino_tipo' => $destinoTipo, 'destino_id' => $destinoId, 'secao' => $this->secao($destino)]);
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

        $motivo = trim($d['motivo'] ?? '');
        if ($motivo === '') {
            Json::erro('Explique por que a ideia não entra — o autor vê este motivo.');
        }
        $linhas = Database::afetadas(
            "UPDATE coleta_item SET situacao = 'DESCARTADO', motivo = ?, triado_por = ?, triado_em = NOW()
             WHERE id = ? AND planejamento_id = ? AND situacao = 'NOVO'",
            [$motivo, (int)$u['id'], $id, $planId]
        );
        if (!$linhas) {
            Json::erro('Esta ideia já foi tratada por outra pessoa.');
        }
        Json::ok();
    }

    /** Reserva a ideia para quem chegou primeiro (ver encaminhar). */
    private function reservar(int $id, int $planId, int $usuarioId): bool
    {
        return Database::afetadas(
            "UPDATE coleta_item SET situacao = 'ACEITO', triado_por = ?, triado_em = NOW()
             WHERE id = ? AND planejamento_id = ? AND situacao = 'NOVO' AND destino_id IS NULL",
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

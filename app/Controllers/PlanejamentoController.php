<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Core\Versao;
use App\Services\Bloqueio;

class PlanejamentoController
{
    /**
     * O pulso do ciclo: `{planejamento_id: versao}`.
     *
     * É o que permite a duas telas abertas ao mesmo tempo se acompanharem. Roda
     * a cada poucos segundos POR ADMIN conectado, e é por isso que ela não faz
     * mais nada: lê uma tabela de duas colunas por chave primária e devolve
     * inteiros. Nenhuma consulta de conteúdo, nenhuma escrita — nem para criar
     * a linha do plano que ainda não foi escrito, que a tela lê como zero.
     *
     * A versão sozinha não diz O QUE mudou, e isso é de propósito: quem sabe
     * ler o próprio conteúdo é a seção, e ela já sabe fazer isso. O pulso
     * responde só "vale a pena reler?".
     */
    public function pulso(): void
    {
        $u = Auth::exigirLogin();
        $cicloId = (int)($_GET['ciclo_id'] ?? 0);
        if (!$cicloId) {
            Json::erro('Informe o ciclo.');
        }
        Json::ok([
            // `(object)` porque array associativo VAZIO vira `[]` em JSON, não
            // `{}`: um ciclo em que ninguém escreveu devolveria uma lista, e a
            // tela faria `mapa[id]` num array. Funciona por acidente (dá
            // `undefined`, lido como zero) e quebra ao iterar as chaves.
            'versoes' => (object)Versao::doCiclo($cicloId, Auth::escopoNegocios($u)),
            // Os cadeados viajam JUNTO com as versões porque respondem à mesma
            // pergunta — "o que está acontecendo agora?" — no mesmo relógio de
            // 4s. Uma rota própria dobraria o tráfego da consulta mais chamada
            // do sistema para responder metade do que a tela precisa saber.
            'bloqueios' => Bloqueio::doCiclo($cicloId, (int)$u['id']),
        ]);
    }

    /**
     * Resolve o contexto (ciclo + negócio ou corporativo): retorna o
     * planejamento — criando-o se ainda não existir — e o checklist do método.
     */
    public function contexto(): void
    {
        $u = Auth::exigirLogin();
        $cicloId   = (int)($_GET['ciclo_id'] ?? 0);
        $corporativo = ($_GET['escopo'] ?? '') === 'CORPORATIVO';
        $negocioId = $corporativo ? null : (int)($_GET['negocio_id'] ?? 0);

        if (!$cicloId || (!$corporativo && !$negocioId)) {
            Json::erro('Informe o ciclo e o negócio (ou escopo corporativo).');
        }
        if ($corporativo && !Auth::veTudo($u)) {
            Json::erro('Sem acesso ao planejamento corporativo.', 403);
        }
        if (!$corporativo) {
            $escopo = Auth::escopoNegocios($u);
            if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
                Json::erro('Sem acesso a este negócio.', 403);
            }
        }

        $plan = Database::um(
            $corporativo
                ? "SELECT * FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'"
                : "SELECT * FROM planejamento WHERE ciclo_id = ? AND negocio_id = ?",
            $corporativo ? [$cicloId] : [$cicloId, $negocioId]
        );
        if (!$plan) {
            try {
                Database::executar(
                    $corporativo
                        ? "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'CORPORATIVO', NULL)"
                        : "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'NEGOCIO', ?)",
                    $corporativo ? [$cicloId] : [$cicloId, $negocioId]
                );
            } catch (\PDOException $e) {
                // Corrida na chave única: outra requisição criou primeiro — segue
            }
            $plan = Database::um(
                $corporativo
                    ? "SELECT * FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'"
                    : "SELECT * FROM planejamento WHERE ciclo_id = ? AND negocio_id = ?",
                $corporativo ? [$cicloId] : [$cicloId, $negocioId]
            );
        }

        Json::ok([
            'planejamento' => $plan,
            'checklist'    => $this->checklist((int)$plan['id'], $cicloId),
        ]);
    }

    /** Situação de cada etapa do método para o hub. */
    private function checklist(int $planId, int $cicloId): array
    {
        $conta = fn(string $sql, array $p) => (int)(Database::um($sql, $p)['n'] ?? 0);

        // Da coleta interessa o que ainda não foi triado — sem denominador: o
        // checklist não filtra por ano e somaria as rodadas de todos os anos
        $coleta  = $conta(
            "SELECT COUNT(*) n FROM coleta_item
             WHERE planejamento_id = ? AND situacao IN ('NOVO', 'SELECIONADO')",
            [$planId]
        );
        $cenario = $conta('SELECT COUNT(*) n FROM cenario_item WHERE planejamento_id = ?', [$planId]);
        $pestel  = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'PESTEL'", [$planId]);
        $porter  = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'PORTER'", [$planId]);
        $swot    = $conta("SELECT COUNT(*) n FROM fator WHERE planejamento_id = ? AND etapa = 'SWOT'", [$planId]);
        $gut     = $conta(
            "SELECT COUNT(*) n FROM gut g JOIN fator f ON f.id = g.fator_id WHERE f.planejamento_id = ?",
            [$planId]
        );

        $horizontes = $conta('SELECT COUNT(*) n FROM horizonte WHERE ciclo_id = ?', [$cicloId]);
        $drivers    = $conta('SELECT COUNT(*) n FROM driver WHERE ativo = 1', []);
        $eixos      = $conta('SELECT COUNT(*) n FROM eixo WHERE ativo = 1', []);
        $celulasTotal = $horizontes * $drivers * ($eixos + 1); // sínteses + aberturas
        $cascata    = $conta('SELECT COUNT(*) n FROM cascata_escolha WHERE planejamento_id = ?', [$planId]);

        $projetos      = $conta('SELECT COUNT(*) n FROM projeto WHERE planejamento_id = ?', [$planId]);
        $investimentos = $conta('SELECT COUNT(*) n FROM investimento WHERE planejamento_id = ?', [$planId]);
        $indicadores   = $conta('SELECT COUNT(*) n FROM indicador WHERE planejamento_id = ?', [$planId]);

        // 'secao' liga cada cartão do hub à seção correspondente do menu
        return [
            ['etapa' => 'Coleta de Ideias',    'secao' => 'coleta',        'itens' => $coleta,        'meta' => null],
            ['etapa' => 'Análise de Cenário',  'secao' => 'cenario',       'itens' => $cenario,       'meta' => null],
            ['etapa' => 'PESTEL',              'secao' => 'pestel',        'itens' => $pestel,        'meta' => null],
            ['etapa' => 'Porter (5 Forças)',   'secao' => 'porter',        'itens' => $porter,        'meta' => null],
            ['etapa' => 'SWOT',                'secao' => 'swot',          'itens' => $swot,          'meta' => null],
            ['etapa' => 'Matriz GUT',          'secao' => 'gut',           'itens' => $gut,           'meta' => $swot ?: null],
            ['etapa' => 'Cascata de Escolhas', 'secao' => 'cascata',       'itens' => $cascata,       'meta' => $celulasTotal ?: null],
            ['etapa' => 'Metas e Indicadores', 'secao' => 'metas',         'itens' => $indicadores,   'meta' => null],
            ['etapa' => 'Projetos',            'secao' => 'projetos',      'itens' => $projetos,      'meta' => null],
            ['etapa' => 'Investimentos',       'secao' => 'investimentos', 'itens' => $investimentos, 'meta' => null],
        ];
    }
}

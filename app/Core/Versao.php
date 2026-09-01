<?php

namespace App\Core;

/**
 * O pulso do planejamento: um contador que sobe a cada escrita, para telas
 * abertas ao mesmo tempo se acompanharem sem ninguém apertar F5.
 *
 * ## Por que a marcação mora aqui, e não em cada controller
 *
 * A alternativa óbvia — chamar `bumpar()` no fim de cada endpoint que grava —
 * tem um modo de falha ruim: esquecer UM endpoint não quebra nada visivelmente.
 * O sistema segue funcionando, e só aquela ação some do outro monitor. Ninguém
 * relata "a exclusão de investimento não propaga"; a pessoa aperta F5 e segue.
 * Defeito que se contorna sozinho é defeito que fica.
 *
 * Então a marcação é montada com duas metades, cada uma num lugar por onde
 * TUDO passa, e nenhuma delas depende de lembrança:
 *
 * 1. **Quem é o alvo** — `Auth::exigirEdicaoPlanejamento()` chama `alvo()`. Ele
 *    é o portão de escrita do conteúdo do plano: as 52 chamadas dele nos
 *    controllers são, por definição, os pontos em que alguém vai gravar.
 * 2. **Houve escrita?** — `Database::executar()` chama `marcarEscrita()`. É o
 *    único caminho de INSERT/UPDATE/DELETE do sistema.
 *
 * O contador só sobe quando as DUAS aconteceram, e sobe **uma vez por
 * requisição**, no encerramento (`registrar()`). Um endpoint que grava dez
 * linhas conta como uma mudança — que é o que a outra tela precisa saber.
 *
 * ## As exceções, que são explícitas
 *
 * Escrita que não passa por `exigirEdicaoPlanejamento` precisa chamar `alvo()`
 * na mão. Hoje é uma só: `ImpactoController`, que autoriza pelo NEGÓCIO da
 * célula (ver `PLANEJAMENTO-SISTEMA.md §5`) e não pelo planejamento. Os
 * cadastros (ciclo, negócio, usuário, driver, eixo) ficam de fora de propósito:
 * eles mudam a moldura, não o conteúdo de um plano, e a tela que os consome já
 * é recarregada ao trocar de contexto.
 */
class Versao
{
    /** Planejamento que esta requisição está mexendo; null até alguém dizer. */
    private static ?int $alvo = null;

    /** Alguma escrita passou por `Database::executar` nesta requisição. */
    private static bool $escreveu = false;

    /** Já gravamos? O encerramento pode ser chamado mais de uma vez. */
    private static bool $gravado = false;

    /** Quem esta requisição está mexendo. Chamado pelo portão de escrita. */
    public static function alvo(int $planejamentoId): void
    {
        if ($planejamentoId > 0) {
            self::$alvo = $planejamentoId;
        }
    }

    /** Passou uma escrita. Chamado por `Database::executar`. */
    public static function marcarEscrita(): void
    {
        self::$escreveu = true;
    }

    /**
     * Fecha a conta da requisição. Roda no encerramento do PHP, e por isso pega
     * também o caminho de `Json::erro()` — que pode ter gravado antes de
     * recusar (um `promover` que insere e depois esbarra numa regra).
     *
     * Falha em silêncio de propósito: se o UPDATE do contador der errado, o
     * pior que acontece é a outra tela demorar mais um gesto para atualizar.
     * Deixar a exceção subir aqui trocaria isso por um erro 500 numa operação
     * que JÁ DEU CERTO, o que é uma troca claramente ruim.
     */
    public static function registrar(): void
    {
        if (self::$gravado || !self::$escreveu || self::$alvo === null) {
            return;
        }
        self::$gravado = true;
        try {
            Database::executar(
                'INSERT INTO planejamento_versao (planejamento_id, versao) VALUES (?, 1)
                 ON DUPLICATE KEY UPDATE versao = versao + 1',
                [self::$alvo]
            );
        } catch (\Throwable $e) {
            // ver o bloco acima
        }
    }

    /**
     * O pulso dos planejamentos de um ciclo: `[planejamento_id => versao]`.
     *
     * Devolve o mapa do CICLO inteiro, e não de um plano só, porque uma tela
     * pode depender de mais de um: a Matriz de Impacto é lida no contexto de um
     * negócio e o conteúdo dela vive no plano CORPORATIVO. Pedir os dois numa
     * consulta evita duas idas ao servidor a cada batida do relógio.
     *
     * Plano sem linha aqui não aparece no mapa: nunca foi escrito, e a tela
     * trata a ausência como versão zero. Criar a linha na leitura seria gravar
     * num GET — e um GET que grava é o tipo de coisa que transforma um relógio
     * de consulta em carga de escrita.
     */
    public static function doCiclo(int $cicloId, ?array $escopoNegocios): array
    {
        $sql = "SELECT v.planejamento_id, v.versao
                FROM planejamento_versao v
                JOIN planejamento p ON p.id = v.planejamento_id
                WHERE p.ciclo_id = ?";
        $params = [$cicloId];
        // Quem não vê tudo recebe só os planos dos negócios dele. O corporativo
        // entra para todos: a Matriz de Impacto o consulta, e o que vaza aqui é
        // um inteiro que não diz nada sobre o conteúdo.
        if ($escopoNegocios !== null) {
            $marcas = $escopoNegocios
                ? implode(',', array_fill(0, count($escopoNegocios), '?'))
                : 'NULL';
            $sql .= " AND (p.escopo = 'CORPORATIVO' OR p.negocio_id IN ({$marcas}))";
            $params = array_merge($params, $escopoNegocios);
        }
        $mapa = [];
        foreach (Database::todos($sql, $params) as $l) {
            $mapa[(string)$l['planejamento_id']] = (int)$l['versao'];
        }
        return $mapa;
    }
}

<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Json;

/**
 * Regras do fator que valem para MAIS DE UMA tela.
 *
 * Existe pelo mesmo motivo de `Quiz`: a guarda abaixo nasceu dentro de
 * `FatorController::excluir` e ficou só lá, enquanto outros três caminhos
 * apagam o mesmo fator. Escrita de novo em cada um, ela divergiria na primeira
 * revisão — e, pior, foi a AUSÊNCIA dela nos outros três que deixou passar o
 * defeito que este arquivo corrige.
 */
class Fatores
{
    /**
     * As categorias de cada análise: o catálogo que decide o que é fator
     * válido.
     *
     * Mora aqui, e não no controller, porque **duas telas passaram a criar
     * fator**: a das análises e o `⇄` do item de cenário, que cria um ao
     * atravessar de tabela. Duas cópias divergiriam na primeira categoria nova,
     * e a divergência produziria o defeito mais caro deste módulo — fator com
     * categoria que a tela de destino não sabe desenhar, invisível nas duas
     * análises e segurando vozes que ninguém consegue desamarrar.
     */
    public const CATEGORIAS = [
        'PESTEL' => ['POLITICO', 'ECONOMICO', 'SOCIAL', 'TECNOLOGICO', 'ECOLOGICO', 'LEGAL'],
        'PORTER' => ['RIVALIDADE', 'NOVOS_ENTRANTES', 'SUBSTITUTOS', 'PODER_FORNECEDORES', 'PODER_CLIENTES'],
        'SWOT'   => ['FORCA', 'FRAQUEZA', 'OPORTUNIDADE', 'AMEACA'],
    ];

    /**
     * Recusa apagar fator que já virou ação no plano.
     *
     * Apagar deixaria a ação viva no plano sem origem nenhuma: ninguém saberia
     * de onde ela veio nem por que existe, e o caminho de volta (o selo da
     * SWOT no cartão) apontaria para uma linha morta.
     *
     * Confere o fator **e os promovidos a partir dele**, porque quem apaga um
     * fator do PESTEL/Porter leva junto o que foi promovido para a SWOT — e é
     * o promovido que carrega o `desdobramento_id`. Olhar só o id pedido
     * deixava passar exatamente o caso que mais acontece: fator do PESTEL
     * promovido à SWOT, encaminhado ao plano, virado ação.
     *
     * @param int[]  $fatorIds  ids cujos vínculos (e os dos promovidos) serão conferidos
     * @param string $mensagem  o que dizer a quem tentou — cada tela fala do seu gesto
     */
    public static function exigirSemAcao(array $fatorIds, string $mensagem): void
    {
        $presos = self::acoesQuePrendem($fatorIds);
        if ($presos) {
            Json::erro($mensagem . ' (ação: “' . reset($presos) . '”)');
        }
    }

    /**
     * Apaga fatores — e os promovidos a partir deles — tratando ANTES as vozes
     * da sala que apontam para o que vai cair.
     *
     * O que cai junto e ninguém via: a FK dos cruzamentos da SWOT é `ON DELETE
     * CASCADE`, então apagar uma Força leva os cruzamentos dela — mas as vozes
     * do quiz amarradas a esses cruzamentos (`destino_tipo = 'CRUZAMENTO'`,
     * polimórfico, sem FK) ficavam `ACEITO` apontando para um id morto: o
     * painel as mostrava "usadas", o autor não conseguia editá-las e nenhuma
     * tela as soltava. Os três caminhos que apagam fator (a tela da análise, a
     * exclusão da ideia da Coleta e o "Desmarcar" da triagem) passam por aqui.
     *
     * `$soltar` distingue os dois destinos das vozes do FATOR: excluir de vez
     * (`excluirVozes`) ou devolvê-las ao painel (`soltarVozes`, o "Desmarcar").
     * As vozes de CRUZAMENTO saem de vez nos dois casos — o cruzamento morre.
     *
     * @param int[] $fatorIds  os fatores pedidos (os promovidos são achados aqui)
     */
    public static function apagar(array $fatorIds, int $planId, bool $soltar = false): void
    {
        $ids = array_values(array_unique(array_map('intval', $fatorIds)));
        if (!$ids) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $todos = array_map('intval', array_column(Database::todos(
            "SELECT id FROM fator WHERE id IN ({$marcas}) OR promovido_de_id IN ({$marcas})",
            array_merge($ids, $ids)
        ), 'id'));
        if ($todos) {
            $marcasTodos = implode(',', array_fill(0, count($todos), '?'));
            $cruzamentos = array_map('intval', array_column(Database::todos(
                "SELECT id FROM swot_cruzamento
                  WHERE fator_interno_id IN ({$marcasTodos}) OR fator_externo_id IN ({$marcasTodos})",
                array_merge($todos, $todos)
            ), 'id'));
            Quiz::excluirVozes('CRUZAMENTO', $cruzamentos);
            $soltar ? Quiz::soltarVozes('FATOR', $todos) : Quiz::excluirVozes('FATOR', $todos);
        }
        // Promovidos apontam para o de origem sem ON DELETE: saem antes. GUT,
        // vínculo com a cascata e os cruzamentos caem por CASCADE.
        Database::executar("DELETE FROM fator WHERE promovido_de_id IN ({$marcas})", $ids);
        Database::executar(
            "DELETE FROM fator WHERE id IN ({$marcas}) AND planejamento_id = ?",
            array_merge($ids, [$planId])
        );
    }

    /**
     * Quais dos fatores pedidos estão presos por uma ação, e por qual delas.
     *
     * É a MESMA pergunta de `exigirSemAcao`, e por isso a consulta é uma só. A
     * recusa (no servidor) e a marcação do botão × (na tela) têm de concordar
     * sempre: se a tela decidisse por uma regra própria, ela erraria justamente
     * nos casos difíceis — o promovido e o cruzamento — e passaria a mentir nos
     * dois sentidos, com × morto onde dava para apagar e × vivo onde o servidor
     * recusa.
     *
     * As três origens da trava, todas com FK `ON DELETE CASCADE` a partir do
     * fator: o próprio fator virou ação; um **promovido** dele virou (ainda o
     * caso mais comum, mesmo depois de PESTEL e Porter passarem a ir direto ao
     * plano); ou um **cruzamento** que o cita virou. Apagar deixaria a ação
     * viva no plano sem origem nenhuma.
     *
     * Uma consulta para a lista inteira: a tela chama isto com todos os fatores
     * de uma etapa, e uma consulta por cartão faria dezenas por pintura.
     *
     * @param  int[] $fatorIds
     * @return array<int,string> `[id do fator pedido => o_que da ação]`
     */
    public static function acoesQuePrendem(array $fatorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fatorIds))));
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        // Cada ramo devolve as DUAS pontas possíveis — o próprio fator e aquele
        // de que ele foi promovido —, e quem decide qual foi pedida é o laço
        // abaixo. Resolver isso em SQL custaria um CASE por ramo dizendo a mesma
        // coisa três vezes.
        // Os dois últimos ramos cobrem o CRUZAMENTO, um por lado do par: as FKs
        // dele para os dois fatores são ON DELETE CASCADE, então apagar um fator
        // leva o cruzamento junto — e com ele a origem da ação.
        $linhas = Database::todos(
            "SELECT f.id AS proprio, f.promovido_de_id AS origem, d.o_que
             FROM fator f
             JOIN desdobramento d ON d.id = f.desdobramento_id
             WHERE f.id IN ({$marcas}) OR f.promovido_de_id IN ({$marcas})
             UNION ALL
             SELECT fi.id, fi.promovido_de_id, d.o_que
             FROM swot_cruzamento c
             JOIN desdobramento d ON d.id = c.desdobramento_id
             JOIN fator fi ON fi.id = c.fator_interno_id
             WHERE fi.id IN ({$marcas}) OR fi.promovido_de_id IN ({$marcas})
             UNION ALL
             SELECT fe.id, fe.promovido_de_id, d.o_que
             FROM swot_cruzamento c
             JOIN desdobramento d ON d.id = c.desdobramento_id
             JOIN fator fe ON fe.id = c.fator_externo_id
             WHERE fe.id IN ({$marcas}) OR fe.promovido_de_id IN ({$marcas})",
            array_merge($ids, $ids, $ids, $ids, $ids, $ids)
        );

        $pedidos = array_flip($ids);
        $presos = [];
        foreach ($linhas as $l) {
            foreach ([(int)$l['proprio'], (int)$l['origem']] as $candidato) {
                if ($candidato && isset($pedidos[$candidato]) && !isset($presos[$candidato])) {
                    $presos[$candidato] = (string)$l['o_que'];
                }
            }
        }
        return $presos;
    }
}

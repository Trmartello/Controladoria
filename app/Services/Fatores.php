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

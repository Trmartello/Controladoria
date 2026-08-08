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
        $ids = array_values(array_filter(array_map('intval', $fatorIds)));
        if (!$ids) {
            return;
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $preso = Database::um(
            "SELECT d.o_que
             FROM fator f
             JOIN desdobramento d ON d.id = f.desdobramento_id
             WHERE f.id IN ({$marcas}) OR f.promovido_de_id IN ({$marcas})
             LIMIT 1",
            array_merge($ids, $ids)
        );
        if ($preso) {
            Json::erro($mensagem . ' (ação: “' . $preso['o_que'] . '”)');
        }
    }
}

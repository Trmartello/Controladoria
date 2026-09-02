<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Json;

/**
 * A regra do PAR do cruzamento (TOWS), num lugar só.
 *
 * Ela nasceu dentro de `CruzamentoController` e ficaria bem lá se houvesse um
 * caminho só. Passou a haver dois quando a sala do encontro ganhou o alvo
 * `CRUZAMENTO`: a partir daí **a rota pública também monta um par** — e a rota
 * pública é a única escrita sem login do sistema.
 *
 * É por isso que esta classe existe, e não por gosto de camada: uma segunda
 * escrita da regra do lado de fora da autenticação divergiria da de dentro na
 * primeira mudança, e a versão frouxa seria justamente a exposta. Aqui a
 * conferência é uma, e quem chama não escolhe qual regra vale.
 *
 * **O bloco é consequência do par, nunca escolha de quem envia.** Força +
 * oportunidade só pode ser "atacar"; fraqueza + ameaça só pode ser "proteger".
 * Aceitar o bloco do corpo permitiria gravar a linha no quadro errado — o mesmo
 * defeito que a etapa e o ano do fator já custaram.
 */
class Cruzamentos
{
    /** O bloco que nasce de cada par [categoria interna][categoria externa]. */
    public const TIPOS = [
        'FORCA'    => ['OPORTUNIDADE' => 'ATACAR',   'AMEACA' => 'DEFENDER'],
        'FRAQUEZA' => ['OPORTUNIDADE' => 'REFORCAR', 'AMEACA' => 'PROTEGER'],
    ];

    /**
     * O caminho inverso: de que quadrantes cada bloco se alimenta, com o verbo
     * do material. É o que a pergunta da sala precisa para montar as duas
     * listas — e para conferir que a resposta veio do bloco perguntado.
     */
    public const BLOCOS = [
        'ATACAR'   => ['interno' => 'FORCA',    'externo' => 'OPORTUNIDADE',
                       'rotulo' => 'Forças × Oportunidades', 'verbo' => 'atacar',
                       'pergunta' => 'força nossa podemos usar para aproveitar '
                                   . 'qual oportunidade — e como?'],
        'DEFENDER' => ['interno' => 'FORCA',    'externo' => 'AMEACA',
                       'rotulo' => 'Forças × Ameaças',       'verbo' => 'defender',
                       'pergunta' => 'força nossa nos defende de qual ameaça — e como?'],
        'REFORCAR' => ['interno' => 'FRAQUEZA', 'externo' => 'OPORTUNIDADE',
                       'rotulo' => 'Fraquezas × Oportunidades', 'verbo' => 'reforçar',
                       'pergunta' => 'fraqueza nossa precisa ser reforçada para não '
                                   . 'perdermos qual oportunidade — e como?'],
        'PROTEGER' => ['interno' => 'FRAQUEZA', 'externo' => 'AMEACA',
                       'rotulo' => 'Fraquezas × Ameaças',    'verbo' => 'proteger',
                       'pergunta' => 'fraqueza nossa nos expõe a qual ameaça — e como '
                                   . 'nos proteger?'],
    ];

    /**
     * Confere o par e devolve `['interno', 'externo', 'tipo', 'ano']`.
     *
     * Recusa com `Json::erro` — não devolve null nem lança: os dois chamadores
     * respondem JSON e param ali mesmo, e um valor de retorno "inválido" que
     * alguém esquecesse de conferir seria a brecha que esta classe existe para
     * fechar.
     *
     * **Uma consulta para os dois fatores, não duas.** Duas abririam a janela em
     * que o primeiro é lido, o segundo é apagado por outra sessão, e o par
     * gravado cita uma linha que não existe mais. O `IN` traz os dois de uma vez
     * e a contagem diz se ambos passaram pelo filtro.
     *
     * @param int|null $anoEsperado quando informado, o par tem de ser deste ano
     *                              (a pergunta da sala é de um ano só)
     */
    public static function parValidado(
        int $internoId, int $externoId, int $planId, ?int $anoEsperado = null
    ): array {
        if ($internoId <= 0 || $externoId <= 0) {
            Json::erro('Escolha um fator interno e um externo da SWOT.');
        }
        if ($internoId === $externoId) {
            Json::erro('O cruzamento liga DOIS fatores diferentes.');
        }
        $linhas = Database::todos(
            "SELECT id, ano, categoria, descricao FROM fator
             WHERE id IN (?, ?) AND planejamento_id = ? AND etapa = 'SWOT'",
            [$internoId, $externoId, $planId]
        );
        $porId = [];
        foreach ($linhas as $l) {
            $porId[(int)$l['id']] = $l;
        }
        if (count($porId) !== 2) {
            Json::erro('Escolha dois fatores da SWOT deste planejamento.');
        }
        $interno = $porId[$internoId];
        $externo = $porId[$externoId];

        $tipo = self::TIPOS[$interno['categoria']][$externo['categoria']] ?? null;
        if ($tipo === null) {
            // Cobre os três erros de uma vez: dois internos, dois externos, ou o
            // par invertido. A mensagem diz o que fazer, não o que o servidor viu.
            Json::erro('O cruzamento liga um fator INTERNO (força ou fraqueza) a um '
                . 'fator EXTERNO (oportunidade ou ameaça). Reveja o par escolhido.');
        }
        // O ano sai dos FATORES, não do corpo: cruzamento é leitura da SWOT de
        // um ano, e um par de anos diferentes não é leitura de ano nenhum.
        if ((int)$interno['ano'] !== (int)$externo['ano']) {
            Json::erro('Os dois fatores precisam ser do mesmo ano da análise.');
        }
        $ano = (int)$interno['ano'];
        if ($ano <= 0) {
            Json::erro('Os fatores escolhidos não têm ano de análise definido.');
        }
        if ($anoEsperado !== null && $ano !== $anoEsperado) {
            Json::erro('Os fatores escolhidos não são do ano desta pergunta.');
        }
        return ['interno' => $interno, 'externo' => $externo, 'tipo' => $tipo, 'ano' => $ano];
    }

    /**
     * Os fatores da SWOT de um quadrante, para montar uma das listas da sala.
     *
     * Devolve o mínimo que a tela do celular precisa — id e descrição —, e é de
     * propósito que não devolva mais: esta lista desce para uma tela SEM LOGIN,
     * e cada campo a mais é uma decisão de exposição que ninguém tomou. O score
     * da GUT, por exemplo, é priorização interna e não tem por que viajar.
     */
    public static function doQuadrante(int $planId, int $ano, string $categoria): array
    {
        return Database::todos(
            "SELECT id, descricao FROM fator
             WHERE planejamento_id = ? AND etapa = 'SWOT' AND ano = ? AND categoria = ?
             ORDER BY id",
            [$planId, $ano, $categoria]
        );
    }
}

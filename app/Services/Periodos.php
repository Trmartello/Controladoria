<?php

namespace App\Services;

use App\Core\Database;

/**
 * Os períodos de uma ação de recorrência `DATAS` (pedido do cliente,
 * 2026-11-09).
 *
 * O caso que a originou, nas palavras dele: "a ação de treinamento seria
 * realizada duas vezes ao ano, uma em abril de 01/04/2026 a 30/04/2026 e outro
 * treinamento em 01/08/2026 a 30/08/2026, e se houvessem mais datas pudessem
 * ser lançadas. Essas ações não se repetem mensalmente, e sim só nas datas
 * lançadas."
 *
 * Três decisões que não devem ser desfeitas:
 *
 * 1. **A ação continua tendo UM par `data_inicio`/`data_fim`**, espelhando o
 *    período CORRENTE. É o mesmo desenho da recorrência semanal e mensal, e
 *    pela mesma razão, que está escrita em `Recorrencia`: o atraso automático
 *    (`Consolidacao::sincronizarAtrasos`), as cobranças por e-mail (`Avisos`) e
 *    o prazo consolidado do projeto leem as COLUNAS da ação, não a regra. Uma
 *    ação cujo vencimento só existisse nesta tabela sairia dos três de uma vez,
 *    sem nada na tela dizendo isso.
 *
 * 2. **O corrente é o primeiro em ABERTO, não o mais próximo de hoje**
 *    (decisão do cliente na mesma conversa). Com abril vencido e não concluído,
 *    a ação continua cobrando abril — atrasada — até alguém concluí-lo; só
 *    então passa para agosto. A recorrência semanal faz o contrário: ela PULA
 *    as ocorrências perdidas (`max($base, $hoje)` em `Recorrencia::reagendar`),
 *    porque oito reuniões semanais perdidas não viram oito cobranças. Aqui as
 *    datas foram escolhidas uma a uma, e deixar uma para trás é apagar um
 *    compromisso que alguém marcou.
 *
 * 3. **`concluido_em` é por período.** Sem ele, concluir abril e reabrir em
 *    agosto apagaria o registro de que abril foi feito — a ação voltaria a
 *    "Não iniciada" e nada na tela distinguiria "abril feito, agosto pendente"
 *    de "nada feito".
 */
class Periodos
{
    /** O valor de `desdobramento.recorrencia` que diz "o vencimento está nesta tabela". */
    public const TIPO = 'DATAS';

    /** @return array<int,array> os períodos da ação, do que vence antes ao que vence depois */
    public static function daAcao(int $acaoId): array
    {
        return Database::todos(
            'SELECT id, data_inicio, data_fim, concluido_em
               FROM desdobramento_periodo
              WHERE desdobramento_id = ?
              ORDER BY data_fim, id',
            [$acaoId]
        );
    }

    /**
     * O período que manda agora: o primeiro ainda em aberto.
     *
     * Sem nenhum em aberto devolve null — a ação cumpriu todos os seus
     * períodos e conclui de vez.
     *
     * @param array $periodos já ordenados por `data_fim` (o que `daAcao` devolve)
     */
    public static function corrente(array $periodos): ?array
    {
        foreach ($periodos as $p) {
            if (($p['concluido_em'] ?? null) === null) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Valida e normaliza a lista que veio do formulário.
     *
     * Devolve `[periodos, erro]`: o erro é a mensagem a mostrar, e nunca se
     * grava nada com ele presente. A validação é a mesma do período digitado da
     * ação comum — fim obrigatório, início não pode ser depois do fim —, mais
     * a recusa da lista vazia: uma ação em "datas específicas" sem data nenhuma
     * não tem vencimento, e sairia do atraso e das cobranças em silêncio.
     *
     * Datas repetidas são recusadas, e não removidas caladas: duas linhas
     * iguais na tela são erro de quem digitou, e apagar uma delas por conta
     * própria esconde o engano em vez de resolvê-lo.
     *
     * @return array{0: array<int,array{data_inicio:?string,data_fim:string}>, 1: ?string}
     */
    public static function validar($bruto): array
    {
        if (!is_array($bruto) || !$bruto) {
            return [[], 'Informe ao menos uma data para a ação.'];
        }
        $limpos = [];
        $vistos = [];
        foreach ($bruto as $linha) {
            if (!is_array($linha)) {
                return [[], 'Período inválido na lista de datas.'];
            }
            $fim = trim((string)($linha['data_fim'] ?? ''));
            $inicio = trim((string)($linha['data_inicio'] ?? ''));
            if ($fim === '') {
                return [[], 'Toda data da lista precisa de um fim previsto.'];
            }
            if (!self::dataValida($fim) || ($inicio !== '' && !self::dataValida($inicio))) {
                return [[], 'Data inválida na lista: use o formato do calendário.'];
            }
            if ($inicio !== '' && $inicio > $fim) {
                return [[], "Na data que termina em {$fim}, o início é posterior ao fim."];
            }
            $chave = "{$inicio}|{$fim}";
            if (isset($vistos[$chave])) {
                return [[], 'Há duas datas iguais na lista — remova a repetida.'];
            }
            $vistos[$chave] = true;
            $limpos[] = ['data_inicio' => $inicio !== '' ? $inicio : null, 'data_fim' => $fim];
        }
        // Ordena por fim: a tela pode receber as linhas em qualquer ordem, e é o
        // fim que decide qual período é o corrente.
        usort($limpos, fn($a, $b) => [$a['data_fim'], $a['data_inicio'] ?? '']
            <=> [$b['data_fim'], $b['data_inicio'] ?? '']);
        return [$limpos, null];
    }

    private static function dataValida(string $v): bool
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    }

    /**
     * Substitui a lista de períodos de uma ação, PRESERVANDO o que já foi
     * concluído.
     *
     * Preservar importa: salvar uma correção no "Como?" não pode desfazer o
     * treinamento de abril que já aconteceu. Um período que continua na lista
     * mantém o `concluido_em` que tinha; um que saiu da lista some de vez, que
     * é o que quem o apagou pediu.
     *
     * @param array $periodos saída de `validar`
     */
    public static function gravar(int $acaoId, array $periodos): void
    {
        $antes = [];
        foreach (self::daAcao($acaoId) as $p) {
            $antes[self::chave($p)] = $p['concluido_em'];
        }
        Database::executar('DELETE FROM desdobramento_periodo WHERE desdobramento_id = ?', [$acaoId]);
        foreach ($periodos as $p) {
            Database::executar(
                'INSERT INTO desdobramento_periodo (desdobramento_id, data_inicio, data_fim, concluido_em)
                 VALUES (?, ?, ?, ?)',
                [$acaoId, $p['data_inicio'], $p['data_fim'], $antes[self::chave($p)] ?? null]
            );
        }
    }

    private static function chave(array $p): string
    {
        return ((string)($p['data_inicio'] ?? '')) . '|' . ((string)$p['data_fim']);
    }

    /**
     * Qual período MANDARÁ depois de a lista ser gravada.
     *
     * O `salvar` precisa do par `data_inicio`/`data_fim` da ação ANTES de ter o
     * id (na criação não há id para gravar período nenhum), então esta função
     * aplica em memória a mesma regra de preservação do `gravar`: período que
     * continua na lista mantém o `concluido_em` que tinha. Usar uma segunda
     * regra aqui faria a ação apontar para um período e a tabela guardar outro.
     *
     * @param array $periodos saída de `validar`
     * @return array{data_inicio:?string,data_fim:string}|null
     */
    public static function correnteDaLista(int $acaoId, array $periodos): ?array
    {
        $concluidos = [];
        if ($acaoId) {
            foreach (self::daAcao($acaoId) as $p) {
                if (($p['concluido_em'] ?? null) !== null) {
                    $concluidos[self::chave($p)] = true;
                }
            }
        }
        foreach ($periodos as $p) {
            if (!isset($concluidos[self::chave($p)])) {
                return ['data_inicio' => $p['data_inicio'], 'data_fim' => $p['data_fim']];
            }
        }
        return null;
    }

    /**
     * Conclui o período corrente e devolve a janela do seguinte.
     *
     * Devolve null quando não sobrou nenhum — e aí a ação conclui de vez, que é
     * exatamente o que `Recorrencia::reagendar` faz ao fim de uma rotina. Os
     * dois caminhos de conclusão (o formulário e a barra de progresso) chamam
     * esta função pela mesma razão que já chamam aquela: um UPDATE cru de
     * status pularia a regra, e a ação "concluiria" sem nunca reabrir.
     *
     * @return array{data_inicio:?string,data_fim:string}|null
     */
    public static function concluirCorrente(int $acaoId): ?array
    {
        $periodos = self::daAcao($acaoId);
        $atual = self::corrente($periodos);
        if ($atual === null) {
            return null;
        }
        // A reserva é atômica pelo mesmo motivo da conclusão da ação: dois
        // pedidos simultâneos passariam ambos pela leitura acima, e o segundo
        // concluiria o período seguinte sem ninguém ter feito nada.
        if (Database::afetadas(
            'UPDATE desdobramento_periodo SET concluido_em = NOW()
              WHERE id = ? AND concluido_em IS NULL',
            [(int)$atual['id']]
        ) === 0) {
            return null;
        }
        foreach ($periodos as $p) {
            if ((int)$p['id'] !== (int)$atual['id'] && ($p['concluido_em'] ?? null) === null) {
                return ['data_inicio' => $p['data_inicio'], 'data_fim' => $p['data_fim']];
            }
        }
        return null;
    }
}

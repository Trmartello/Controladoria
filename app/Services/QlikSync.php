<?php

namespace App\Services;

use App\Core\Database;

/**
 * Sincronização do cadastro de negócios com o app Comercial Global (Qlik Cloud).
 *
 * A fonte é o campo `FlagFilialNegocio` do app, no formato "cód - NOME" — o
 * código é o oficial do ERP. A sincronização casa primeiro pelo código e
 * depois pelo nome (corrigindo códigos provisórios de cargas antigas). Quando
 * QLIK_API_KEY estiver configurada, valida a conectividade com o app antes de
 * importar (a extração de valores via Engine API entra em fase futura).
 */
class QlikSync
{
    /** Valores de `FlagFilialNegocio` no Comercial Global (verificado em 31/07/2026). */
    private const NEGOCIOS_FONTE = [
        '1'  => 'NEGOCIO CEREAIS',
        '2'  => 'NEGOCIO PECUARIA',
        '4'  => 'NEGOCIO LEITE',
        '6'  => 'NEGOCIO FABRICA DE RACOES',
        '7'  => 'NEGOCIO UTM',
        '8'  => 'NEGOCIO LOJAS AGROPECUARIAS',
        '9'  => 'NEGOCIO SUPERMERCADOS',
        '11' => 'NEGOCIO POSTO COMBUSTIVEIS',
        '12' => 'POSTO RESFRIAMENTO DE LEITE',
        '13' => 'UBS UNID.BENEF.SEMENTES',
    ];

    public static function sincronizarNegocios(): array
    {
        $qlik = $GLOBALS['config']['qlik'];
        $conectividade = null;
        if (!empty($qlik['api_key'])) {
            $conectividade = self::verificarApp($qlik) ? 'ok' : 'falha';
        }

        // Negócios de cargas QLIK antigas que saíram da fonte (ex.: NEGOCIO
        // REFLORESTAMENTO) são desativados e liberam o código que ocupavam —
        // os planejamentos vinculados a eles permanecem intactos. Cadastros
        // manuais nunca são desativados.
        $marcadores = implode(',', array_fill(0, count(self::NEGOCIOS_FONTE), '?'));
        $foraDaFonte = Database::todos(
            "SELECT id FROM negocio WHERE origem = 'QLIK' AND ativo = 1 AND nome NOT IN ($marcadores)",
            array_values(self::NEGOCIOS_FONTE)
        );
        foreach ($foraDaFonte as $f) {
            Database::executar(
                'UPDATE negocio SET ativo = 0, cod_negocio = ? WHERE id = ?',
                ['X' . $f['id'], (int)$f['id']]
            );
        }
        $desativados = count($foraDaFonte);

        // 1ª passada: linhas casadas pelo nome com código divergente do oficial
        // recebem um código temporário, liberando os oficiais para a 2ª passada
        // (cargas antigas usavam códigos provisórios sequenciais que colidem).
        foreach (self::NEGOCIOS_FONTE as $cod => $nome) {
            $linha = Database::um('SELECT id, cod_negocio FROM negocio WHERE nome = ?', [$nome]);
            if ($linha && $linha['cod_negocio'] !== (string)$cod) {
                Database::executar(
                    'UPDATE negocio SET cod_negocio = ? WHERE id = ?',
                    ['T' . $linha['id'], $linha['id']]
                );
            }
        }

        $inseridos = 0;
        $atualizados = 0;
        $conflitos = 0;
        foreach (self::NEGOCIOS_FONTE as $cod => $nome) {
            $cod = (string)$cod;
            $linha = Database::um('SELECT id FROM negocio WHERE nome = ?', [$nome]);
            $ocupante = Database::um(
                'SELECT id FROM negocio WHERE cod_negocio = ? AND id <> ?',
                [$cod, $linha ? (int)$linha['id'] : 0]
            );

            if ($linha) {
                // Cód. oficial só entra se não colidir com um cadastro manual
                $novoCod = $ocupante ? self::proximoCodigoLivre((int)$cod) : $cod;
                if ($ocupante) {
                    $conflitos++;
                }
                Database::executar(
                    "UPDATE negocio SET cod_negocio = ?, origem = 'QLIK', sincronizado_em = NOW() WHERE id = ?",
                    [$novoCod, (int)$linha['id']]
                );
                $atualizados++;
                continue;
            }

            $novoCod = $ocupante ? self::proximoCodigoLivre((int)$cod) : $cod;
            if ($ocupante) {
                $conflitos++;
            }
            Database::executar(
                "INSERT INTO negocio (cod_negocio, nome, origem, sincronizado_em)
                 VALUES (?, ?, 'QLIK', NOW())",
                [$novoCod, $nome]
            );
            $inseridos++;
        }

        return [
            'inseridos'     => $inseridos,
            'atualizados'   => $atualizados,
            'conflitos'     => $conflitos,
            'desativados'   => $desativados,
            'conectividade' => $conectividade,
        ];
    }

    private static function proximoCodigoLivre(int $sugestao): string
    {
        $cod = $sugestao;
        while (Database::um('SELECT id FROM negocio WHERE cod_negocio = ?', [(string)$cod])) {
            $cod++;
        }
        return (string)$cod;
    }

    /** Confere acesso ao app Comercial Global via REST (metadados). */
    private static function verificarApp(array $qlik): bool
    {
        $url = "https://{$qlik['tenant']}/api/v1/apps/{$qlik['app_id']}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$qlik['api_key']}"],
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status === 200;
    }
}

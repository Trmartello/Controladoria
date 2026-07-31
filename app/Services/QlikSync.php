<?php

namespace App\Services;

use App\Core\Database;

/**
 * Sincronização do cadastro de negócios com o app Comercial Global (Qlik Cloud).
 *
 * O app expõe hoje o campo `Negócio` (nomes, sem código). Enquanto o código do
 * ERP não entra na carga do Qlik, a lista abaixo reflete os valores do campo e
 * os códigos são atribuídos/ajustados no cadastro. Quando QLIK_API_KEY estiver
 * configurada, a sincronização valida a conectividade com o app antes de
 * importar (a extração de valores via Engine API entra em fase futura).
 */
class QlikSync
{
    /** Valores do campo `Negócio` no Comercial Global (verificado em 31/07/2026). */
    private const NEGOCIOS_FONTE = [
        'NEGOCIO CEREAIS',
        'NEGOCIO FABRICA DE RACOES',
        'NEGOCIO LEITE',
        'NEGOCIO LOJAS AGROPECUARIAS',
        'NEGOCIO PECUARIA',
        'NEGOCIO POSTO COMBUSTIVEIS',
        'NEGOCIO REFLORESTAMENTO',
        'NEGOCIO SUPERMERCADOS',
        'NEGOCIO UTM',
        'POSTO RESFRIAMENTO DE LEITE',
        'UBS UNID.BENEF.SEMENTES',
        'USINA FOTOVOLTAICA',
    ];

    public static function sincronizarNegocios(): array
    {
        $qlik = $GLOBALS['config']['qlik'];
        $conectividade = null;
        if (!empty($qlik['api_key'])) {
            $conectividade = self::verificarApp($qlik) ? 'ok' : 'falha';
        }

        $inseridos = 0;
        $existentes = 0;
        foreach (self::NEGOCIOS_FONTE as $indice => $nome) {
            $atual = Database::um('SELECT id FROM negocio WHERE nome = ?', [$nome]);
            if ($atual) {
                Database::executar(
                    "UPDATE negocio SET origem = 'QLIK', sincronizado_em = NOW() WHERE id = ?",
                    [$atual['id']]
                );
                $existentes++;
                continue;
            }
            // Código provisório sequencial — ajustável no cadastro até o ERP expor o cód. oficial
            $cod = self::proximoCodigoLivre($indice + 1);
            Database::executar(
                "INSERT INTO negocio (cod_negocio, nome, origem, sincronizado_em)
                 VALUES (?, ?, 'QLIK', NOW())",
                [$cod, $nome]
            );
            $inseridos++;
        }

        return [
            'inseridos'     => $inseridos,
            'atualizados'   => $existentes,
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

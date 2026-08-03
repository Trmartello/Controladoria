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
    /**
     * Valores de `FlagFilialNegocio` no Comercial Global (conferido com a lista
     * do cliente em 03/08/2026). Esta é a fonte da verdade dos códigos: o
     * `seeds.sql` (instalação nova) e o passo do `migrate.php` (instalação que
     * já existe) aplicam exatamente esta lista.
     *
     * A revisão de 03/08/2026 trouxe os códigos 3 e 17, que faltavam, e trocou
     * os rótulos longos da carga anterior pelos oficiais, mais curtos
     * ("NEGOCIO FABRICA DE RACOES" → "F. DE RACOES"). Os códigos 10, 14, 15 e
     * 16 não existem na fonte — o intervalo é descontínuo de propósito.
     *
     * O código **5 (JUROS S. COTA CAPITAL) existe no ERP mas não entra aqui**:
     * é linha de resultado financeiro, não unidade de negócio que planeja. Ficou
     * de fora a pedido do cliente, depois de entrar e ser desativado no
     * cadastro. Enquanto estiver fora desta lista, o negócio pode ser excluído
     * pela tela; devolvê-lo é acrescentar a linha de volta — a sincronização o
     * recria com o código oficial.
     */
    private const NEGOCIOS_FONTE = [
        '1'  => 'CEREAIS',
        '2'  => 'PECUARIA',
        '3'  => 'FRUTICULTURA',
        '4'  => 'LEITE',
        '6'  => 'F. DE RACOES',
        '7'  => 'UTM',
        '8'  => 'AGROPECUARIAS',
        '9'  => 'SUPERMERCADOS',
        '11' => 'P. COMBUSTIVEIS',
        '12' => 'P. RESF. LEITE',
        '13' => 'UBS',
        '17' => 'USINA FOTOVOLTAICA',
    ];

    /** O código está na lista oficial? (a sincronização recria quem está.) */
    public static function estaNaFonte(string $cod): bool
    {
        return array_key_exists($cod, self::NEGOCIOS_FONTE);
    }

    public static function sincronizarNegocios(): array
    {
        $qlik = $GLOBALS['config']['qlik'];
        $conectividade = null;
        if (!empty($qlik['api_key'])) {
            $conectividade = self::verificarApp($qlik) ? 'ok' : 'falha';
        }

        $resolvidas = self::resolverLinhas();

        // Negócios de cargas QLIK antigas que saíram da fonte (ex.: NEGOCIO
        // REFLORESTAMENTO) são desativados e liberam o código que ocupavam —
        // os planejamentos vinculados a eles permanecem intactos. Cadastros
        // manuais nunca são desativados.
        // O critério é a LINHA que nenhum código da fonte reconheceu, não o
        // nome: com o critério antigo, renomear na fonte (a revisão de
        // 03/08/2026 renomeou os dez) desativava a linha em uso e criava outra
        // do zero — o negócio sumia do seletor e o planejamento dele ficava
        // pendurado numa linha inativa.
        // array_values: `$resolvidas` é indexado pelo código, e array_map
        // preserva a chave — a lista chegaria ao PDO com chaves de texto e o
        // execute() posicional morreria com "Invalid parameter number".
        $ids = array_values(array_map(static fn ($l) => (int)$l['id'], $resolvidas));
        $marcadores = $ids ? implode(',', array_fill(0, count($ids), '?')) : '';
        $foraDaFonte = Database::todos(
            "SELECT id FROM negocio WHERE origem = 'QLIK' AND ativo = 1"
            . ($ids ? " AND id NOT IN ($marcadores)" : ''),
            $ids
        );
        foreach ($foraDaFonte as $f) {
            Database::executar(
                'UPDATE negocio SET ativo = 0, cod_negocio = ? WHERE id = ?',
                ['X' . $f['id'], (int)$f['id']]
            );
        }
        $desativados = count($foraDaFonte);

        // 1ª passada: linha reconhecida cujo código difere do oficial recebe um
        // código temporário, liberando os oficiais para a 2ª passada (cargas
        // antigas usavam códigos provisórios sequenciais que colidem).
        foreach ($resolvidas as $cod => $linha) {
            if ((string)$linha['cod_negocio'] !== (string)$cod) {
                Database::executar(
                    'UPDATE negocio SET cod_negocio = ? WHERE id = ?',
                    ['T' . $linha['id'], (int)$linha['id']]
                );
            }
        }

        $inseridos = 0;
        $atualizados = 0;
        $conflitos = 0;
        foreach (self::NEGOCIOS_FONTE as $cod => $nome) {
            $cod = (string)$cod;
            $linha = $resolvidas[$cod] ?? null;
            // Nome oficial ocupado por cadastro MANUAL: linha manual nunca é
            // sobrescrita, e duplicar o nome dela confundiria o seletor. Com
            // linha própria reconhecida, o código oficial ainda é aplicado e só
            // o nome fica como está; sem linha, não há o que inserir.
            $manualComONome = Database::um(
                "SELECT id FROM negocio WHERE nome = ? AND origem = 'MANUAL'",
                [$nome]
            );
            if ($manualComONome) {
                $conflitos++;
                if (!$linha) {
                    continue;
                }
            }
            $ocupante = Database::um(
                'SELECT id FROM negocio WHERE cod_negocio = ? AND id <> ?',
                [$cod, $linha ? (int)$linha['id'] : 0]
            );
            // Cód. oficial só entra se não colidir com um cadastro manual
            $novoCod = $ocupante ? self::proximoCodigoLivre((int)$cod) : $cod;
            if ($ocupante) {
                $conflitos++;
            }

            if ($linha) {
                // `ativo = 1` porque estar na fonte É estar ativo: linha
                // desativada por uma carga anterior e listada de novo precisa
                // voltar ao seletor, senão fica reconhecida e invisível.
                Database::executar(
                    "UPDATE negocio SET cod_negocio = ?, nome = ?, origem = 'QLIK',
                            ativo = 1, sincronizado_em = NOW() WHERE id = ?",
                    [$novoCod, $manualComONome ? $linha['nome'] : $nome, (int)$linha['id']]
                );
                $atualizados++;
                continue;
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

    /**
     * Qual linha do banco é cada código da fonte, na ordem: pelo CÓDIGO e, se
     * ele não achar ninguém, pelo NOME.
     *
     * O código é a identidade — é o oficial do ERP e não muda; o nome muda (a
     * fonte renomeou os dez negócios em 03/08/2026). Casar por nome primeiro
     * fazia de toda renomeação uma troca de linha, com o efeito descrito no
     * `sincronizarNegocios()`. O nome segue valendo como segunda chance, para
     * a linha de carga antiga que ficou com código provisório (`T<id>`).
     *
     * Só linhas de origem QLIK entram: sem esse filtro, um negócio cadastrado
     * À MÃO com um dos nomes oficiais era adotado pela carga — virava origem
     * QLIK, tinha o código reescrito e passava a ser desativável por ela,
     * contra a regra de que linha manual nunca é sobrescrita.
     *
     * @return array<string, array{id: int, cod_negocio: string, nome: string}>
     */
    private static function resolverLinhas(): array
    {
        $resolvidas = [];
        $usadas = [];
        foreach (self::NEGOCIOS_FONTE as $cod => $nome) {
            $linha = Database::um(
                "SELECT id, cod_negocio, nome FROM negocio WHERE cod_negocio = ? AND origem = 'QLIK'",
                [(string)$cod]
            ) ?: Database::um(
                "SELECT id, cod_negocio, nome FROM negocio WHERE nome = ? AND origem = 'QLIK'",
                [$nome]
            );
            // Uma linha responde por um código só: sem esta trava, um nome que
            // casasse com a linha de outro código faria os dois escreverem na
            // mesma linha e um negócio inteiro sumiria do cadastro.
            if (!$linha || isset($usadas[(int)$linha['id']])) {
                continue;
            }
            $usadas[(int)$linha['id']] = true;
            $resolvidas[(string)$cod] = $linha;
        }
        return $resolvidas;
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

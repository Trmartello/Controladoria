<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Configurações GERAIS do sistema (pedido do cliente, 2026-11-26: "nas
 * configurações crie um flag que ao acioná-lo o sistema oculta os botões de
 * edição, redirecionamento, exclusão, plano de ação e adicionar anexos […] na
 * Situação Atual e Tendência, SWOT"; "somente os usuários com o perfil ADM
 * podem acionar este flag").
 *
 * Todo usuário logado LÊ (as configurações descem também na sessão, em
 * `/api/me`); só o ADMIN grava. As chaves aceitas são as de `CHAVES`, com o
 * valor padrão — uma chave desconhecida é recusada, para um erro de digitação
 * não virar configuração fantasma.
 */
final class ConfiguracaoController
{
    /** chave => valor padrão (texto) */
    public const CHAVES = [
        'diagnostico_somente_leitura' => '0',
    ];

    /** As configurações em vigor, com os padrões por baixo. */
    public static function todas(): array
    {
        $saida = [];
        foreach (self::CHAVES as $k => $padrao) {
            $saida[$k] = $padrao;
        }
        try {
            foreach (Database::todos('SELECT chave, valor FROM configuracao') as $l) {
                if (array_key_exists($l['chave'], self::CHAVES)) {
                    $saida[$l['chave']] = (string)$l['valor'];
                }
            }
        } catch (\Throwable $e) {
            // Banco sem a tabela (antes do migrate): valem os padrões.
        }
        $saida['diagnostico_somente_leitura'] = $saida['diagnostico_somente_leitura'] === '1';
        return $saida;
    }

    public function listar(): void
    {
        Auth::exigirLogin();
        Json::ok(self::todas());
    }

    public function gravar(): void
    {
        $u = Auth::exigirLogin();
        if (($u['perfil'] ?? '') !== 'ADMIN') {
            Json::erro('Só administradores alteram as configurações.', 403);
        }
        $corpo = Json::corpo();
        $chave = (string)($corpo['chave'] ?? '');
        if (!array_key_exists($chave, self::CHAVES)) {
            Json::erro('Configuração desconhecida: ' . $chave . '.', 422);
        }
        $valor = !empty($corpo['valor']) && $corpo['valor'] !== '0' ? '1' : '0';
        Database::executar(
            'INSERT INTO configuracao (chave, valor, atualizado_por, atualizado_em) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), atualizado_por = VALUES(atualizado_por), atualizado_em = NOW()',
            [$chave, $valor, (int)$u['id']]
        );
        Json::ok(self::todas());
    }
}

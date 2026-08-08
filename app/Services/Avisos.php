<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Email;

/**
 * Avisos por e-mail do plano de ação:
 *  - SEMANAL (segunda-feira): panorama da semana de cada responsável;
 *  - DIARIO: só as pendências do dia (vence hoje ou já atrasada).
 *
 * Cada disparo é registrado em envio_email com chave única (tipo, data,
 * usuário), então rodar duas vezes no mesmo dia não manda e-mail repetido.
 *
 * O `$forcar` desliga essa trava, e existe só para o botão do Relatório de
 * Status — um clique deliberado de um ADMIN, que quer o e-mail na caixa agora
 * (para conferir o conteúdo, ou porque a pessoa apagou o de mais cedo). O
 * agendamento NUNCA força: ele roda sozinho, e sem a trava um cron a cada cinco
 * minutos viraria doze e-mails por hora para gente de verdade.
 */
class Avisos
{
    private const STATUS_ABERTOS = "('NAO_INICIADO','EM_ANDAMENTO','ATRASADO','PAUSADO','AGUARDANDO_VALIDACAO')";

    /** Dispara o que estiver pendente para hoje. Devolve o resumo do que rodou. */
    public static function despachar(string $tipo = 'auto', ?string $hoje = null, bool $forcar = false): array
    {
        $hoje = $hoje ?: date('Y-m-d');
        $resultado = [];
        $ehSegunda = (int)date('N', strtotime($hoje)) === 1;

        if ($tipo === 'semanal' || ($tipo === 'auto' && $ehSegunda)) {
            $resultado['semanal'] = self::rodar('SEMANAL', $hoje, $forcar);
        }
        if ($tipo === 'diario' || $tipo === 'auto') {
            $resultado['diario'] = self::rodar('DIARIO', $hoje, $forcar);
        }
        return $resultado;
    }

    private static function rodar(string $tipo, string $hoje, bool $forcar = false): array
    {
        $referencia = $tipo === 'SEMANAL' ? date('Y-m-d', strtotime('monday this week', strtotime($hoje))) : $hoje;
        $resumo = [
            'enviados' => 0, 'sem_itens' => 0, 'falhas' => 0,
            'ja_enviados' => 0, 'reenviados' => 0, 'detalhes' => [],
        ];

        foreach (self::responsaveis() as $u) {
            // Só conta como enviado o que saiu sem erro: uma falha de SMTP
            // não pode bloquear o aviso do dia (ou da semana) para sempre
            $ja = Database::um(
                'SELECT id FROM envio_email
                 WHERE tipo = ? AND referencia = ? AND usuario_id = ? AND erro IS NULL',
                [$tipo, $referencia, (int)$u['id']]
            );
            if ($ja && !$forcar) {
                $resumo['ja_enviados']++;
                continue;
            }

            $acoes = $tipo === 'SEMANAL'
                ? self::acoesDaSemana((int)$u['id'], $hoje)
                : self::pendenciasDoDia((int)$u['id'], $hoje);
            if (!$acoes) {
                $resumo['sem_itens']++;
                continue;
            }

            $erro = null;
            try {
                Email::enviar(
                    $u['email'],
                    $tipo === 'SEMANAL'
                        ? 'Planejamento — sua semana (' . date('d/m', strtotime($referencia)) . ')'
                        : 'Planejamento — pendências de hoje (' . date('d/m', strtotime($hoje)) . ')',
                    self::corpo($tipo, $u['nome'], $acoes, $hoje)
                );
                $resumo['enviados']++;
                if ($ja) {
                    $resumo['reenviados']++;
                }
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
                $resumo['falhas']++;
            }
            $resumo['detalhes'][] = ['usuario' => $u['nome'], 'itens' => count($acoes), 'erro' => $erro];

            Database::executar(
                // `enviado_em` também é atualizado: com o reenvio manual, a linha
                // passa a valer pelo ÚLTIMO disparo. Mantê-la no primeiro deixava
                // o registro dizendo que o aviso saiu de manhã quando a caixa da
                // pessoa mostra o das cinco da tarde.
                'INSERT INTO envio_email (tipo, referencia, usuario_id, destinatario, itens, erro)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE itens = VALUES(itens), erro = VALUES(erro),
                                         enviado_em = CURRENT_TIMESTAMP',
                [$tipo, $referencia, (int)$u['id'], $u['email'], count($acoes), $erro]
            );
        }
        return $resumo;
    }

    /** Usuários ativos que respondem por alguma ação em aberto. */
    private static function responsaveis(): array
    {
        return Database::todos(
            "SELECT DISTINCT u.id, u.nome, u.email
             FROM usuario u
             JOIN desdobramento d ON d.quem_usuario_id = u.id
             WHERE u.ativo = 1 AND u.email <> '' AND d.status IN " . self::STATUS_ABERTOS . '
             ORDER BY u.nome'
        );
    }

    /** Semana corrente (segunda a domingo) mais o que ficou atrasado antes. */
    private static function acoesDaSemana(int $usuarioId, string $hoje): array
    {
        $domingo = date('Y-m-d', strtotime('sunday this week', strtotime($hoje)));
        return Database::todos(self::CONSULTA, [$usuarioId, $domingo]);
    }

    /** Só o que vence hoje ou já venceu. */
    private static function pendenciasDoDia(int $usuarioId, string $hoje): array
    {
        return Database::todos(self::CONSULTA, [$usuarioId, $hoje]);
    }

    private const CAMPOS = "d.id, d.o_que, d.data_fim, d.status, d.prioridade, d.progresso,
                            p.titulo AS projeto, i.titulo AS iniciativa";

    /**
     * Ações de um responsável com prazo até a data informada.
     *
     * O `AND` do escopo repete a regra de Auth::exigirAcessoPlanejamento: quem
     * não enxerga o planejamento também não pode receber por e-mail o título
     * do projeto, da iniciativa e da ação. Sem ele, um nome digitado à mão no
     * campo "Quem?" vazaria o plano de um negócio para alguém de outro.
     */
    private const CONSULTA = 'SELECT ' . self::CAMPOS . '
         FROM desdobramento d
         JOIN projeto p ON p.id = d.projeto_id
         JOIN planejamento pl ON pl.id = p.planejamento_id
         JOIN usuario u ON u.id = d.quem_usuario_id
         LEFT JOIN iniciativa i ON i.id = d.iniciativa_id
         WHERE d.quem_usuario_id = ? AND d.status IN ' . self::STATUS_ABERTOS . "
           AND d.data_fim IS NOT NULL AND d.data_fim <= ?
           AND (u.perfil IN ('ADMIN', 'CONTROLADORIA', 'DIRECAO')
                OR (pl.escopo <> 'CORPORATIVO' AND EXISTS (
                      SELECT 1 FROM usuario_negocio un
                      WHERE un.usuario_id = u.id AND un.negocio_id = pl.negocio_id)))
         ORDER BY d.data_fim, d.prioridade = 'ALTA' DESC";

    private static function corpo(string $tipo, string $nome, array $acoes, string $hoje): string
    {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $cfg = $GLOBALS['config'] ?? (require dirname(__DIR__, 2) . '/config/config.php');
        $url = $cfg['app_url'] ?? '';

        $atrasadas = array_filter($acoes, fn($a) => $a['data_fim'] < $hoje);
        $hojeItens = array_filter($acoes, fn($a) => $a['data_fim'] === $hoje);
        $proximas  = array_filter($acoes, fn($a) => $a['data_fim'] > $hoje);

        $linhas = function (array $itens, string $titulo, string $cor) use ($esc) {
            if (!$itens) {
                return '';
            }
            $html = '<h3 style="font:600 15px sans-serif;color:' . $cor . ';margin:18px 0 6px">'
                . $esc($titulo) . ' (' . count($itens) . ')</h3>'
                . '<table cellpadding="6" cellspacing="0" border="0" width="100%" '
                . 'style="border-collapse:collapse;font:14px sans-serif">';
            foreach ($itens as $a) {
                $prazo = date('d/m/Y', strtotime($a['data_fim']));
                $html .= '<tr style="border-top:1px solid #e4e9e6">'
                    . '<td style="padding:8px 6px">'
                    . '<strong>' . $esc($a['o_que']) . '</strong><br>'
                    . '<span style="color:#6a6a6a;font-size:12px">'
                    . $esc($a['projeto']) . ($a['iniciativa'] ? ' · ' . $esc($a['iniciativa']) : '')
                    . '</span></td>'
                    . '<td align="right" style="padding:8px 6px;white-space:nowrap;color:' . $cor . '">'
                    . $prazo . '<br><span style="color:#6a6a6a;font-size:12px">'
                    . (int)$a['progresso'] . '%</span></td></tr>';
            }
            return $html . '</table>';
        };

        $intro = $tipo === 'SEMANAL'
            ? 'Este é o resumo das suas ações para esta semana no planejamento estratégico.'
            : 'Estas são as suas pendências de hoje no planejamento estratégico.';

        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f4f6f5;padding:16px">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden">'
            . '<div style="background:#06432a;color:#fff;padding:14px 18px;font:700 16px sans-serif">'
            . 'Planejamento Estratégico Copérdia</div>'
            . '<div style="padding:18px">'
            . '<p style="font:14px sans-serif;margin:0 0 4px">Olá, ' . $esc($nome) . '.</p>'
            . '<p style="font:14px sans-serif;color:#6a6a6a;margin:0">' . $esc($intro) . '</p>'
            . $linhas($atrasadas, 'Atrasadas', '#b3261e')
            . $linhas($hojeItens, 'Vencem hoje', '#b08d4f')
            . ($tipo === 'SEMANAL' ? $linhas($proximas, 'Ainda nesta semana', '#007a45') : '')
            . ($url
                ? '<p style="margin:22px 0 0"><a href="' . $esc($url) . '" '
                  . 'style="background:#007a45;color:#fff;font:600 14px sans-serif;'
                  . 'padding:10px 18px;border-radius:8px;text-decoration:none">Abrir o planejamento</a></p>'
                : '')
            . '<p style="font:12px sans-serif;color:#9aa6a0;margin:22px 0 0">'
            . 'Mensagem automática — não é preciso responder.</p>'
            . '</div></div></body></html>';
    }
}

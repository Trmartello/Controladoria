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
        $relatorio = self::relatorioAdmin($resultado, $hoje, $forcar);
        if ($relatorio) {
            $resultado['resumo'] = $relatorio;
        }
        return $resultado;
    }

    /**
     * O relatório do disparo, para quem administra.
     *
     * Sai DEPOIS dos avisos e resume duas coisas que ninguém mais vê juntas: o
     * que acabou de ser enviado (e o que falhou) e como está a carteira de cada
     * responsável — total, abertas, atrasadas e concluídas, com percentual.
     * Quem administra não é responsável por ação nenhuma na maioria dos casos,
     * então sem ele o único sinal do dia era "N enviado(s)" num alerta de tela
     * que some ao clicar em OK.
     *
     * Só sai quando ALGUMA coisa foi disparada: um relatório diário dizendo
     * "nada aconteceu" ensina a ignorar o remetente, e aí o dia em que algo
     * falha passa despercebido junto.
     *
     * @return array|null o mesmo formato das outras rodadas, ou null se não houve disparo
     */
    private static function relatorioAdmin(array $resultado, string $hoje, bool $forcar): ?array
    {
        $houve = 0;
        foreach ($resultado as $r) {
            $houve += $r['enviados'] + $r['falhas'];
        }
        if (!$houve) {
            return null;
        }

        $admins = Database::todos(
            "SELECT id, nome, email FROM usuario
             WHERE ativo = 1 AND perfil = 'ADMIN' AND email <> '' ORDER BY nome"
        );
        if (!$admins) {
            return null;
        }

        $carteira = self::carteira($hoje);
        $resumo = [
            'enviados' => 0, 'sem_itens' => 0, 'falhas' => 0,
            'ja_enviados' => 0, 'reenviados' => 0, 'detalhes' => [],
        ];

        foreach ($admins as $u) {
            $ja = Database::um(
                'SELECT id FROM envio_email
                 WHERE tipo = ? AND referencia = ? AND usuario_id = ? AND erro IS NULL',
                ['RESUMO', $hoje, (int)$u['id']]
            );
            if ($ja && !$forcar) {
                $resumo['ja_enviados']++;
                continue;
            }

            $erro = null;
            try {
                Email::enviar(
                    $u['email'],
                    'Planejamento — relatório do disparo (' . date('d/m', strtotime($hoje)) . ')',
                    self::corpoRelatorio($u['nome'], $resultado, $carteira, $hoje)
                );
                $resumo['enviados']++;
                if ($ja) {
                    $resumo['reenviados']++;
                }
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
                $resumo['falhas']++;
            }
            $resumo['detalhes'][] = ['usuario' => $u['nome'], 'itens' => count($carteira), 'erro' => $erro];

            Database::executar(
                'INSERT INTO envio_email (tipo, referencia, usuario_id, destinatario, itens, erro)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE itens = VALUES(itens), erro = VALUES(erro),
                                         enviado_em = CURRENT_TIMESTAMP',
                ['RESUMO', $hoje, (int)$u['id'], $u['email'], count($carteira), $erro]
            );
        }
        return $resumo;
    }

    /**
     * A carteira de ações por responsável.
     *
     * Três decisões: entra TODA ação, não só a do dia (o relatório é panorama,
     * não recorte); "atrasada" é medida pela DATA, o mesmo critério do aviso de
     * cobrança, e não pelo status `ATRASADO` — ele é reconciliado na leitura das
     * telas e no horário do agendamento pode estar velho; e ação **sem
     * responsável** ganha linha própria, porque ela não recebe cobrança nenhuma
     * e some de todos os outros relatórios (é `LEFT JOIN`, e o usuário inativo
     * também aparece: a ação dele continua no plano).
     */
    private static function carteira(string $hoje): array
    {
        return Database::todos(
            "SELECT COALESCE(u.nome, '— sem responsável —') AS nome,
                    COUNT(*) AS total,
                    SUM(d.status = 'CONCLUIDO') AS concluidas,
                    SUM(d.status = 'CANCELADO') AS canceladas,
                    SUM(d.status IN " . self::STATUS_ABERTOS . ") AS abertas,
                    SUM(d.status IN " . self::STATUS_ABERTOS . "
                        AND d.data_fim IS NOT NULL AND d.data_fim < ?) AS atrasadas,
                    SUM(d.status IN " . self::STATUS_ABERTOS . " AND d.data_fim = ?) AS vencem_hoje,
                    SUM(d.status = 'NAO_INICIADO') AS nao_iniciadas,
                    SUM(d.status = 'EM_ANDAMENTO') AS em_andamento,
                    SUM(d.status = 'ATRASADO') AS marcadas_atraso,
                    SUM(d.status = 'PAUSADO') AS pausadas,
                    SUM(d.status = 'AGUARDANDO_VALIDACAO') AS aguardando
             FROM desdobramento d
             LEFT JOIN usuario u ON u.id = d.quem_usuario_id
             GROUP BY nome
             ORDER BY atrasadas DESC, abertas DESC, nome",
            [$hoje, $hoje]
        );
    }

    private static function rodar(string $tipo, string $hoje, bool $forcar = false): array
    {
        $referencia = $tipo === 'SEMANAL' ? date('Y-m-d', strtotime('monday this week', strtotime($hoje))) : $hoje;
        $resumo = [
            'enviados' => 0, 'sem_itens' => 0, 'falhas' => 0,
            'ja_enviados' => 0, 'reenviados' => 0, 'detalhes' => [],
        ];

        $ate = self::limite($tipo, $hoje);

        foreach (self::destinatarios() as $u) {
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

            $acoes = self::acoesDe((int)$u['id'], $ate);
            // O bloco do negócio sai do mesmo disparo, e SEM repetir o que já
            // está na carteira da pessoa: o gestor que também responde pela
            // ação a veria duas vezes na mesma mensagem, e contaria duas.
            $doNegocio = [];
            if ($u['gestor'] ?? false) {
                $meus = array_column($acoes, 'id');
                $doNegocio = array_values(array_filter(
                    self::acoesDoNegocio((int)$u['id'], $ate),
                    static fn($a) => !in_array($a['id'], $meus)
                ));
            }
            if (!$acoes && !$doNegocio) {
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
                    self::corpo($tipo, $u['nome'], $acoes, $hoje, $doNegocio)
                );
                $resumo['enviados']++;
                if ($ja) {
                    $resumo['reenviados']++;
                }
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
                $resumo['falhas']++;
            }
            // `itens` conta as DUAS listas: é o número que o relatório do
            // disparo mostra, e contar só a carteira própria diria "0 itens"
            // num e-mail que o gestor recebeu cheio.
            $itens = count($acoes) + count($doNegocio);
            $resumo['detalhes'][] = ['usuario' => $u['nome'], 'itens' => $itens, 'erro' => $erro];

            Database::executar(
                // `enviado_em` também é atualizado: com o reenvio manual, a linha
                // passa a valer pelo ÚLTIMO disparo. Mantê-la no primeiro deixava
                // o registro dizendo que o aviso saiu de manhã quando a caixa da
                // pessoa mostra o das cinco da tarde.
                'INSERT INTO envio_email (tipo, referencia, usuario_id, destinatario, itens, erro)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE itens = VALUES(itens), erro = VALUES(erro),
                                         enviado_em = CURRENT_TIMESTAMP',
                [$tipo, $referencia, (int)$u['id'], $u['email'], $itens, $erro]
            );
        }
        return $resumo;
    }

    /**
     * Quem GERE um negócio, nas DUAS formas em que a casa cadastra isso.
     *
     * 1. O **vínculo da tela de Usuários**: perfil GESTOR mais os negócios
     *    marcados nele (`usuario_negocio`). É a forma em uso — foi o que o
     *    cliente mostrou ao pedir o aviso ("Ademar da Silva · Gestor · 1 -
     *    CEREAIS, 13 - UBS").
     * 2. O campo **gestor do negócio** (`negocio.gestor_id`), da tela de
     *    Negócios. Está vazio na base, mas é cadastro válido e diz a mesma
     *    coisa; ler só um dos dois deixaria o aviso dependendo de qual tela a
     *    pessoa usou.
     *
     * LEITURA fica de fora do caminho (1): o perfil existe para quem acompanha
     * sem responder por nada. ADMIN, CONTROLADORIA e DIREÇÃO também: eles
     * enxergam todos os negócios, e entrar por aqui lhes mandaria o plano
     * inteiro da cooperativa todo dia — quem administra já recebe o relatório
     * do disparo, que é o panorama feito para eles. Pelo caminho (2) qualquer
     * perfil entra: ali alguém escolheu aquela pessoa para aquele negócio.
     */
    private const GERE_O_NEGOCIO = "n.ativo = 1 AND (n.gestor_id = %1\$s.id
            OR (%1\$s.perfil = 'GESTOR' AND EXISTS (
                  SELECT 1 FROM usuario_negocio un
                  WHERE un.usuario_id = %1\$s.id AND un.negocio_id = n.id)))";

    /**
     * Quem recebe aviso: quem RESPONDE por alguma ação em aberto e quem GERE um
     * negócio com plano (pedido do cliente, 2026-11-03: "precisamos garantir
     * que o e-mail vai para o gestor do negócio").
     *
     * Até aqui a lista era só a primeira metade, e o gestor que não respondia
     * por ação nenhuma não recebia nada: nem das ações do negócio dele, nem das
     * que estão SEM responsável — estas não entram em cobrança alguma e, antes
     * disto, não geravam e-mail para ninguém.
     *
     * Cada pessoa entra UMA vez, com a marca `gestor`, e recebe UMA mensagem
     * com as duas partes. Não é arrumação: a chave única de `envio_email` é
     * (tipo, referência, usuário), então a segunda mensagem do dia para a mesma
     * pessoa sobrescreveria o registro da primeira — e quem é gestor e
     * responsável receberia dois e-mails por dia para ler a mesma lista duas
     * vezes.
     */
    private static function destinatarios(): array
    {
        $gere = sprintf(self::GERE_O_NEGOCIO, 'u');
        return Database::todos(
            "SELECT u.id, u.nome, u.email,
                    EXISTS (SELECT 1 FROM negocio n
                            JOIN planejamento pl ON pl.negocio_id = n.id
                            WHERE $gere) AS gestor
             FROM usuario u
             WHERE u.ativo = 1 AND u.email <> ''
               AND (EXISTS (SELECT 1 FROM desdobramento d
                            WHERE d.quem_usuario_id = u.id
                              AND d.status IN " . self::STATUS_ABERTOS . ")
                    OR EXISTS (SELECT 1 FROM negocio n
                               JOIN planejamento pl ON pl.negocio_id = n.id
                               WHERE $gere))
             ORDER BY u.nome"
        );
    }

    /**
     * Até quando o aviso olha: o diário fecha em hoje; o semanal, no domingo
     * desta semana. É a mesma régua para a carteira da pessoa e para a do
     * negócio que ela gere — duas janelas na mesma mensagem fariam o gestor
     * comparar listas de períodos diferentes.
     */
    private static function limite(string $tipo, string $hoje): string
    {
        return $tipo === 'SEMANAL'
            ? date('Y-m-d', strtotime('sunday this week', strtotime($hoje)))
            : $hoje;
    }

    /** Ações atribuídas à pessoa, com prazo até a data informada. */
    private static function acoesDe(int $usuarioId, string $ate): array
    {
        return Database::todos(self::CONSULTA, [$usuarioId, $ate]);
    }

    /**
     * Ações do(s) negócio(s) que a pessoa GERE, com prazo até a data informada
     * — inclusive as que não têm responsável, que são justamente as que não
     * chegam a ninguém pela carteira.
     *
     * Aqui não vai a cláusula de escopo da consulta de cima: ser o gestor do
     * negócio É a autorização para ver o plano dele. O recorte de
     * `GERE_O_NEGOCIO` já limita a mensagem ao que a pessoa responde.
     */
    private static function acoesDoNegocio(int $usuarioId, string $ate): array
    {
        return Database::todos(self::consultaNegocio(), [$usuarioId, $ate]);
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

    /**
     * Ações do negócio para o GESTOR dele. Traz o `quem` de cada uma: a lista
     * não é dele, é do negócio, e sem o nome do responsável ela não diz a quem
     * cobrar — e ação sem responsável aparece dizendo isso, que é a informação
     * mais acionável das duas.
     */
    private static function consultaNegocio(): string
    {
        // O gestor entra na consulta como JOIN (`g`): quem decide se ele gere
        // o negócio é o perfil DELE mais o vínculo, e isso não cabe num
        // `WHERE coluna = ?`. Daí a consulta ser montada e não constante.
        return 'SELECT ' . self::CAMPOS . ", q.nome AS quem, n.nome AS negocio
         FROM desdobramento d
         JOIN projeto p ON p.id = d.projeto_id
         JOIN planejamento pl ON pl.id = p.planejamento_id
         JOIN negocio n ON n.id = pl.negocio_id
         JOIN usuario g ON g.id = ?
         LEFT JOIN iniciativa i ON i.id = d.iniciativa_id
         LEFT JOIN usuario q ON q.id = d.quem_usuario_id
         WHERE " . sprintf(self::GERE_O_NEGOCIO, 'g') . ' AND d.status IN ' . self::STATUS_ABERTOS . "
           AND d.data_fim IS NOT NULL AND d.data_fim <= ?
         ORDER BY d.data_fim, d.prioridade = 'ALTA' DESC";
    }

    private static function corpo(
        string $tipo,
        string $nome,
        array $acoes,
        string $hoje,
        array $doNegocio = []
    ): string
    {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $cfg = $GLOBALS['config'] ?? (require dirname(__DIR__, 2) . '/config/config.php');
        $url = $cfg['app_url'] ?? '';

        $atrasadas = array_filter($acoes, fn($a) => $a['data_fim'] < $hoje);
        $hojeItens = array_filter($acoes, fn($a) => $a['data_fim'] === $hoje);
        $proximas  = array_filter($acoes, fn($a) => $a['data_fim'] > $hoje);

        // `$comQuem` só é ligado no bloco do negócio: ali a lista não é da
        // pessoa, e sem o nome do responsável ela não diz a quem cobrar. Na
        // carteira própria o nome seria o dela em toda linha.
        $linhas = function (array $itens, string $titulo, string $cor, bool $comQuem = false) use ($esc) {
            if (!$itens) {
                return '';
            }
            $html = '<h3 style="font:600 15px sans-serif;color:' . $cor . ';margin:18px 0 6px">'
                . $esc($titulo) . ' (' . count($itens) . ')</h3>'
                . '<table cellpadding="6" cellspacing="0" border="0" width="100%" '
                . 'style="border-collapse:collapse;font:14px sans-serif">';
            foreach ($itens as $a) {
                $prazo = date('d/m/Y', strtotime($a['data_fim']));
                // Ação SEM responsável é dita com todas as letras, e em
                // vermelho: ela não entra em cobrança nenhuma, não gera e-mail
                // para ninguém, e é por isso que ela precisa chegar ao gestor.
                $quem = $comQuem
                    ? ('<br>' . (trim((string)($a['quem'] ?? '')) !== ''
                        ? '<span style="color:#6a6a6a;font-size:12px">Responsável: '
                          . $esc($a['quem']) . '</span>'
                        : '<span style="color:#b3261e;font-size:12px">Sem responsável</span>'))
                    : '';
                $html .= '<tr style="border-top:1px solid #e4e9e6">'
                    . '<td style="padding:8px 6px">'
                    . '<strong>' . $esc($a['o_que']) . '</strong><br>'
                    . '<span style="color:#6a6a6a;font-size:12px">'
                    . $esc($a['projeto']) . ($a['iniciativa'] ? ' · ' . $esc($a['iniciativa']) : '')
                    . '</span>' . $quem . '</td>'
                    . '<td align="right" style="padding:8px 6px;white-space:nowrap;color:' . $cor . '">'
                    . $prazo . '<br><span style="color:#6a6a6a;font-size:12px">'
                    . (int)$a['progresso'] . '%</span></td></tr>';
            }
            return $html . '</table>';
        };

        /* O bloco do NEGÓCIO, para quem o gere (pedido do cliente,
         * 2026-11-03). Vem DEPOIS da carteira própria — primeiro o que a
         * pessoa faz, depois o que ela cobra — e com um filete separando os
         * dois: emendados, o gestor leria as ações dos outros como suas.
         *
         * O título nomeia o negócio: quem gere dois recebe os dois blocos, e
         * sem o nome as duas listas viram uma. */
        $negocios = [];
        foreach ($doNegocio as $a) {
            $negocios[(string)($a['negocio'] ?? '')][] = $a;
        }
        $blocoNegocio = '';
        foreach ($negocios as $nomeNeg => $itens) {
            $blocoNegocio .= '<div style="border-top:2px solid #e4e9e6;margin:26px 0 0;padding-top:4px">'
                . '<h2 style="font:700 15px sans-serif;color:#06432a;margin:14px 0 0">'
                . $esc($nomeNeg !== '' ? $nomeNeg : 'Seu negócio') . '</h2>'
                . '<p style="font:13px sans-serif;color:#6a6a6a;margin:2px 0 0">'
                . 'Você responde por este negócio. Abaixo, as ações do plano dele '
                . ($tipo === 'SEMANAL' ? 'nesta semana' : 'com prazo até hoje') . '.</p>'
                . $linhas(array_filter($itens, fn($a) => $a['data_fim'] < $hoje), 'Atrasadas', '#b3261e', true)
                . $linhas(array_filter($itens, fn($a) => $a['data_fim'] === $hoje), 'Vencem hoje', '#b08d4f', true)
                . ($tipo === 'SEMANAL'
                    ? $linhas(array_filter($itens, fn($a) => $a['data_fim'] > $hoje), 'Ainda nesta semana', '#007a45', true)
                    : '')
                . '</div>';
        }

        // Quem é só gestor não tem carteira própria: a frase "estas são as
        // suas pendências" acima de uma lista vazia mandaria procurar o que
        // não existe.
        $intro = $acoes
            ? ($tipo === 'SEMANAL'
                ? 'Este é o resumo das suas ações para esta semana no planejamento estratégico.'
                : 'Estas são as suas pendências de hoje no planejamento estratégico.')
            : ($tipo === 'SEMANAL'
                ? 'Você não tem ações suas nesta semana. Abaixo, o que está aberto no negócio que você responde.'
                : 'Você não tem pendências suas hoje. Abaixo, o que está aberto no negócio que você responde.');

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
            . $blocoNegocio
            . ($url
                ? '<p style="margin:22px 0 0"><a href="' . $esc($url) . '" '
                  . 'style="background:#007a45;color:#fff;font:600 14px sans-serif;'
                  . 'padding:10px 18px;border-radius:8px;text-decoration:none">Abrir o planejamento</a></p>'
                : '')
            . '<p style="font:12px sans-serif;color:#9aa6a0;margin:22px 0 0">'
            . 'Mensagem automática — não é preciso responder.</p>'
            . '</div></div></body></html>';
    }

    /**
     * O corpo do relatório do disparo.
     *
     * Percentual sempre ACOMPANHADO do número absoluto: "100% atrasadas" de uma
     * ação só e de quarenta pedem providências diferentes, e sozinho o
     * percentual não distingue as duas.
     */
    private static function corpoRelatorio(string $nome, array $resultado, array $carteira, string $hoje): string
    {
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $cfg = $GLOBALS['config'] ?? (require dirname(__DIR__, 2) . '/config/config.php');
        $url = $cfg['app_url'] ?? '';
        $pct = fn(int $n, int $base) => $base > 0 ? round($n * 100 / $base) . '%' : '—';
        $num = fn($v) => (int)$v;

        // ---- 1. O que acabou de sair ----
        // O rótulo é escrito, não derivado da chave: `ucfirst('diario')` dava
        // "Diario", sem acento, num e-mail que vai para a direção.
        $rotulos = ['semanal' => 'Relatório da semana', 'diario' => 'Pendências do dia'];
        $envio = '';
        foreach ($resultado as $qual => $r) {
            $partes = [$num($r['enviados']) . ' enviado(s)'];
            if ($r['reenviados'] ?? 0) {
                $partes[] = $num($r['reenviados']) . ' reenviado(s)';
            }
            if ($r['ja_enviados']) {
                $partes[] = $num($r['ja_enviados']) . ' já enviado(s)';
            }
            if ($r['sem_itens']) {
                $partes[] = $num($r['sem_itens']) . ' sem pendência';
            }
            $falhas = $num($r['falhas']);
            $envio .= '<li style="margin:2px 0"><strong>'
                . $esc($rotulos[$qual] ?? ucfirst((string)$qual)) . '</strong>: '
                . $esc(implode(', ', $partes))
                . ($falhas ? ' — <span style="color:#b3261e">' . $falhas . ' falha(s)</span>' : '')
                . '</li>';
            foreach ($r['detalhes'] as $d) {
                if ($d['erro']) {
                    $envio .= '<li style="margin:2px 0 2px 14px;color:#b3261e;font-size:13px">'
                        . $esc($d['usuario']) . ': ' . $esc(mb_substr($d['erro'], 0, 160)) . '</li>';
                }
            }
        }

        // ---- 2. Totais, para o percentual de cada linha ter denominador ----
        $soma = ['total' => 0, 'abertas' => 0, 'atrasadas' => 0, 'vencem_hoje' => 0,
                 'concluidas' => 0, 'canceladas' => 0, 'nao_iniciadas' => 0, 'em_andamento' => 0,
                 'marcadas_atraso' => 0, 'pausadas' => 0, 'aguardando' => 0];
        foreach ($carteira as $c) {
            foreach ($soma as $k => $_) {
                $soma[$k] += (int)$c[$k];
            }
        }

        $th = 'style="padding:6px;font:600 12px sans-serif;color:#6a6a6a;'
            . 'border-bottom:1px solid #e4e9e6;text-align:right"';
        $td = 'style="padding:6px;font:13px sans-serif;border-bottom:1px solid #f0f3f1;text-align:right"';
        $tdE = 'style="padding:6px;font:13px sans-serif;border-bottom:1px solid #f0f3f1"';

        $linhas = '';
        foreach ($carteira as $c) {
            $total = (int)$c['total'];
            $abertas = (int)$c['abertas'];
            $atras = (int)$c['atrasadas'];
            $linhas .= '<tr>'
                . '<td ' . $tdE . '>' . $esc($c['nome']) . '</td>'
                . '<td ' . $td . '>' . $total . '</td>'
                . '<td ' . $td . '>' . $abertas . '</td>'
                . '<td ' . $td . ' >' . ($atras
                    ? '<span style="color:#b3261e"><strong>' . $atras . '</strong> ('
                      . $pct($atras, $abertas) . ')</span>'
                    : '0')
                . '</td>'
                . '<td ' . $td . '>' . (int)$c['vencem_hoje'] . '</td>'
                . '<td ' . $td . '>' . (int)$c['concluidas']
                . ' <span style="color:#6a6a6a">(' . $pct((int)$c['concluidas'], $total) . ')</span></td>'
                . '</tr>';
        }

        $situacoes = [
            ['Não iniciadas', $soma['nao_iniciadas']],
            ['Em andamento', $soma['em_andamento']],
            ['Marcadas como atrasadas', $soma['marcadas_atraso']],
            ['Pausadas', $soma['pausadas']],
            ['Aguardando validação', $soma['aguardando']],
            ['Concluídas', $soma['concluidas']],
            ['Canceladas', $soma['canceladas']],
        ];
        $porSituacao = '';
        foreach ($situacoes as [$rotulo, $n]) {
            $porSituacao .= '<tr><td ' . $tdE . '>' . $esc($rotulo) . '</td>'
                . '<td ' . $td . '>' . (int)$n . '</td>'
                . '<td ' . $td . '>' . $pct((int)$n, $soma['total']) . '</td></tr>';
        }

        return '<!doctype html><html lang="pt-BR"><body style="margin:0;background:#f4f6f5;padding:16px">'
            . '<div style="max-width:720px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden">'
            . '<div style="background:#06432a;color:#fff;padding:14px 18px;font:700 16px sans-serif">'
            . 'Planejamento Estratégico Copérdia — relatório do disparo</div>'
            . '<div style="padding:18px">'
            . '<p style="font:14px sans-serif;margin:0 0 4px">Olá, ' . $esc($nome) . '.</p>'
            . '<p style="font:14px sans-serif;color:#6a6a6a;margin:0">'
            . 'Resumo dos avisos enviados em ' . date('d/m/Y', strtotime($hoje))
            . ' e da situação do plano de ação.</p>'

            . '<h3 style="font:600 15px sans-serif;color:#06432a;margin:18px 0 6px">O que foi enviado</h3>'
            . '<ul style="font:14px sans-serif;margin:0;padding-left:18px">' . $envio . '</ul>'

            . '<h3 style="font:600 15px sans-serif;color:#06432a;margin:18px 0 6px">Por responsável</h3>'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse">'
            . '<tr><th style="padding:6px;font:600 12px sans-serif;color:#6a6a6a;'
            . 'border-bottom:1px solid #e4e9e6;text-align:left">Responsável</th>'
            . '<th ' . $th . '>Total</th><th ' . $th . '>Abertas</th>'
            . '<th ' . $th . '>Atrasadas</th><th ' . $th . '>Vencem hoje</th>'
            . '<th ' . $th . '>Concluídas</th></tr>'
            . ($linhas ?: '<tr><td colspan="6" style="padding:8px;font:13px sans-serif;color:#6a6a6a">'
                . 'Nenhuma ação cadastrada.</td></tr>')
            . '<tr style="background:#f4f6f5"><td ' . $tdE . '><strong>Total</strong></td>'
            . '<td ' . $td . '><strong>' . $soma['total'] . '</strong></td>'
            . '<td ' . $td . '><strong>' . $soma['abertas'] . '</strong></td>'
            . '<td ' . $td . '><strong>' . $soma['atrasadas'] . '</strong> ('
            . $pct($soma['atrasadas'], $soma['abertas']) . ')</td>'
            . '<td ' . $td . '><strong>' . $soma['vencem_hoje'] . '</strong></td>'
            . '<td ' . $td . '><strong>' . $soma['concluidas'] . '</strong> ('
            . $pct($soma['concluidas'], $soma['total']) . ')</td></tr>'
            . '</table>'
            . '<p style="font:12px sans-serif;color:#9aa6a0;margin:6px 0 0">'
            . '“Atrasada” é prazo vencido — o mesmo critério do aviso de cobrança. '
            . 'O percentual de atrasadas é sobre as ABERTAS; o de concluídas, sobre o total.</p>'

            . '<h3 style="font:600 15px sans-serif;color:#06432a;margin:18px 0 6px">Por situação</h3>'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%" '
            . 'style="border-collapse:collapse">'
            . '<tr><th style="padding:6px;font:600 12px sans-serif;color:#6a6a6a;'
            . 'border-bottom:1px solid #e4e9e6;text-align:left">Situação</th>'
            . '<th ' . $th . '>Ações</th><th ' . $th . '>% do total</th></tr>'
            . $porSituacao
            . '</table>'

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

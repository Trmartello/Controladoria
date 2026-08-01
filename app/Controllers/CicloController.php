<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class CicloController
{
    public function listar(): void
    {
        Auth::exigirLogin();
        $ciclos = Database::todos('SELECT * FROM ciclo ORDER BY ano_inicio DESC');
        foreach ($ciclos as &$c) {
            $c['horizontes'] = Database::todos(
                'SELECT * FROM horizonte WHERE ciclo_id = ? ORDER BY ordem, ano_inicio',
                [$c['id']]
            );
        }
        Json::ok($ciclos);
    }

    public function salvar(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $nome   = trim($d['nome'] ?? '');
        $anoBase   = (int)($d['ano_base'] ?? 0);
        $anoInicio = (int)($d['ano_inicio'] ?? 0);
        $anoFim    = (int)($d['ano_fim'] ?? 0);
        $status = $d['status'] ?? 'EM_ELABORACAO';
        if ($nome === '' || !$anoBase || !$anoInicio || !$anoFim) {
            Json::erro('Informe nome, ano do planejamento e período do ciclo.');
        }
        if ($anoFim < $anoInicio) {
            Json::erro('Ano final não pode ser menor que o inicial.');
        }
        if (!in_array($status, ['EM_ELABORACAO', 'VIGENTE', 'ENCERRADO'], true)) {
            Json::erro('Status inválido.');
        }

        if ($id) {
            Database::executar(
                'UPDATE ciclo SET nome = ?, ano_base = ?, ano_inicio = ?, ano_fim = ?, status = ? WHERE id = ?',
                [$nome, $anoBase, $anoInicio, $anoFim, $status, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO ciclo (nome, ano_base, ano_inicio, ano_fim, status) VALUES (?, ?, ?, ?, ?)',
                [$nome, $anoBase, $anoInicio, $anoFim, $status]
            );
            // Todo ciclo nasce com seu planejamento corporativo
            Database::executar(
                "INSERT INTO planejamento (ciclo_id, escopo, negocio_id) VALUES (?, 'CORPORATIVO', NULL)",
                [$id]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function salvarHorizonte(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $cicloId   = (int)($d['ciclo_id'] ?? 0);
        $nome      = trim($d['nome'] ?? '');
        $anoInicio = (int)($d['ano_inicio'] ?? 0);
        $anoFim    = (int)($d['ano_fim'] ?? 0);
        $tema      = trim($d['tema'] ?? '');
        $objetivo  = trim($d['objetivo'] ?? '');
        $ordem     = (int)($d['ordem'] ?? 0);
        if (!$cicloId || $nome === '' || !$anoInicio || !$anoFim || $tema === '' || $objetivo === '') {
            Json::erro('Preencha todos os campos do horizonte (nome, período, tema e objetivo).');
        }
        if ($anoFim < $anoInicio) {
            Json::erro('Ano final do horizonte não pode ser menor que o inicial.');
        }

        // As checagens de anos só rodam quando os anos mudam: dados legados
        // fora da regra continuam editáveis nos demais campos (nome, tema...)
        $atual = $id ? Database::um('SELECT ano_inicio, ano_fim FROM horizonte WHERE id = ?', [$id]) : null;
        $anosMudaram = !$atual
            || (int)$atual['ano_inicio'] !== $anoInicio
            || (int)$atual['ano_fim'] !== $anoFim;
        if ($anosMudaram) {
            // Anos fora do período do ciclo criariam horizontes inalcançáveis
            // (o seletor de ano do projeto só oferece os anos do ciclo)
            $ciclo = Database::um('SELECT ano_inicio, ano_fim FROM ciclo WHERE id = ?', [$cicloId]);
            if (!$ciclo) {
                Json::erro('Ciclo não encontrado.', 404);
            }
            if ($anoInicio < (int)$ciclo['ano_inicio'] || $anoFim > (int)$ciclo['ano_fim']) {
                Json::erro("Os anos do horizonte devem estar dentro do período do ciclo ({$ciclo['ano_inicio']}–{$ciclo['ano_fim']}).");
            }
            // Os anos definem a qual horizonte cada projeto pertence — não
            // podem se sobrepor entre horizontes do mesmo ciclo
            $conflito = Database::um(
                'SELECT nome FROM horizonte
                 WHERE ciclo_id = ? AND id <> ? AND ano_inicio <= ? AND ano_fim >= ?',
                [$cicloId, $id ?? 0, $anoFim, $anoInicio]
            );
            if ($conflito) {
                Json::erro("O período {$anoInicio}–{$anoFim} conflita com o horizonte {$conflito['nome']} deste ciclo.");
            }
        }

        if ($id) {
            Database::executar(
                'UPDATE horizonte SET ciclo_id = ?, nome = ?, ano_inicio = ?, ano_fim = ?, tema = ?, objetivo = ?, ordem = ? WHERE id = ?',
                [$cicloId, $nome, $anoInicio, $anoFim, $tema, $objetivo, $ordem, $id]
            );
        } else {
            $id = (int)Database::executar(
                'INSERT INTO horizonte (ciclo_id, nome, ano_inicio, ano_fim, tema, objetivo, ordem) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$cicloId, $nome, $anoInicio, $anoFim, $tema, $objetivo, $ordem]
            );
        }

        if ($anosMudaram) {
            // Realinha os projetos do ciclo: cada um volta para o horizonte
            // que contempla o seu ano após a mudança de intervalo
            Database::executar(
                'UPDATE projeto p
                 JOIN planejamento pl ON pl.id = p.planejamento_id
                 JOIN horizonte h ON h.ciclo_id = pl.ciclo_id
                   AND p.ano BETWEEN h.ano_inicio AND h.ano_fim
                 SET p.horizonte_id = h.id
                 WHERE pl.ciclo_id = ?',
                [$cicloId]
            );
        }
        Json::ok(['id' => $id]);
    }
}

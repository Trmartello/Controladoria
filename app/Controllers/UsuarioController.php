<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class UsuarioController
{
    private const SENHA_MINIMA = 8;

    public function listar(): void
    {
        Auth::exigirAdministrador();
        $usuarios = Database::todos(
            'SELECT id, nome, email, perfil, ativo FROM usuario ORDER BY nome'
        );
        foreach ($usuarios as &$u) {
            $u['negocios'] = array_map(
                fn($l) => (int)$l['negocio_id'],
                Database::todos('SELECT negocio_id FROM usuario_negocio WHERE usuario_id = ?', [$u['id']])
            );
        }
        Json::ok($usuarios);
    }

    public function salvar(?int $id = null): void
    {
        Auth::exigirAdministrador();
        $d = Json::corpo();
        $nome   = trim($d['nome'] ?? '');
        $email  = trim($d['email'] ?? '');
        $perfil = $d['perfil'] ?? 'LEITURA';
        $senha  = $d['senha'] ?? '';
        $ativo  = isset($d['ativo']) ? (int)!!$d['ativo'] : 1;
        $negocios = array_map('intval', $d['negocios'] ?? []);

        if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Json::erro('Informe nome e e-mail válidos.');
        }
        if (!in_array($perfil, ['ADMIN', 'CONTROLADORIA', 'DIRECAO', 'GESTOR', 'LEITURA'], true)) {
            Json::erro('Perfil inválido.');
        }
        $duplicado = Database::um('SELECT id FROM usuario WHERE email = ? AND id <> ?', [$email, $id ?? 0]);
        if ($duplicado) {
            Json::erro('Já existe usuário com este e-mail.');
        }

        if ($senha !== '' && strlen($senha) < self::SENHA_MINIMA) {
            Json::erro('A senha deve ter ao menos ' . self::SENHA_MINIMA . ' caracteres.');
        }

        if ($id) {
            Database::executar(
                'UPDATE usuario SET nome = ?, email = ?, perfil = ?, ativo = ? WHERE id = ?',
                [$nome, $email, $perfil, $ativo, $id]
            );
            if ($senha !== '') {
                Database::executar(
                    'UPDATE usuario SET senha_hash = ? WHERE id = ?',
                    [password_hash($senha, PASSWORD_DEFAULT), $id]
                );
            }
        } else {
            if ($senha === '') {
                Json::erro('Informe a senha inicial do usuário.');
            }
            $id = (int)Database::executar(
                'INSERT INTO usuario (nome, email, senha_hash, perfil, ativo) VALUES (?, ?, ?, ?, ?)',
                [$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $perfil, $ativo]
            );
        }

        // Vínculos usuário × negócio (relevantes para GESTOR/LEITURA)
        Database::executar('DELETE FROM usuario_negocio WHERE usuario_id = ?', [$id]);
        foreach (array_unique($negocios) as $negocioId) {
            Database::executar(
                'INSERT INTO usuario_negocio (usuario_id, negocio_id) VALUES (?, ?)',
                [$id, $negocioId]
            );
        }
        Json::ok(['id' => $id]);
    }

    public function trocarSenha(): void
    {
        $u = Auth::exigirLogin();
        $d = Json::corpo();
        $atual = $d['senha_atual'] ?? '';
        $nova  = $d['senha_nova'] ?? '';
        if (strlen($nova) < self::SENHA_MINIMA) {
            Json::erro('A nova senha deve ter ao menos ' . self::SENHA_MINIMA . ' caracteres.');
        }
        $linha = Database::um('SELECT senha_hash FROM usuario WHERE id = ?', [$u['id']]);
        if (!$linha || !password_verify($atual, $linha['senha_hash'])) {
            Json::erro('Senha atual incorreta.', 403);
        }
        Database::executar(
            'UPDATE usuario SET senha_hash = ? WHERE id = ?',
            [password_hash($nova, PASSWORD_DEFAULT), $u['id']]
        );
        Json::ok();
    }
}

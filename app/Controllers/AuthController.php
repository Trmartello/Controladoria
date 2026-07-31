<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class AuthController
{
    public function login(): void
    {
        $dados = Json::corpo();
        $email = trim($dados['email'] ?? '');
        $senha = $dados['senha'] ?? '';

        $u = Database::um('SELECT * FROM usuario WHERE email = ? AND ativo = 1', [$email]);
        if (!$u || !password_verify($senha, $u['senha_hash'])) {
            Json::erro('E-mail ou senha inválidos.', 401);
        }

        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id'     => (int)$u['id'],
            'nome'   => $u['nome'],
            'email'  => $u['email'],
            'perfil' => $u['perfil'],
        ];
        Json::ok(self::dadosSessao());
    }

    public function logout(): void
    {
        session_destroy();
        Json::ok();
    }

    public function me(): void
    {
        Auth::exigirLogin();
        Json::ok(self::dadosSessao());
    }

    /** Usuário logado + negócios do seu escopo (para montar o seletor de contexto). */
    private static function dadosSessao(): array
    {
        $u = Auth::usuario();
        $escopo = Auth::escopoNegocios($u);
        $sql = "SELECT id, cod_negocio, nome, CONCAT(cod_negocio, ' - ', nome) AS rotulo
                FROM negocio WHERE ativo = 1";
        $params = [];
        if ($escopo !== null) {
            if (!$escopo) {
                $negocios = [];
            } else {
                $marcadores = implode(',', array_fill(0, count($escopo), '?'));
                $negocios = Database::todos(
                    "$sql AND id IN ($marcadores) ORDER BY CAST(cod_negocio AS UNSIGNED), nome",
                    $escopo
                );
            }
        } else {
            $negocios = Database::todos("$sql ORDER BY CAST(cod_negocio AS UNSIGNED), nome");
        }
        return [
            'usuario'  => $u,
            'veTudo'   => Auth::veTudo($u),
            'negocios' => $negocios,
            'ciclos'   => Database::todos('SELECT * FROM ciclo ORDER BY ano_inicio DESC'),
        ];
    }
}

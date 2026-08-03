<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class AuthController
{
    /** Falhas de login toleradas por e-mail (e o dobro por origem) na janela. */
    private const MAX_FALHAS = 10;
    private const JANELA_MIN = 15;

    public function login(): void
    {
        $dados = Json::corpo();
        $email = mb_substr(trim($dados['email'] ?? ''), 0, 190);
        $senha = $dados['senha'] ?? '';
        $origem = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);

        // A SENHA É CONFERIDA PRIMEIRO, e quem acerta entra mesmo com o balde
        // cheio. A ordem inversa criava dois problemas sérios: a conta ficava
        // travada por 15 minutos sem saída (não há recuperação de senha nem
        // destravamento por admin, e o DELETE de sucesso abaixo era inalcançável
        // com o balde cheio), e qualquer um que soubesse um e-mail corporativo
        // trancava a conta do diretor à vontade — bastando errar 10 vezes. Pior:
        // o balde por origem derrubava o escritório inteiro, porque atrás do NAT
        // ou da borda do Railway todo mundo compartilha o REMOTE_ADDR.
        // É a mesma conclusão a que rodadaPorPin já tinha chegado: quem acerta
        // nunca é punido; o balde só alcança quem erra.
        // O custo por tentativa aqui é o próprio bcrypt do password_verify.
        $u = Database::um('SELECT * FROM usuario WHERE email = ? AND ativo = 1', [$email]);
        if (!$u || !password_verify($senha, $u['senha_hash'])) {
            Database::executar(
                'INSERT INTO login_tentativa (origem, email) VALUES (?, ?)',
                [$origem, $email]
            );
            // Limpeza oportunista: a tabela não pode crescer sem fim
            Database::executar(
                'DELETE FROM login_tentativa WHERE criado_em < (NOW() - INTERVAL 1 DAY) LIMIT 500'
            );
            // Passado o limite, a resposta muda de tom — sem revelar mais nada
            if ($this->bloqueado($email, $origem)) {
                Json::erro('Muitas tentativas. Espere alguns minutos antes de tentar de novo.', 429);
            }
            Json::erro('E-mail ou senha inválidos.', 401);
        }
        // Entrou: zera o balde daquele e-mail, para não punir quem só errou antes
        Database::executar('DELETE FROM login_tentativa WHERE email = ?', [$email]);

        session_regenerate_id(true);
        $_SESSION['usuario'] = [
            'id'     => (int)$u['id'],
            'nome'   => $u['nome'],
            'email'  => $u['email'],
            'perfil' => $u['perfil'],
        ];
        Json::ok(self::dadosSessao());
    }

    /**
     * Balde duplo: por e-mail (protege a conta alvo, mesmo com o atacante
     * trocando de IP) e por origem (protege contra varrer vários e-mails de um
     * lugar só). O da origem é mais folgado, porque um escritório inteiro sai
     * pelo mesmo IP.
     *
     * Só é consultado DEPOIS de a senha falhar: ele muda a mensagem de quem
     * está errando, nunca nega a entrada de quem acerta.
     */
    private function bloqueado(string $email, string $origem): bool
    {
        $porEmail = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM login_tentativa
             WHERE email = ? AND criado_em > (NOW() - INTERVAL ? MINUTE)',
            [$email, self::JANELA_MIN]
        )['n'] ?? 0);
        if ($porEmail >= self::MAX_FALHAS) {
            return true;
        }
        $porOrigem = (int)(Database::um(
            'SELECT COUNT(*) AS n FROM login_tentativa
             WHERE origem = ? AND criado_em > (NOW() - INTERVAL ? MINUTE)',
            [$origem, self::JANELA_MIN]
        )['n'] ?? 0);
        return $porOrigem >= self::MAX_FALHAS * 2;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
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

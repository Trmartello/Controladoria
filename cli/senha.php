<?php

/**
 * Redefinir a senha de um usuário pela linha de comando.
 *
 *   php cli/senha.php listar                    # quem está cadastrado
 *   php cli/senha.php trocar <e-mail>           # sorteia uma senha e a mostra
 *   php cli/senha.php trocar <e-mail> <senha>   # define a que você escolher
 *   php cli/senha.php trocar <e-mail> --ativar  # reativa junto, se estiver inativo
 *
 * ESTE É O CAMINHO DE QUANDO NINGUÉM MAIS ENTRA. A senha é bcrypt e não tem
 * volta: nem o sistema, nem um ADMIN, nem o dono do banco leem a original. Com
 * o único ADMIN trancado do lado de fora, não sobrava caminho nenhum —
 * `ADMIN_SENHA` não resolve, porque o passo do migrate que cria o admin só roda
 * quando NÃO existe nenhum (`WHERE perfil = 'ADMIN'` com contagem zero), e
 * reimplantar com a variável definida não toca em quem já está lá.
 *
 * Não é um buraco novo na segurança: quem executa isto já tem o shell do
 * servidor e, com ele, o `config/config.php` e o banco inteiro — poderia
 * escrever o hash na mão com um cliente MySQL. O que a CLI acrescenta é fazer
 * isso certo: com o mesmo `password_hash` da aplicação, com o mesmo mínimo de
 * caracteres e sem deixar a senha no histórico do shell quando ela é sorteada.
 *
 * Cuidados que não podem ser afrouxados:
 *
 * - a senha **nunca** vai num parâmetro de consulta nem em log; ela é escrita
 *   uma vez na saída padrão e some. Quem passa a senha como argumento a deixa
 *   no `history` e no `ps` — por isso o modo sorteado é o padrão;
 * - reativar é **explícito** (`--ativar`). Devolver a senha de alguém que foi
 *   desativado de propósito, e reativá-lo junto sem dizer, desfaria uma decisão
 *   de outra pessoa em silêncio. Sem a opção, a CLI troca a senha e AVISA que
 *   o acesso segue bloqueado — `Auth::exigirLogin` relê `ativo` a cada
 *   requisição, então senha certa em conta inativa não entra;
 * - o mínimo de caracteres é o MESMO da tela (`UsuarioController::SENHA_MINIMA`,
 *   8). Aceitar menos aqui deixaria a CLI gravando o que o formulário recusa.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$GLOBALS['config'] = require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/Core/Database.php';

use App\Core\Database;

/** O mesmo mínimo do formulário — uma regra só para os dois caminhos. */
const SENHA_MINIMA = 8;

/**
 * Banco fora do ar vira UMA linha, não um `stack trace`.
 *
 * Esta CLI é usada no pior momento possível — ninguém entra no sistema — e por
 * alguém que pode não ser quem a escreveu. Um `Uncaught PDOException` com cinco
 * linhas de pilha faz parecer que a ferramenta está quebrada, quando o que
 * falta é a variável de conexão do ambiente.
 */
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, "Não foi possível falar com o banco: {$e->getMessage()}\n");
    fwrite(STDERR, "Confira as variáveis DB_HOST, DB_PORT, DB_NAME, DB_USER e DB_PASS.\n");
    exit(1);
});

$argumentos = array_slice($argv, 1);
$ativar = in_array('--ativar', $argumentos, true);
$argumentos = array_values(array_filter($argumentos, fn($a) => $a !== '--ativar'));
$comando = $argumentos[0] ?? '';

function uso(): void
{
    fwrite(STDERR, <<<TXT
    Uso:
      php cli/senha.php listar
      php cli/senha.php trocar <e-mail> [nova-senha] [--ativar]

    Sem a nova senha, uma é sorteada e mostrada uma única vez.

    TXT);
    exit(1);
}

if ($comando === 'listar') {
    $usuarios = Database::todos(
        'SELECT id, nome, email, perfil, ativo FROM usuario ORDER BY perfil, nome'
    );
    if (!$usuarios) {
        fwrite(STDERR, "Nenhum usuário cadastrado.\n");
        exit(1);
    }
    printf("%-4s %-28s %-34s %-14s %s\n", 'id', 'nome', 'e-mail', 'perfil', 'situação');
    foreach ($usuarios as $u) {
        printf(
            "%-4s %-28s %-34s %-14s %s\n",
            $u['id'],
            mb_strimwidth($u['nome'], 0, 28, '…'),
            mb_strimwidth($u['email'], 0, 34, '…'),
            $u['perfil'],
            (int)$u['ativo'] === 1 ? 'ativo' : 'INATIVO'
        );
    }
    exit(0);
}

if ($comando !== 'trocar') {
    uso();
}

$email = trim($argumentos[1] ?? '');
if ($email === '') {
    uso();
}

$usuario = Database::um(
    'SELECT id, nome, email, perfil, ativo FROM usuario WHERE email = ?',
    [$email]
);
if (!$usuario) {
    fwrite(STDERR, "Nenhum usuário com o e-mail {$email}. Use 'listar' para ver os cadastrados.\n");
    exit(1);
}

// Sorteada é o PADRÃO: senha escrita na linha de comando fica no histórico do
// shell e é legível no `ps` por qualquer usuário da máquina enquanto o comando
// roda. Quem escolher a própria assume isso conscientemente.
$sorteada = !isset($argumentos[2]);
$senha = $sorteada ? bin2hex(random_bytes(6)) : $argumentos[2];

if (strlen($senha) < SENHA_MINIMA) {
    fwrite(STDERR, 'A senha deve ter ao menos ' . SENHA_MINIMA . " caracteres.\n");
    exit(1);
}

$campos = 'senha_hash = ?';
$valores = [password_hash($senha, PASSWORD_DEFAULT)];
if ($ativar) {
    $campos .= ', ativo = 1';
}
$valores[] = (int)$usuario['id'];
Database::executar("UPDATE usuario SET {$campos} WHERE id = ?", $valores);

echo "Senha redefinida para {$usuario['nome']} <{$usuario['email']}> ({$usuario['perfil']}).\n";
if ($sorteada) {
    echo "Nova senha: {$senha}\n";
    echo "Ela não é guardada em lugar nenhum — anote agora e troque no primeiro acesso.\n";
} else {
    echo "Troque no primeiro acesso: ela ficou no histórico do shell.\n";
}

if ((int)$usuario['ativo'] !== 1) {
    if ($ativar) {
        echo "O usuário estava INATIVO e foi reativado.\n";
    } else {
        // Sem este aviso, a pessoa tentaria a senha nova, levaria "e-mail ou
        // senha inválidos" e concluiria que a redefinição falhou — quando o que
        // barra é o `ativo`, conferido a cada requisição.
        fwrite(STDERR, "ATENÇÃO: o usuário está INATIVO e não vai conseguir entrar.\n");
        fwrite(STDERR, "Rode de novo com --ativar para reativá-lo junto.\n");
    }
}

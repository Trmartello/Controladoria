<?php

namespace App\Core;

class Auth
{
    /** Perfis com visão total (todos os negócios + planejamento corporativo). */
    public const VE_TUDO = ['ADMIN', 'CONTROLADORIA', 'DIRECAO'];

    /** Perfis que administram cadastros (negócios, ciclos, drivers, usuários). */
    public const ADMINISTRA = ['ADMIN', 'CONTROLADORIA'];

    /**
     * Usuário já reconferido no banco nesta requisição. `exigirLogin()` é
     * chamado várias vezes no mesmo pedido (toda `exigir*Planejamento` passa
     * por ele), e sem este cache seriam várias consultas idênticas.
     */
    private static ?array $reconferido = null;

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    /**
     * Perfil e situação do usuário vêm do BANCO a cada requisição, não do
     * retrato gravado na sessão no momento do login.
     *
     * Sem isso, desativar alguém em Cadastros ou rebaixar o perfil dele não
     * revogava nada: a sessão continuava valendo com os poderes antigos até
     * expirar — e o cookie é de 30 dias, renovado a cada acesso. Na prática, um
     * desligamento só surtia efeito quando a pessoa parasse de usar o sistema.
     */
    public static function exigirLogin(): array
    {
        if (self::$reconferido !== null) {
            return self::$reconferido;
        }
        $u = self::usuario();
        if (!$u) {
            Json::erro('Não autenticado.', 401);
        }
        $linha = Database::um(
            'SELECT id, nome, email, perfil FROM usuario WHERE id = ? AND ativo = 1',
            [(int)$u['id']]
        );
        if (!$linha) {
            // Sumiu ou foi desativado no meio da sessão: derruba na hora
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            Json::erro('Sessão encerrada.', 401);
        }
        $linha['id'] = (int)$linha['id'];
        // A sessão acompanha, para quem lê `usuario()` direto ver o valor novo
        $_SESSION['usuario'] = $linha;
        return self::$reconferido = $linha;
    }

    public static function veTudo(?array $u = null): bool
    {
        $u = $u ?? self::usuario();
        return $u && in_array($u['perfil'], self::VE_TUDO, true);
    }

    public static function exigirAdministrador(): array
    {
        $u = self::exigirLogin();
        if (!in_array($u['perfil'], self::ADMINISTRA, true)) {
            Json::erro('Sem permissão para esta operação.', 403);
        }
        return $u;
    }

    /** IDs dos negócios visíveis ao usuário; null = todos. */
    public static function escopoNegocios(?array $u = null): ?array
    {
        $u = $u ?? self::exigirLogin();
        if (self::veTudo($u)) {
            return null;
        }
        $linhas = Database::todos(
            'SELECT negocio_id FROM usuario_negocio WHERE usuario_id = ?',
            [$u['id']]
        );
        return array_map(fn($l) => (int)$l['negocio_id'], $linhas);
    }

    /** Garante que o usuário enxerga o planejamento (negócio no escopo ou corporativo+veTudo). */
    public static function exigirAcessoPlanejamento(int $planejamentoId): array
    {
        $u = self::exigirLogin();
        $plan = Database::um('SELECT * FROM planejamento WHERE id = ?', [$planejamentoId]);
        if (!$plan) {
            Json::erro('Planejamento não encontrado.', 404);
        }
        if ($plan['escopo'] === 'CORPORATIVO') {
            if (!self::veTudo($u)) {
                Json::erro('Sem acesso ao planejamento corporativo.', 403);
            }
            return $plan;
        }
        $escopo = self::escopoNegocios($u);
        if ($escopo !== null && !in_array((int)$plan['negocio_id'], $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        return $plan;
    }

    /** Edição: LEITURA nunca edita; GESTOR edita apenas seus negócios. */
    public static function exigirEdicaoPlanejamento(int $planejamentoId): array
    {
        $u = self::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $plan = self::exigirAcessoPlanejamento($planejamentoId);
        // Metade da marcação do pulso (`App\Core\Versao`): este é o portão por
        // onde passa toda escrita de conteúdo do plano, então quem chega aqui
        // está prestes a gravar. Marcar o ALVO não grava nada — quem diz que
        // houve mudança é `Database::executar`, e o contador só sobe quando as
        // duas coisas acontecem na mesma requisição.
        Versao::alvo($planejamentoId);
        return $plan;
    }

    /**
     * Escrever a própria ideia na Coleta. Existe como método com nome próprio
     * porque é o único ponto do sistema em que a regra de escrita pode vir a
     * diferir de `exigirEdicaoPlanejamento` — o brainstorm quer ser amplo sem
     * inflar perfis de escrita.
     *
     * Enquanto a diretoria não decidir se o perfil LEITURA pode registrar
     * ideia, a regra é a mesma da edição. Liberar depois é trocar a chamada
     * abaixo, sem afrouxar `exigirEdicaoPlanejamento` — que continua barrando
     * LEITURA em todas as outras rotas de escrita.
     *
     * Devolve o USUÁRIO (autor da ideia), não o planejamento: quem chama grava
     * `autor_id`/`triado_por` com esse id. `exigirEdicaoPlanejamento` retorna a
     * linha do planejamento; usá-la como usuário gravava o id do planejamento em
     * `autor_id` e estourava a FK para `usuario` (coincidia só quando os ids
     * batiam por acaso).
     */
    public static function exigirRespostaColeta(int $planejamentoId): array
    {
        self::exigirEdicaoPlanejamento($planejamentoId); // autoriza (perfil + escopo)
        return self::exigirLogin();                      // devolve quem escreve
    }

    /** Triagem da Coleta: encaminhar ou descartar ideia é ato de curadoria. */
    public static function exigirTriagemColeta(int $planejamentoId): array
    {
        self::exigirEdicaoPlanejamento($planejamentoId);
        return self::exigirLogin();
    }

    public static function tokenCsrf(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function validarCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            Json::erro('Token CSRF inválido.', 419);
        }
    }
}

<?php

namespace App\Core;

class Auth
{
    /** Perfis com visão total (todos os negócios + planejamento corporativo). */
    public const VE_TUDO = ['ADMIN', 'CONTROLADORIA', 'DIRECAO'];

    /** Perfis que administram cadastros (negócios, ciclos, drivers, usuários). */
    public const ADMINISTRA = ['ADMIN', 'CONTROLADORIA'];

    public static function usuario(): ?array
    {
        return $_SESSION['usuario'] ?? null;
    }

    public static function exigirLogin(): array
    {
        $u = self::usuario();
        if (!$u) {
            Json::erro('Não autenticado.', 401);
        }
        return $u;
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
        return self::exigirAcessoPlanejamento($planejamentoId);
    }

    /**
     * Escrever a própria ideia na Coleta. Existe como método com nome próprio
     * porque é o único ponto do sistema em que a regra de escrita pode vir a
     * diferir de `exigirEdicaoPlanejamento` — o brainstorm quer ser amplo sem
     * inflar perfis de escrita.
     *
     * Enquanto a diretoria não decidir se o perfil LEITURA pode registrar
     * ideia, a regra é a mesma da edição. Liberar depois é trocar a linha
     * abaixo, sem afrouxar `exigirEdicaoPlanejamento` — que continua barrando
     * LEITURA em todas as outras rotas de escrita.
     */
    public static function exigirRespostaColeta(int $planejamentoId): array
    {
        return self::exigirEdicaoPlanejamento($planejamentoId);
    }

    /** Triagem da Coleta: encaminhar ou descartar ideia é ato de curadoria. */
    public static function exigirTriagemColeta(int $planejamentoId): array
    {
        return self::exigirEdicaoPlanejamento($planejamentoId);
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

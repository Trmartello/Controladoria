<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

class UsuarioController
{
    private const SENHA_MINIMA = 8;

    /**
     * Como aparece, na tela, o registro cujo autor saiu do cadastro.
     *
     * Uma fonte só para as quatro consultas que mostram autoria (comentário,
     * ata, sessão da sala e o relatório de status). Escrita em cada uma, a
     * primeira revisão da redação deixaria a mesma ausência com quatro nomes
     * diferentes, e o leitor procuraria a diferença entre elas.
     *
     * Não confundir com o `— sem responsável —` de `Avisos::carteira`: aquele
     * agrupa ação SEM DONO num relatório de cobrança (trabalho que não vai ser
     * cobrado de ninguém); este diz que quem escreveu não está mais no cadastro.
     */
    public const SEM_USUARIO = 'Sem usuário';

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
            // O ✕ da tela só aparece em quem PODE ser excluído — mesma regra do
            // cadastro de negócios. Aqui "não pode" é só o que transferência
            // nenhuma resolve (você mesmo, o último administrador ativo); ter
            // ações ou registros não impede nada, é o que a tela vai perguntar.
            // A decisão mora no servidor, que é quem consegue contar os admins,
            // e o excluir() reconfere as mesmas guardas — o botão escondido é
            // conforto de tela, nunca autorização.
            $u['excluivel'] = (int)empty($this->impedimentos($u));
        }
        Json::ok($usuarios);
    }

    /**
     * Nomes sugeridos como responsável por projetos e ações do planejamento:
     * quem enxerga tudo mais os usuários ligados ao negócio. Só devolve nomes
     * (sem e-mail nem perfil) e exige acesso ao planejamento.
     */
    public function responsaveis(): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        $plan = Auth::exigirAcessoPlanejamento($planId);
        $negocioId = $plan['negocio_id'] !== null ? (int)$plan['negocio_id'] : 0;
        $nomes = Database::todos(
            "SELECT DISTINCT u.nome
             FROM usuario u
             LEFT JOIN usuario_negocio un ON un.usuario_id = u.id
             WHERE u.ativo = 1
               AND (u.perfil IN ('ADMIN', 'CONTROLADORIA', 'DIRECAO') OR un.negocio_id = ?)
             ORDER BY u.nome",
            [$negocioId]
        );
        Json::ok(array_map(fn($l) => $l['nome'], $nomes));
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
            // As MESMAS razões que impedem excluir valem para rebaixar ou
            // desativar: o último ADMIN ativo que vira CONTROLADORIA (ou é
            // desativado) deixa o sistema sem quem gere usuários, e quem se
            // desativa derruba a própria sessão no meio do gesto.
            $alvo = Database::um('SELECT * FROM usuario WHERE id = ?', [$id]);
            if (!$alvo) {
                Json::erro('Usuário não encontrado.', 404);
            }
            $souEu = (int)$alvo['id'] === (int)(Auth::usuario()['id'] ?? 0);
            $eraAdminAtivo = $alvo['perfil'] === 'ADMIN' && (int)$alvo['ativo'] === 1;
            $deixaDeSerAdmin = $perfil !== 'ADMIN' || $ativo === 0;
            if ($souEu && ($ativo === 0 || ($eraAdminAtivo && $deixaDeSerAdmin))) {
                Json::erro('Você não pode desativar nem rebaixar a própria conta. '
                    . 'Peça a outro administrador.', 409);
            }
            if ($eraAdminAtivo && $deixaDeSerAdmin) {
                $outros = (int)Database::um(
                    "SELECT COUNT(*) AS n FROM usuario WHERE perfil = 'ADMIN' AND ativo = 1 AND id <> ?",
                    [$id]
                )['n'];
                if ($outros === 0) {
                    Json::erro("«{$alvo['nome']}» é o último administrador ativo. Promova outra pessoa a "
                        . 'Admin antes de rebaixá-lo ou desativá-lo, senão ninguém mais consegue gerir usuários.', 409);
                }
            }
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

    /**
     * Tudo que aponta para uma PESSOA, e o que fazer com cada coisa quando ela
     * sai do cadastro. Uma lista só, usada pelas TRÊS leituras — o que a tela
     * mostra antes de perguntar (`vinculos`), o que a exclusão transfere e a
     * conferência final. Escritas separadas, uma coluna nova entraria em duas
     * das três e a terceira só apareceria como erro de chave estrangeira, no
     * dia em que alguém tentasse excluir alguém.
     *
     * Os grupos existem porque as duas naturezas se leem diferente na tela:
     *
     * - **carteira**: trabalho que alguém precisa assumir. É daqui que saem as
     *   cobranças por e-mail e o filtro de "minhas ações" — deixar sem dono tem
     *   consequência no dia seguinte, e é o que a tela precisa dizer alto.
     * - **autoria**: o que a pessoa escreveu. Não é trabalho pendente, é
     *   registro; some da tela só o nome, nunca o texto.
     *
     * `rotulo` está no singular e no plural porque a tela conta ("3 ações",
     * "1 ação") — montar isso com um `s` no fim erra em «comentário».
     */
    private const VINCULOS = [
        ['grupo' => 'carteira', 'tabela' => 'desdobramento', 'coluna' => 'quem_usuario_id',
            'rotulo' => ['ação do plano', 'ações do plano']],
        ['grupo' => 'carteira', 'tabela' => 'fator', 'coluna' => 'acao_por',
            'rotulo' => ['fator aguardando ação', 'fatores aguardando ação']],
        ['grupo' => 'carteira', 'tabela' => 'swot_cruzamento', 'coluna' => 'acao_por',
            'rotulo' => ['cruzamento aguardando ação', 'cruzamentos aguardando ação']],
        // A quarta origem da mesma fila: sem esta linha o item de cenário
        // encaminhado ao plano perdia o dono em silêncio (FK SET NULL).
        ['grupo' => 'carteira', 'tabela' => 'cenario_item', 'coluna' => 'acao_por',
            'rotulo' => ['item de cenário aguardando ação', 'itens de cenário aguardando ação']],
        ['grupo' => 'carteira', 'tabela' => 'negocio', 'coluna' => 'gestor_id',
            'rotulo' => ['negócio sob gestão', 'negócios sob gestão']],
        ['grupo' => 'autoria', 'tabela' => 'comentario', 'coluna' => 'autor_id',
            'rotulo' => ['comentário', 'comentários']],
        ['grupo' => 'autoria', 'tabela' => 'reuniao', 'coluna' => 'autor_id',
            'rotulo' => ['ata de reunião', 'atas de reunião']],
        ['grupo' => 'autoria', 'tabela' => 'coleta_item', 'coluna' => 'autor_id',
            'rotulo' => ['ideia registrada', 'ideias registradas']],
        ['grupo' => 'autoria', 'tabela' => 'coleta_item', 'coluna' => 'triado_por',
            'rotulo' => ['ideia triada', 'ideias triadas']],
        ['grupo' => 'autoria', 'tabela' => 'coleta_item', 'coluna' => 'unido_por',
            'rotulo' => ['resposta unificada', 'respostas unificadas']],
        ['grupo' => 'autoria', 'tabela' => 'coleta_rodada', 'coluna' => 'criado_por',
            'rotulo' => ['sessão da sala', 'sessões da sala']],
        ['grupo' => 'autoria', 'tabela' => 'swot_cruzamento', 'coluna' => 'criado_por',
            'rotulo' => ['cruzamento redigido', 'cruzamentos redigidos']],
        ['grupo' => 'autoria', 'tabela' => 'diario_bordo', 'coluna' => 'autor_id',
            'rotulo' => ['registro do diário', 'registros do diário']],
    ];

    /**
     * O que sai do cadastro JUNTO com a pessoa, sem escolha e sem transferência.
     *
     * `usuario_negocio` é o escopo dela — quais unidades enxergava. Passar isso
     * adiante daria a quem recebe um acesso que ninguém concedeu, e é a única
     * coisa nesta rota que muda o que outra pessoa PODE VER.
     * `envio_email` é o livro de "este aviso já foi mandado para fulano": sem o
     * fulano, a linha não quer dizer nada.
     *
     * As duas já são `ON DELETE CASCADE` no banco; estão aqui para a conferência
     * final saber que são esperadas, e para a tela poder dizer o que perde.
     */
    private const DESCARTADOS = ['usuario_negocio', 'envio_email'];

    /** Quantas linhas de cada vínculo apontam para esta pessoa (só as > 0). */
    private function contarVinculos(int $id): array
    {
        $achados = [];
        foreach (self::VINCULOS as $v) {
            $n = (int)Database::um(
                "SELECT COUNT(*) AS n FROM {$v['tabela']} WHERE {$v['coluna']} = ?",
                [$id]
            )['n'];
            if ($n > 0) {
                $achados[] = [
                    'grupo'  => $v['grupo'],
                    'chave'  => "{$v['tabela']}.{$v['coluna']}",
                    'rotulo' => $n === 1 ? $v['rotulo'][0] : $v['rotulo'][1],
                    'total'  => $n,
                ];
            }
        }
        return $achados;
    }

    /**
     * As razões pelas quais esta pessoa não pode ser excluída de jeito nenhum —
     * as que nenhuma transferência resolve. Devolve [] quando dá para excluir.
     *
     * São duas, e as duas evitam um sistema sem saída:
     * - **você mesmo**: excluir a própria conta derruba a sessão no meio do
     *   gesto, e o que sobra é uma tela que não recarrega e um cadastro em que
     *   já não se pode entrar para desfazer;
     * - **o último ADMIN ativo**: sem nenhum administrador não há quem crie
     *   usuário, quem edite perfil nem quem chegue de novo a esta tela. O
     *   conserto seria no banco, à mão.
     *
     * Perfil rebaixado e usuário desativado contam como ausência: quem está
     * inativo não consegue entrar, então ele não é o administrador que sobrou.
     */
    private function impedimentos(array $alvo): array
    {
        $eu = Auth::usuario();
        $razoes = [];
        if ((int)$alvo['id'] === (int)($eu['id'] ?? 0)) {
            $razoes[] = 'Você não pode excluir a própria conta. '
                . 'Peça a outro administrador — ou desative o acesso, se a intenção era sair.';
        }
        if ($alvo['perfil'] === 'ADMIN' && (int)$alvo['ativo'] === 1) {
            $outros = (int)Database::um(
                "SELECT COUNT(*) AS n FROM usuario WHERE perfil = 'ADMIN' AND ativo = 1 AND id <> ?",
                [$alvo['id']]
            )['n'];
            if ($outros === 0) {
                $razoes[] = "«{$alvo['nome']}» é o último administrador ativo. "
                    . 'Promova outra pessoa a Admin antes de excluir, senão ninguém mais '
                    . 'consegue gerir usuários.';
            }
        }
        return $razoes;
    }

    /**
     * O que esta pessoa segura hoje, para a tela poder PERGUNTAR antes de
     * excluir em vez de descobrir no erro.
     *
     * Devolve também `destinos`: quem pode receber. A lista sai daqui e não do
     * `/api/usuarios` inteiro porque ela tem regra própria — fora o próprio
     * excluído e fora quem está inativo, que não recebe cobrança nenhuma e
     * transformaria a transferência num sumiço com outro nome.
     */
    public function vinculos(int $id): void
    {
        Auth::exigirAdministrador();
        $alvo = Database::um('SELECT id, nome, email, perfil, ativo FROM usuario WHERE id = ?', [$id]);
        if (!$alvo) {
            Json::erro('Usuário não encontrado.', 404);
        }
        $impedimentos = $this->impedimentos($alvo);
        $vinculos = $this->contarVinculos($id);
        Json::ok([
            'usuario'      => ['id' => (int)$alvo['id'], 'nome' => $alvo['nome'], 'email' => $alvo['email']],
            'impedimentos' => $impedimentos,
            'pode_excluir' => empty($impedimentos),
            'vinculos'     => $vinculos,
            'carteira'     => array_sum(array_map(
                fn($v) => $v['grupo'] === 'carteira' ? $v['total'] : 0,
                $vinculos
            )),
            'destinos'     => array_map(
                fn($l) => ['valor' => (int)$l['id'], 'texto' => $l['nome'], 'descricao' => $l['email']],
                Database::todos(
                    'SELECT id, nome, email FROM usuario WHERE ativo = 1 AND id <> ? ORDER BY nome',
                    [$id]
                )
            ),
        ]);
    }

    /**
     * Tira a pessoa do cadastro, depois de dar destino a tudo que era dela.
     *
     * O corpo traz `transferir_para` (id de quem recebe) **ou**
     * `sem_responsavel: true`. Um dos dois é obrigatório sempre que houver
     * vínculo: sem essa exigência, o caminho mais curto — mandar só o id na URL
     * — seria justamente o que apaga o dono de todas as ações em silêncio, e
     * "não escolhi nada" viraria uma escolha por omissão. Com a pessoa limpa
     * (nada apontando para ela) nenhum dos dois é pedido: não há o que decidir.
     *
     * O que a transferência faz com cada coluna está em VINCULOS. Duas notas
     * sobre o que ela NÃO faz:
     *
     * - **`desdobramento.quem` anda junto com `quem_usuario_id`.** O nome é
     *   texto livre, gravado ao lado do id desde sempre; atualizar só o id
     *   deixaria o cartão da ação exibindo o nome de quem já saiu enquanto a
     *   cobrança ia para outra pessoa — duas verdades na mesma linha.
     * - **A autoria transferida passa a ser de quem recebe**, e isso reescreve
     *   o passado: uma ata passa a constar como escrita por quem não estava lá.
     *   Foi decisão de quem pediu a funcionalidade, e a alternativa está a um
     *   clique — «deixar sem responsável» preserva o registro e marca a origem
     *   como «Sem usuário».
     *
     * Nada disso roda em transação: o repositório não usa `beginTransaction` e
     * `Json::erro()` encerra a execução. Por isso a ordem é UPDATE (dar
     * destino) → conferência → DELETE, nunca o contrário: interrompida no meio,
     * a pior sobra é uma pessoa sem nada apontando para ela, que a própria tela
     * mostra e deixa excluir de novo. Ao contrário, ficaria a linha apagada com
     * registros pendurados nela.
     */
    public function excluir(int $id): void
    {
        Auth::exigirAdministrador();
        $alvo = Database::um('SELECT id, nome, perfil, ativo FROM usuario WHERE id = ?', [$id]);
        if (!$alvo) {
            Json::erro('Usuário não encontrado.', 404);
        }
        foreach ($this->impedimentos($alvo) as $razao) {
            Json::erro($razao, 400, 'EXCLUSAO_IMPEDIDA');
        }

        $d = Json::corpo();
        $paraId = (int)($d['transferir_para'] ?? 0);
        $semResponsavel = !empty($d['sem_responsavel']);
        $vinculos = $this->contarVinculos($id);

        $para = null;
        if ($paraId) {
            if ($paraId === $id) {
                Json::erro('Escolha outra pessoa para receber: não dá para transferir para quem está saindo.');
            }
            $para = Database::um('SELECT id, nome, ativo FROM usuario WHERE id = ?', [$paraId]);
            if (!$para) {
                Json::erro('A pessoa escolhida para receber não existe.', 404);
            }
            // Inativo não entra: ele não recebe cobrança nenhuma, e transferir
            // para lá seria o mesmo sumiço de "sem responsável", só que com um
            // nome na tela dizendo que alguém está cuidando disso.
            if ((int)$para['ativo'] !== 1) {
                Json::erro("«{$para['nome']}» está inativo e não receberia as cobranças. "
                    . 'Escolha alguém ativo, ou deixe sem responsável.');
            }
        } elseif ($vinculos && !$semResponsavel) {
            Json::erro(
                "«{$alvo['nome']}» ainda tem registros no sistema. Indique quem assume — "
                . 'ou confirme que eles ficam sem responsável.',
                409,
                'DESTINO_OBRIGATORIO'
            );
        }

        // O nome escrito na ação acompanha o id — ver a nota do cabeçalho. Sem
        // destino ele fica VAZIO, e não com o nome de quem saiu: a tela lê o
        // vazio como «Sem usuário», e o relatório de carteira já agrupa a ação
        // sem dono numa linha própria.
        //
        // Roda ANTES do laço, enquanto `quem_usuario_id` ainda aponta para quem
        // sai. Depois da transferência essas linhas já são de quem recebeu, e
        // não haveria como distingui-las das ações que ele já tinha — o
        // "sem responsável" então não acharia linha nenhuma para limpar.
        Database::executar(
            'UPDATE desdobramento SET quem = ? WHERE quem_usuario_id = ?',
            [$para['nome'] ?? '', $id]
        );
        foreach (self::VINCULOS as $v) {
            Database::executar(
                "UPDATE {$v['tabela']} SET {$v['coluna']} = ? WHERE {$v['coluna']} = ?",
                [$para['id'] ?? null, $id]
            );
        }

        $sobras = $this->referenciasRestantes($id);
        if ($sobras) {
            Json::erro(
                "Não dá para excluir «{$alvo['nome']}» com segurança: ainda há registros ligados a "
                . 'ela em ' . implode(', ', $sobras) . '. '
                . 'Isso é defeito do sistema, não do cadastro — avise quem mantém o Controladoria.',
                500,
                'VINCULO_NAO_TRATADO'
            );
        }

        Database::executar('DELETE FROM usuario WHERE id = ?', [$id]);
        Json::ok([
            'excluido'    => $id,
            'nome'        => $alvo['nome'],
            'transferido' => $para['nome'] ?? null,
            'vinculos'    => $vinculos,
        ]);
    }

    /**
     * Conferência final antes do DELETE: alguma coluna ainda aponta para esta
     * pessoa?
     *
     * A lista de colunas sai do **information_schema**, não da constante
     * VINCULOS, e é essa a graça: ela enxerga o que o código ainda não sabe.
     * Uma tabela nova criada daqui a um ano com `FOREIGN KEY (autor_id)
     * REFERENCES usuario(id)` entra aqui sozinha, e quem esquecer de somá-la a
     * VINCULOS recebe uma recusa nomeando a tabela — em vez de um erro cru de
     * chave estrangeira, ou pior, do nulo silencioso que a coluna sem chave
     * produzia. Conferir contra a própria constante não provaria nada: ela é
     * justamente o que pode estar incompleto.
     *
     * `ON DELETE CASCADE`/`SET NULL` ficam de fora porque o banco resolve as
     * duas sozinho — a primeira leva a linha junto (é o caso de DESCARTADOS), a
     * segunda solta o vínculo.
     */
    private function referenciasRestantes(int $id): array
    {
        $colunas = Database::todos(
            "SELECT k.TABLE_NAME AS tabela, k.COLUMN_NAME AS coluna
               FROM information_schema.KEY_COLUMN_USAGE k
               JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                 ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
                AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
              WHERE k.TABLE_SCHEMA = DATABASE()
                AND k.REFERENCED_TABLE_NAME = 'usuario'
                AND k.REFERENCED_COLUMN_NAME = 'id'
                AND r.DELETE_RULE = 'RESTRICT'"
        );
        $sobras = [];
        foreach ($colunas as $c) {
            if (in_array($c['tabela'], self::DESCARTADOS, true)) {
                continue;
            }
            $n = (int)Database::um(
                "SELECT COUNT(*) AS n FROM `{$c['tabela']}` WHERE `{$c['coluna']}` = ?",
                [$id]
            )['n'];
            if ($n > 0) {
                $sobras[] = "{$c['tabela']}.{$c['coluna']} ({$n})";
            }
        }
        return $sobras;
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

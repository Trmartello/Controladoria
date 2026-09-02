<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;

/**
 * Comentários de acompanhamento, com anexos — sucedem o diário de bordo.
 *
 * O registro continua datado e nunca sobrescrito; o que muda é que agora ele
 * carrega arquivo: a foto da obra, a proposta em PDF, a planilha do orçamento.
 *
 * O arquivo mora no BANCO (`comentario_anexo.conteudo`, LONGBLOB) e não em
 * disco: o contêiner do Railway é efêmero e uma pasta de upload some no deploy
 * seguinte, levando junto o anexo de todo mundo. Não há Composer aqui, então
 * SDK de armazenamento externo também está fora.
 *
 * O envio é `multipart/form-data` (e não JSON com base64): base64 infla 33% o
 * corpo e obrigaria a carregar o arquivo inteiro em memória duas vezes. O CSRF
 * continua valendo — ele é conferido pelo header `X-CSRF-Token`, que o
 * `App.api` manda em qualquer tipo de corpo.
 */
class ComentarioController
{
    /** Teto por arquivo. Combina com `upload_max_filesize` do Dockerfile. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Teto por comentário: o `post_max_size` da imagem cobre este número. */
    private const MAX_ARQUIVOS = 5;

    /**
     * Lista branca de tipos, por EXTENSÃO — e o `Content-Type` servido depois
     * sai daqui, nunca do que o navegador declarou no envio. Sem a lista, um
     * `.html` (ou um `.svg`, que executa script) subiria e seria servido do
     * mesmo domínio da sessão: XSS com cookie de sessão junto.
     */
    private const TIPOS = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv'  => 'text/csv',
        'txt'  => 'text/plain',
    ];

    /** Quais são desenhados como miniatura de imagem na tela. */
    private const IMAGENS = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function listar(): void
    {
        $planId  = (int)($_GET['planejamento_id'] ?? 0);
        $refTipo = $_GET['ref_tipo'] ?? '';
        $refId   = (int)($_GET['ref_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $this->validarRef($refTipo, $refId, $planId);

        $comentarios = Database::todos(
            'SELECT c.id, c.texto, c.criado_em, COALESCE(u.nome, ?) AS autor, c.autor_id
               FROM comentario c LEFT JOIN usuario u ON u.id = c.autor_id
              WHERE c.ref_tipo = ? AND c.ref_id = ?
              ORDER BY c.criado_em DESC, c.id DESC',
            [UsuarioController::SEM_USUARIO, $refTipo, $refId]
        );
        if (!$comentarios) {
            Json::ok([]);
        }

        // Os anexos vêm numa consulta só, e SEM o `conteudo`: arrastar os BLOBs
        // aqui faria a abertura do cartão baixar dezenas de megabytes que a
        // tela não usa — a miniatura busca cada arquivo pela rota própria.
        $ids = array_column($comentarios, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $anexos = Database::todos(
            "SELECT id, comentario_id, nome, tipo, tamanho
               FROM comentario_anexo WHERE comentario_id IN ({$marcas}) ORDER BY id",
            $ids
        );
        $porComentario = [];
        foreach ($anexos as $a) {
            $a['imagem'] = in_array($a['tipo'], self::IMAGENS, true);
            $porComentario[(int)$a['comentario_id']][] = $a;
        }
        foreach ($comentarios as &$c) {
            $c['anexos'] = $porComentario[(int)$c['id']] ?? [];
        }
        Json::ok($comentarios);
    }

    /**
     * Cria o comentário e guarda os anexos. Sem transação (o repositório não
     * usa `beginTransaction` e `Json::erro` encerra a execução), então TUDO é
     * validado antes do primeiro INSERT: um arquivo recusado no meio deixaria
     * um comentário pela metade, com parte dos anexos.
     */
    public function criar(): void
    {
        // Corpo multipart: os campos chegam em $_POST, não em Json::corpo()
        $planId = (int)($_POST['planejamento_id'] ?? 0);
        $u = Auth::exigirLogin();
        Auth::exigirEdicaoPlanejamento($planId);

        $refTipo = $_POST['ref_tipo'] ?? '';
        $refId   = (int)($_POST['ref_id'] ?? 0);
        $this->validarRef($refTipo, $refId, $planId);

        $texto = trim((string)($_POST['texto'] ?? ''));
        $arquivos = $this->arquivosEnviados();
        // Comentário vazio SEM anexo não é comentário; com anexo, o arquivo é a
        // mensagem (a foto da obra costuma dispensar legenda).
        if ($texto === '' && !$arquivos) {
            Json::erro('Escreva o comentário ou anexe um arquivo.');
        }
        if (count($arquivos) > self::MAX_ARQUIVOS) {
            Json::erro('Máximo de ' . self::MAX_ARQUIVOS . ' arquivos por comentário.');
        }
        $validados = array_map(fn ($a) => $this->validarArquivo($a), $arquivos);

        $id = (int)Database::executar(
            'INSERT INTO comentario (ref_tipo, ref_id, autor_id, texto) VALUES (?, ?, ?, ?)',
            [$refTipo, $refId, (int)$u['id'], $texto]
        );
        foreach ($validados as $a) {
            Database::executar(
                'INSERT INTO comentario_anexo (comentario_id, nome, tipo, tamanho, conteudo)
                 VALUES (?, ?, ?, ?, ?)',
                [$id, $a['nome'], $a['tipo'], $a['tamanho'], $a['conteudo']]
            );
        }
        Json::ok(['id' => $id, 'anexos' => count($validados)]);
    }

    /**
     * Apaga o comentário (e os anexos, por ON DELETE CASCADE). Só o autor ou um
     * ADMIN: comentário de acompanhamento é registro de alguém, e quem edita o
     * planejamento não herda o direito de apagar o registro dos outros.
     */
    public function excluir(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirLogin();
        Auth::exigirEdicaoPlanejamento($planId);

        $c = Database::um('SELECT * FROM comentario WHERE id = ?', [$id]);
        if (!$c) {
            Json::erro('Comentário não encontrado.', 404);
        }
        // A referência precisa ser DESTE planejamento: sem a conferência, o id
        // do comentário de outro negócio passaria com um planejamento onde o
        // usuário edita.
        $this->validarRef((string)$c['ref_tipo'], (int)$c['ref_id'], $planId);
        if ((int)$c['autor_id'] !== (int)$u['id'] && ($u['perfil'] ?? '') !== 'ADMIN') {
            Json::erro('Só o autor do comentário (ou um administrador) pode apagá-lo.', 403);
        }
        Database::executar('DELETE FROM comentario WHERE id = ?', [$id]);
        Json::ok();
    }

    /**
     * Apaga UM anexo, sem levar o comentário junto. Antes desta rota, tirar o
     * arquivo errado obrigava a apagar o comentário inteiro e reescrevê-lo com
     * os anexos certos — o texto do registro se perdia por causa de um arquivo.
     *
     * A permissão é a MESMA de apagar o comentário (autor ou ADMIN): o anexo é
     * parte do registro de alguém, e quem edita o planejamento não herda o
     * direito de mexer no registro dos outros.
     *
     * Quando o anexo é o último e o comentário não tem texto, o comentário sai
     * junto — é a mesma regra que `criar` aplica na entrada: comentário sem
     * texto e sem arquivo não é comentário. A resposta diz o que aconteceu
     * (`comentario_excluido`) para a tela não continuar mostrando um registro
     * que deixou de existir.
     */
    public function excluirAnexo(int $id): void
    {
        $d = Json::corpo();
        $planId = (int)($d['planejamento_id'] ?? 0);
        $u = Auth::exigirLogin();
        Auth::exigirEdicaoPlanejamento($planId);

        $a = Database::um(
            'SELECT a.id, a.comentario_id, c.ref_tipo, c.ref_id, c.autor_id, c.texto
               FROM comentario_anexo a JOIN comentario c ON c.id = a.comentario_id
              WHERE a.id = ?',
            [$id]
        );
        if (!$a) {
            Json::erro('Anexo não encontrado.', 404);
        }
        // Mesma conferência de `excluir`: sem ela, o id de um anexo de outro
        // negócio passaria junto com um planejamento onde o usuário edita.
        $this->validarRef((string)$a['ref_tipo'], (int)$a['ref_id'], $planId);
        if ((int)$a['autor_id'] !== (int)$u['id'] && ($u['perfil'] ?? '') !== 'ADMIN') {
            Json::erro('Só o autor do comentário (ou um administrador) pode apagar o anexo.', 403);
        }

        $restantes = (int)(Database::um(
            'SELECT COUNT(*) n FROM comentario_anexo WHERE comentario_id = ? AND id <> ?',
            [(int)$a['comentario_id'], $id]
        )['n'] ?? 0);

        if ($restantes === 0 && trim((string)$a['texto']) === '') {
            // O DELETE do comentário leva o anexo por ON DELETE CASCADE.
            Database::executar('DELETE FROM comentario WHERE id = ?', [(int)$a['comentario_id']]);
            Json::ok(['comentario_excluido' => true]);
        }
        Database::executar('DELETE FROM comentario_anexo WHERE id = ?', [$id]);
        Json::ok(['comentario_excluido' => false]);
    }

    /**
     * Entrega o arquivo. Não é rota de API: devolve bytes, e por isso escreve
     * os próprios cabeçalhos e encerra.
     *
     * Três guardas que não podem ser afrouxadas: o `Content-Type` sai da LISTA
     * BRANCA (nunca do que o navegador declarou), `nosniff` impede o navegador
     * de adivinhar outro, e tudo que não é imagem desce como ANEXO — um
     * documento aberto no mesmo domínio da sessão é XSS esperando acontecer.
     * A CSP `default-src 'none'` fecha o resto.
     */
    public function baixar(int $id): void
    {
        $planId = (int)($_GET['planejamento_id'] ?? 0);
        Auth::exigirAcessoPlanejamento($planId);
        $a = Database::um(
            'SELECT a.*, c.ref_tipo, c.ref_id FROM comentario_anexo a
               JOIN comentario c ON c.id = a.comentario_id
              WHERE a.id = ?',
            [$id]
        );
        if (!$a) {
            Json::erro('Anexo não encontrado.', 404);
        }
        $this->validarRef((string)$a['ref_tipo'], (int)$a['ref_id'], $planId);

        $tipo = in_array($a['tipo'], self::TIPOS, true) ? $a['tipo'] : 'application/octet-stream';
        $inline = in_array($tipo, self::IMAGENS, true);
        // O nome vai entre aspas e sem caractere de controle: ele entra num
        // cabeçalho, e uma quebra de linha ali é injeção de cabeçalho.
        $nome = preg_replace('/[^\w .\-()]+/u', '_', (string)$a['nome']);

        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $tipo);
        header('Content-Length: ' . strlen((string)$a['conteudo']));
        header(($inline ? 'Content-Disposition: inline' : 'Content-Disposition: attachment')
            . '; filename="' . $nome . '"');
        // O anexo não muda depois de gravado; a rota é autenticada, então o
        // cache é PRIVADO — em cache compartilhado ele vazaria para outra sessão
        header('Cache-Control: private, max-age=86400');
        echo $a['conteudo'];
        exit;
    }

    /**
     * Normaliza `$_FILES['arquivos']` (que chega como colunas paralelas) numa
     * lista, descartando os campos vazios que o navegador manda quando o
     * usuário abre o seletor e desiste.
     */
    private function arquivosEnviados(): array
    {
        $f = $_FILES['arquivos'] ?? null;
        if (!$f || !isset($f['name'])) {
            return [];
        }
        $nomes = (array)$f['name'];
        $lista = [];
        foreach (array_keys($nomes) as $i) {
            if ((int)$f['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $lista[] = [
                'nome'    => (string)$f['name'][$i],
                'tmp'     => (string)$f['tmp_name'][$i],
                'erro'    => (int)$f['error'][$i],
                'tamanho' => (int)$f['size'][$i],
            ];
        }
        return $lista;
    }

    /** Devolve o anexo pronto para gravar, ou encerra com a recusa. */
    private function validarArquivo(array $a): array
    {
        if ($a['erro'] === UPLOAD_ERR_INI_SIZE || $a['erro'] === UPLOAD_ERR_FORM_SIZE) {
            Json::erro("O arquivo “{$a['nome']}” passa do limite de 5 MB.");
        }
        if ($a['erro'] !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp'])) {
            Json::erro("Falha ao receber o arquivo “{$a['nome']}”.");
        }
        if ($a['tamanho'] <= 0 || $a['tamanho'] > self::MAX_BYTES) {
            Json::erro("O arquivo “{$a['nome']}” passa do limite de 5 MB.");
        }
        $ext = strtolower((string)pathinfo($a['nome'], PATHINFO_EXTENSION));
        if (!isset(self::TIPOS[$ext])) {
            Json::erro("Tipo de arquivo não aceito em “{$a['nome']}”. "
                . 'Envie imagem (JPG, PNG, GIF, WEBP) ou documento (PDF, Word, Excel, PowerPoint, CSV, TXT).');
        }
        $tipo = self::TIPOS[$ext];
        // Imagem tem de ser imagem de verdade: sem esta conferência, um script
        // renomeado para .png seria servido com `Content-Type: image/png` e
        // exibido `inline`. `getimagesize` lê o cabeçalho do arquivo, não a
        // extensão, e não depende da extensão GD do PHP.
        if (in_array($tipo, self::IMAGENS, true) && @getimagesize($a['tmp']) === false) {
            Json::erro("O arquivo “{$a['nome']}” não é uma imagem válida.");
        }
        $conteudo = file_get_contents($a['tmp']);
        if ($conteudo === false) {
            Json::erro("Falha ao ler o arquivo “{$a['nome']}”.");
        }
        return [
            'nome'    => mb_substr($a['nome'], 0, 200),
            'tipo'    => $tipo,
            'tamanho' => strlen($conteudo),
            'conteudo' => $conteudo,
        ];
    }

    /** Garante que a referência pertence ao planejamento informado. */
    private function validarRef(string $refTipo, int $refId, int $planId): void
    {
        $sql = match ($refTipo) {
            'PROJETO'       => 'SELECT id FROM projeto WHERE id = ? AND planejamento_id = ?',
            'DESDOBRAMENTO' => 'SELECT d.id FROM desdobramento d JOIN projeto p ON p.id = d.projeto_id
                                WHERE d.id = ? AND p.planejamento_id = ?',
            'INVESTIMENTO'  => 'SELECT id FROM investimento WHERE id = ? AND planejamento_id = ?',
            'CASCATA'       => 'SELECT id FROM cascata_escolha WHERE id = ? AND planejamento_id = ?',
            default         => null,
        };
        if ($sql === null) {
            Json::erro('Tipo de referência inválido.');
        }
        if (!Database::um($sql, [$refId, $planId])) {
            Json::erro('Item não encontrado neste planejamento.', 404);
        }
    }
}

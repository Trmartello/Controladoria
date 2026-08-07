<?php

namespace App\Core;

class Json
{
    public static function ok(mixed $dados = null, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Resposta de API carrega dado do planejamento: nada de cache em proxy
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * O `codigo` é opcional e serve a quem precisa DECIDIR pelo erro, não só
     * mostrá-lo: a tela reconhece o caso e oferece a saída (o típico é a sala
     * do quiz aberta em outra análise, que vira uma confirmação em vez de um
     * beco). Casar por texto da mensagem seria refém da redação.
     */
    public static function erro(string $mensagem, int $status = 400, ?string $codigo = null): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Resposta de API carrega dado do planejamento: nada de cache em proxy
        header('Cache-Control: no-store');
        $corpo = ['ok' => false, 'erro' => $mensagem];
        if ($codigo !== null) {
            $corpo['codigo'] = $codigo;
        }
        echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** Corpo JSON da requisição como array. */
    public static function corpo(): array
    {
        $bruto = file_get_contents('php://input');
        $dados = json_decode($bruto ?: '[]', true);
        return is_array($dados) ? $dados : [];
    }
}

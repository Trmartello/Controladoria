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

    public static function erro(string $mensagem, int $status = 400): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        // Resposta de API carrega dado do planejamento: nada de cache em proxy
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'erro' => $mensagem], JSON_UNESCAPED_UNICODE);
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

<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Json;
use App\Services\Bloqueio;

/**
 * O cadeado de edição: tomar, renovar, soltar.
 *
 * As três rotas exigem **edição do planejamento**, e não só o login: pedir um
 * cadeado é declarar intenção de gravar, então quem não pode gravar também não
 * pode impedir os outros de gravar. Um perfil LEITURA capaz de travar itens
 * seria uma forma barata de parar a cooperativa.
 *
 * Nenhuma delas responde com erro quando o cadeado é de outro: devolvem o
 * ESTADO (`livre`, `meu`, `usuario`, `restam`), e a tela decide o que dizer.
 * Erro seria a resposta errada — não há nada de errado em pedir um cadeado
 * ocupado; a resposta certa é "é da Maria, faltam 3 minutos".
 */
class BloqueioController
{
    /** Toma ou renova. Devolve o estado do cadeado depois da tentativa. */
    public function tomar(): void
    {
        [$recurso, $id, $planId, $usuarioId] = $this->pedido();
        Json::ok(Bloqueio::tomar($recurso, $id, $planId, $usuarioId));
    }

    /** O "+1 minuto" de quem está com o formulário aberto. */
    public function renovar(): void
    {
        [$recurso, $id, , $usuarioId] = $this->pedido();
        Json::ok(Bloqueio::renovar($recurso, $id, $usuarioId));
    }

    /**
     * Solta ao fechar o formulário.
     *
     * Aceita corpo de `sendBeacon`, que é como o navegador avisa que a aba está
     * sendo fechada — a única chance de soltar o cadeado nesse caminho, e ela
     * não espera resposta. Por isso responde vazio e nunca recusa: um erro aqui
     * não teria quem o lesse.
     */
    public function soltar(): void
    {
        [$recurso, $id, , $usuarioId] = $this->pedido();
        Bloqueio::soltar($recurso, $id, $usuarioId);
        Json::ok();
    }

    /**
     * Lê e confere o pedido. As TRÊS rotas exigem o `planejamento_id`, mesmo
     * as que não o usam depois: é ele que autoriza, e a autorização é a mesma
     * de gravar. A alternativa — autorizar renovar/soltar pela posse do cadeado
     * — deixaria a rota sem escopo nenhum, alcançável por qualquer sessão.
     *
     * @return array{0:string,1:int,2:int,3:int}
     */
    private function pedido(): array
    {
        $d = Json::corpo();
        $recurso = (string)($d['recurso'] ?? '');
        $id = (int)($d['registro_id'] ?? 0);
        $planId = (int)($d['planejamento_id'] ?? 0);
        if (!Bloqueio::recursoValido($recurso) || !$id) {
            Json::erro('Recurso ou registro inválido para bloqueio.');
        }
        // `exigirEdicaoPlanejamento` devolve o PLANO; quem é o usuário vem do
        // login. Pedir cadeado é declarar intenção de gravar, então quem não
        // pode gravar também não pode travar o item para os outros.
        Auth::exigirEdicaoPlanejamento($planId);
        return [$recurso, $id, $planId, (int)Auth::exigirLogin()['id']];
    }
}

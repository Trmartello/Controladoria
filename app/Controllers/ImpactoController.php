<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Json;
use App\Core\Versao;

/**
 * Matriz de Impacto por Negócio — o que o diagnóstico CORPORATIVO faz com cada
 * negócio.
 *
 * É a única leitura transversal que o método promete: sem ela, um fator do
 * PESTEL corporativo morre no PESTEL corporativo, e o gestor abre a reunião sem
 * saber o que o macro diz sobre ele.
 *
 * **As linhas não têm curadoria própria.** São os fatores do plano corporativo
 * com `etapa = 'SWOT'` e categoria OPORTUNIDADE/AMEACA do ano pedido, ordenados
 * pelo score da GUT. A SWOT corporativa já é a lista curada e o GUT já é a
 * priorização — escolher de novo à mão seria uma segunda priorização sem score e
 * sem rastro, divergindo da primeira no dia seguinte.
 *
 * ## Autorização — o ponto que decide este arquivo
 *
 * Nenhum dos dois métodos usa `Auth::exigirAcessoPlanejamento` no plano
 * corporativo: ele devolve 403 a GESTOR, e o gestor é exatamente quem mais
 * precisa da coluna dele.
 *
 * A regra é do **negócio**, não do planejamento, e isso não é um contorno — é o
 * que a linha desta tabela é. A célula não pertence ao plano corporativo; ela
 * pertence à MATRIZ, e apenas cita um fator. Por isso a autorização natural é
 * "você mexe na célula de um negócio que você já mexe", e não "você mexe no
 * plano corporativo" — que seria mesmo indefensável.
 *
 * **Leitura** (decisão do cliente, 2026-09-01 — ver `PLANEJAMENTO-SISTEMA.md` §5):
 * quem vê tudo recebe a grade inteira; os demais perfis recebem **só as colunas
 * dos negócios do escopo**, e de cada fator apenas a **descrição** — nunca o
 * registro inteiro, que carrega o score da GUT, a origem e o encaminhamento ao
 * plano. O que o gestor passa a saber é o que ele ouviria na própria reunião; o
 * que continua fora do alcance dele é a priorização e o julgamento por trás
 * dela.
 *
 * **Escrita**: controladoria (e quem vê tudo) edita qualquer célula; GESTOR
 * edita as dos negócios dele; LEITURA nunca. Um GESTOR gravando aqui é a
 * primeira escrita do sistema numa linha que cita o plano corporativo — e é
 * segura porque o que ele escolhe é o negócio (o dele) e o texto, nunca a
 * linha: o `fator_id` é conferido contra o plano corporativo do ciclo, então
 * ninguém inventa linha nova pela borda.
 */
class ImpactoController
{
    /** Categorias que viram linha: é a SWOT olhando para fora. */
    private const CATEGORIAS = ['OPORTUNIDADE', 'AMEACA'];

    private const SINAIS = ['POSITIVO', 'NEGATIVO'];

    public function listar(): void
    {
        $u = Auth::exigirLogin();
        $cicloId = (int)($_GET['ciclo_id'] ?? 0);
        $ano = (int)($_GET['ano'] ?? 0);
        if (!$cicloId || $ano < 2000 || $ano > 2100) {
            Json::erro('Informe o ciclo e o ano da matriz.');
        }

        // Só LÊ o plano corporativo; nunca o cria. Criar planejamento como
        // efeito colateral de um GET — disparado por um gestor, ainda por cima —
        // é como o sistema ganharia planos fantasmas de ciclos que ninguém abriu.
        $plan = Database::um(
            "SELECT id FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'",
            [$cicloId]
        );
        if (!$plan) {
            Json::ok(['fatores' => [], 'negocios' => [], 'celulas' => [], 'pode_editar' => false]);
        }
        $planId = (int)$plan['id'];

        $escopo = Auth::escopoNegocios($u);
        // Escopo vazio (gestor sem negócio vinculado) não é o mesmo que "todos":
        // sem este desvio o `IN ()` vazio quebraria a consulta, e um `IN` montado
        // com zero marcas devolveria a grade inteira — o oposto do pedido.
        if ($escopo !== null && !$escopo) {
            Json::ok(['fatores' => [], 'negocios' => [], 'celulas' => [], 'pode_editar' => false]);
        }

        $negocios = Database::todos(
            'SELECT id, cod_negocio, nome, CONCAT(cod_negocio, \' - \', nome) AS rotulo
             FROM negocio WHERE ativo = 1'
            . ($escopo === null ? '' : ' AND id IN (' . implode(',', array_fill(0, count($escopo), '?')) . ')')
            . ' ORDER BY CAST(cod_negocio AS UNSIGNED), nome',
            $escopo ?? []
        );

        // A linha traz `score` para ORDENAR e para a tela mostrar de onde veio a
        // prioridade — mas só a quem vê tudo. Para o gestor a ordem chega pronta
        // e o número não: é justamente a priorização que a decisão de acesso
        // manteve fora do alcance dele.
        $fatores = Database::todos(
            "SELECT f.id, f.categoria, f.descricao, g.score
             FROM fator f
             LEFT JOIN gut g ON g.fator_id = f.id
             WHERE f.planejamento_id = ? AND f.etapa = 'SWOT' AND f.ano = ?
               AND f.categoria IN ('" . implode("','", self::CATEGORIAS) . "')
             ORDER BY g.score DESC, f.categoria, f.id",
            [$planId, $ano]
        );
        $veTudo = Auth::veTudo($u);
        if (!$veTudo) {
            foreach ($fatores as &$f) {
                unset($f['score']);
            }
            unset($f);
        }

        $celulas = [];
        if ($fatores && $negocios) {
            $idsF = array_column($fatores, 'id');
            $idsN = array_column($negocios, 'id');
            $celulas = Database::todos(
                'SELECT fator_id, negocio_id, sinal, texto FROM impacto_negocio
                 WHERE fator_id IN (' . implode(',', array_fill(0, count($idsF), '?')) . ')
                   AND negocio_id IN (' . implode(',', array_fill(0, count($idsN), '?')) . ')',
                array_merge($idsF, $idsN)
            );
        }

        Json::ok([
            'fatores' => $fatores,
            'negocios' => $negocios,
            'celulas' => $celulas,
            // A tela não decide quem edita — ela pergunta. Repetir a regra em JS
            // faria o botão aparecer onde o servidor recusa, ou sumir onde ele
            // aceita, e as duas versões seriam descobertas por acidente.
            'pode_editar' => $u['perfil'] !== 'LEITURA',
            've_tudo' => $veTudo,
            // O id do plano CORPORATIVO vai junto para o relógio do "duas telas
            // juntas" saber o que vigiar: esta tela é aberta no contexto de um
            // negócio e o conteúdo dela mora aqui. É só um id — o gestor já
            // sabe que o plano corporativo existe, e nada do conteúdo dele
            // atravessa por esta chave.
            'planejamento_id' => $planId,
        ]);
    }

    /**
     * Grava (ou apaga) uma célula.
     *
     * `sinal` vazio APAGA: a ausência da célula já significa "sem impacto
     * relevante", e um terceiro estado `NEUTRO` seria a mesma informação com
     * dois jeitos de escrevê-la — a grade passaria a ter célula vazia e célula
     * neutra, indistinguíveis para quem lê e diferentes para quem consulta.
     */
    public function salvar(): void
    {
        $d = Json::corpo();
        $u = Auth::exigirLogin();
        if ($u['perfil'] === 'LEITURA') {
            Json::erro('Perfil somente leitura.', 403);
        }
        $cicloId = (int)($d['ciclo_id'] ?? 0);
        $fatorId = (int)($d['fator_id'] ?? 0);
        $negocioId = (int)($d['negocio_id'] ?? 0);
        $sinal = (string)($d['sinal'] ?? '');
        $texto = trim((string)($d['texto'] ?? ''));

        if (!$cicloId || !$fatorId || !$negocioId) {
            Json::erro('Informe o ciclo, o fator e o negócio.');
        }
        if ($sinal !== '' && !in_array($sinal, self::SINAIS, true)) {
            Json::erro('Sinal inválido.');
        }

        // O negócio manda na autorização. GESTOR só mexe no que já é dele — a
        // mesma lista que o seletor de negócio dele já mostra.
        $escopo = Auth::escopoNegocios($u);
        if ($escopo !== null && !in_array($negocioId, $escopo, true)) {
            Json::erro('Sem acesso a este negócio.', 403);
        }
        $negocio = Database::um('SELECT id FROM negocio WHERE id = ? AND ativo = 1', [$negocioId]);
        if (!$negocio) {
            Json::erro('Negócio não encontrado ou inativo.', 404);
        }

        // O fator tem de ser LINHA DESTA MATRIZ: do plano corporativo do ciclo,
        // da SWOT, e de uma das duas categorias que olham para fora. Sem esta
        // conferência, um id qualquer de `fator` viraria linha nova pela borda —
        // inclusive um fator de OUTRO negócio, que ninguém veria na grade e que
        // ficaria pendurado na tabela para sempre.
        $fator = Database::um(
            "SELECT f.id FROM fator f
             JOIN planejamento p ON p.id = f.planejamento_id
             WHERE f.id = ? AND p.ciclo_id = ? AND p.escopo = 'CORPORATIVO'
               AND f.etapa = 'SWOT'
               AND f.categoria IN ('" . implode("','", self::CATEGORIAS) . "')",
            [$fatorId, $cicloId]
        );
        if (!$fator) {
            Json::erro('Este fator não é uma linha da matriz deste ciclo.', 404);
        }

        // A exceção explícita do pulso (`App\Core\Versao`): esta é a única
        // escrita de conteúdo que NÃO passa por `exigirEdicaoPlanejamento` —
        // ela autoriza pelo negócio da célula, não pelo planejamento. Sem esta
        // linha a marcação ficaria sem alvo, e a grade aberta noutro monitor não
        // acompanharia. O plano é o corporativo, que é onde a linha da matriz
        // mora; `$fator` acabou de confirmar que é dele.
        $plan = Database::um(
            "SELECT id FROM planejamento WHERE ciclo_id = ? AND escopo = 'CORPORATIVO'",
            [$cicloId]
        );
        if ($plan) {
            Versao::alvo((int)$plan['id']);
        }

        if ($sinal === '') {
            Database::executar(
                'DELETE FROM impacto_negocio WHERE fator_id = ? AND negocio_id = ?',
                [$fatorId, $negocioId]
            );
            Json::ok(['apagada' => true]);
        }

        // Upsert pela chave única: duas pessoas na mesma célula terminam com uma
        // linha e a última palavra, não com duas opiniões empilhadas.
        Database::executar(
            'INSERT INTO impacto_negocio (fator_id, negocio_id, sinal, texto)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sinal = VALUES(sinal), texto = VALUES(texto)',
            [$fatorId, $negocioId, $sinal, $texto === '' ? null : $texto]
        );
        Json::ok();
    }
}

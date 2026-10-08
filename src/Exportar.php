<?php

namespace Consulta;

class Exportar
{
    /** @param Resultado[] $resultados */
    public static function json(array $resultados): string
    {
        $dados = [];
        foreach ($resultados as $r) {
            $processos = [];
            foreach ($r->processos as $p) {
                $processos[] = $p->paraArray() + ['novo' => isset($r->novos[$p->numero])];
            }
            $dados[] = ['consulta' => $r->consulta, 'empresa' => $r->empresa, 'processos' => $processos, 'erros' => $r->erros];
        }
        return json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** CSV separado por ";" com BOM, para abrir direto no Excel em português. */
    public static function csv(array $resultados): string
    {
        $colunas = ['consulta', 'numero', 'fonte', 'novo', 'classe', 'assunto', 'foro', 'vara', 'distribuicao',
            'situacao', 'valor_acao', 'participacao', 'ultima_movimentacao', 'url'];
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $colunas, ';', '"', '');
        foreach ($resultados as $r) {
            foreach ($r->processos as $p) {
                $ultima = $p->movimentacoes[0] ?? null;
                $linha = $p->paraArray() + [
                    'consulta' => $r->consulta,
                    'novo' => isset($r->novos[$p->numero]) ? 'sim' : '',
                    'ultima_movimentacao' => $ultima ? "{$ultima->data} {$ultima->descricao}" : '',
                ];
                fputcsv($f, array_map(function ($c) use ($linha) {
                    return $linha[$c] ?? '';
                }, $colunas), ';', '"', '');
            }
        }
        rewind($f);
        return stream_get_contents($f);
    }

    /** Relatório em texto simples (linha de comando, e-mail do cron). */
    public static function texto(array $resultados, bool $marcarNovos = true): string
    {
        $linha = str_repeat('=', 78) . "\n";
        $s = '';
        foreach ($resultados as $r) {
            $s .= $linha . $r->consulta . "\n";
            if ($r->empresa) {
                $e = $r->empresa;
                $s .= "  Empresa: {$e['razao_social']} — {$e['situacao']} — {$e['municipio']}/{$e['uf']}\n";
            }
            $s .= '  Processos encontrados: ' . count($r->processos) . "\n";
            foreach ($r->processos as $p) {
                $marca = $marcarNovos && isset($r->novos[$p->numero]) ? ' [NOVO]' : '';
                $s .= "\n  {$p->numero}{$marca}  ({$p->fonte})\n";
                $campos = [
                    'Classe' => $p->classe, 'Assunto' => $p->assunto, 'Foro' => $p->foro, 'Vara' => $p->vara,
                    'Distribuição' => $p->distribuicao, 'Situação' => $p->situacao, 'Valor' => $p->valor_acao,
                    'Parte' => trim($p->parte_consultada . ($p->participacao ? " ({$p->participacao})" : '')),
                ];
                foreach ($campos as $rotulo => $valor) {
                    if ($valor !== '') {
                        $s .= '    ' . str_pad($rotulo . ':', 14 + strlen($rotulo) - mb_strlen($rotulo)) . $valor . "\n";
                    }
                }
                if ($p->segredo_justica) {
                    $s .= "    Segredo de justiça: detalhes indisponíveis na consulta pública\n";
                }
                foreach ($p->partes as $parte) {
                    $s .= "    - {$parte->tipo}: {$parte->nome}\n";
                }
                if ($p->movimentacoes) {
                    $m = $p->movimentacoes[0];
                    $s .= "    Última movimentação: {$m->data} — " . mb_substr($m->descricao, 0, 120) . "\n";
                }
                if ($p->url) {
                    $s .= "    {$p->url}\n";
                }
            }
            foreach ($r->erros as $erro) {
                $s .= "\n  ! $erro\n";
            }
        }
        return $s . $linha;
    }
}

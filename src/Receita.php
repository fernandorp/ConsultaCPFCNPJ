<?php
/** Dados cadastrais públicos de CNPJ via BrasilAPI (espelho dos dados abertos da Receita). */

namespace Consulta;

class Receita
{
    public const URL = 'https://brasilapi.com.br/api/cnpj/v1/';

    /** @return array|null razao_social, nome_fantasia, situacao, municipio, uf, socios */
    public static function consultarCnpj(ClienteHttp $http, string $cnpj): ?array
    {
        try {
            $dados = json_decode($http->get(self::URL . $cnpj)->texto, true);
        } catch (ErroFonte $e) {
            return null;
        }
        if (!is_array($dados) || empty($dados['razao_social'])) {
            return null;
        }
        $socios = [];
        foreach ($dados['qsa'] ?? [] as $s) {
            if (!empty($s['nome_socio'])) {
                $socios[] = $s['nome_socio'];
            }
        }
        return [
            'cnpj' => $cnpj,
            'razao_social' => $dados['razao_social'],
            'nome_fantasia' => $dados['nome_fantasia'] ?? '',
            'situacao' => $dados['descricao_situacao_cadastral'] ?? '',
            'municipio' => $dados['municipio'] ?? '',
            'uf' => $dados['uf'] ?? '',
            'socios' => $socios,
        ];
    }
}

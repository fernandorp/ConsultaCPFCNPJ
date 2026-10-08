<?php

namespace Consulta;

/** Uma origem de processos (um tribunal/sistema). */
abstract class Fonte
{
    public const CODIGO = '';
    public const DESCRICAO = '';

    /** @var ClienteHttp */
    protected $http;

    public function __construct(ClienteHttp $http)
    {
        $this->http = $http;
    }

    /**
     * Busca processos em que o CPF/CNPJ figura como parte.
     * @return \Generator<Processo>
     */
    abstract public function buscarDocumento(string $documento, int $maxPaginas = 10): \Generator;

    /**
     * Busca processos pelo nome completo da parte.
     * @return \Generator<Processo>
     */
    abstract public function buscarNome(string $nome, int $maxPaginas = 10): \Generator;

    /** Carrega partes, movimentações e demais dados do processo. */
    abstract public function detalhar(Processo $processo): Processo;
}

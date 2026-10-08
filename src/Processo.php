<?php

namespace Consulta;

class Parte
{
    public $tipo;
    public $nome;
    /** @var string[] */
    public $advogados;

    public function __construct(string $tipo, string $nome, array $advogados = [])
    {
        $this->tipo = $tipo;
        $this->nome = $nome;
        $this->advogados = $advogados;
    }
}

class Movimentacao
{
    public $data;
    public $descricao;

    public function __construct(string $data, string $descricao)
    {
        $this->data = $data;
        $this->descricao = $descricao;
    }
}

class Processo
{
    public $numero;
    public $fonte;
    public $url = '';
    public $classe = '';
    public $assunto = '';
    public $foro = '';
    public $vara = '';
    public $distribuicao = '';
    public $parte_consultada = '';
    public $participacao = '';
    public $situacao = '';
    public $valor_acao = '';
    public $juiz = '';
    /** @var Parte[] */
    public $partes = [];
    /** @var Movimentacao[] */
    public $movimentacoes = [];
    public $segredo_justica = false;

    public function __construct(string $numero, string $fonte, array $campos = [])
    {
        $this->numero = $numero;
        $this->fonte = $fonte;
        foreach ($campos as $nome => $valor) {
            $this->$nome = $valor;
        }
    }

    /** Completa campos vazios com os de $outro (ex.: detalhes sobre a listagem). */
    public function mesclar(Processo $outro): void
    {
        foreach (get_object_vars($outro) as $nome => $valor) {
            if ($valor && !$this->$nome) {
                $this->$nome = $valor;
            }
        }
    }

    public function paraArray(): array
    {
        return json_decode(json_encode($this), true);
    }
}

<?php

namespace Consulta;

class Resultado
{
    /** @var string rótulo exibido: "CPF 123.456.789-09" ou "Nome: FULANO" */
    public $consulta;
    /** @var string chave do histórico */
    public $chave;
    /** @var array|null dados do CNPJ na Receita */
    public $empresa = null;
    /** @var Processo[] */
    public $processos = [];
    /** @var array<string, true> números de processos novos */
    public $novos = [];
    /** @var string[] */
    public $erros = [];

    public function __construct(string $consulta, string $chave)
    {
        $this->consulta = $consulta;
        $this->chave = $chave;
    }
}

/** Orquestra as buscas: documentos e nomes em todas as fontes, detalhes e histórico. */
class Consulta
{
    public const FONTES = [
        TJSP1Grau::CODIGO => TJSP1Grau::class,
        TJSP2Grau::CODIGO => TJSP2Grau::class,
    ];

    /** @var Fonte[] */
    private $fontes;
    private $http;
    private $historico;

    public $detalhes = false;
    public $incluirRazaoSocial = false;
    public $consultarReceita = true;
    public $maxPaginas = 10;
    public $maxProcessos = 300;
    /** @var string[] documentos ignorados por serem inválidos */
    public $ignorados = [];

    /** @param string[] $codigosFontes */
    public function __construct(ClienteHttp $http, array $codigosFontes, ?Historico $historico = null)
    {
        $this->http = $http;
        $this->historico = $historico;
        $this->fontes = [];
        foreach ($codigosFontes as $codigo) {
            if (isset(self::FONTES[$codigo])) {
                $classe = self::FONTES[$codigo];
                $this->fontes[] = new $classe($http);
            }
        }
    }

    /**
     * @param string[] $documentos
     * @param string[] $nomes
     * @return Resultado[]
     */
    public function executar(array $documentos, array $nomes = []): array
    {
        $resultados = [];
        foreach ($documentos as $valor) {
            try {
                $doc = Documento::identificar($valor);
            } catch (DocumentoInvalido $e) {
                $this->ignorados[] = $e->getMessage();
                continue;
            }
            $res = new Resultado((string) $doc, $doc->numero);
            if ($doc->tipo === 'CNPJ' && $this->consultarReceita) {
                $res->empresa = Receita::consultarCnpj($this->http, $doc->numero);
            }
            $formatado = $doc->formatado();
            $this->consultar($res, function (Fonte $f) use ($formatado) {
                return $f->buscarDocumento($formatado, $this->maxPaginas);
            });
            // Muitos processos antigos não têm o CPF/CNPJ cadastrado; a razão social complementa.
            if ($this->incluirRazaoSocial && !empty($res->empresa['razao_social'])) {
                $razao = $res->empresa['razao_social'];
                $this->consultar($res, function (Fonte $f) use ($razao) {
                    return $f->buscarNome($razao, $this->maxPaginas);
                });
            }
            $resultados[] = $res;
        }

        foreach ($nomes as $nome) {
            $res = new Resultado("Nome: $nome", 'NOME:' . mb_strtoupper($nome));
            $this->consultar($res, function (Fonte $f) use ($nome) {
                return $f->buscarNome($nome, $this->maxPaginas);
            });
            $resultados[] = $res;
        }

        if ($this->historico) {
            foreach ($resultados as $res) {
                foreach ($res->processos as $p) {
                    if ($this->historico->registrar($res->chave, $p)) {
                        $res->novos[$p->numero] = true;
                    }
                }
            }
        }
        return $resultados;
    }

    private function consultar(Resultado $res, callable $buscar): void
    {
        $vistos = [];
        foreach ($res->processos as $p) {
            $vistos[$p->fonte . '|' . $p->numero] = true;
        }
        foreach ($this->fontes as $fonte) {
            try {
                foreach ($buscar($fonte) as $p) {
                    if (isset($vistos[$p->fonte . '|' . $p->numero])) {
                        continue;
                    }
                    if (count($res->processos) >= $this->maxProcessos) {
                        $res->erros[] = $fonte::CODIGO . ": limite de {$this->maxProcessos} processos atingido";
                        break;
                    }
                    if ($this->detalhes) {
                        $p = $fonte->detalhar($p);
                    }
                    $vistos[$p->fonte . '|' . $p->numero] = true;
                    $res->processos[] = $p;
                }
            } catch (ErroFonte $e) {
                $msg = $e->getMessage();
                $res->erros[] = strpos($msg, $fonte::CODIGO) === 0 ? $msg : $fonte::CODIGO . ': ' . $msg;
            }
        }
    }
}

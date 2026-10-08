<?php
/**
 * Consulta pública de processos do TJSP (e-SAJ), 1º e 2º graus.
 *
 * O e-SAJ é o sistema do Tribunal de Justiça de São Paulo. Ele também concentra
 * os processos que envolvem municípios paulistas (execuções fiscais de IPTU/ISS,
 * ações contra prefeituras etc.), pois municípios não têm Justiça própria.
 *
 * Fluxo da consulta:
 *  - search.do com cbPesquisa=DOCPARTE (CPF/CNPJ) ou NMPARTE (nome);
 *  - com vários resultados, o e-SAJ devolve uma listagem paginada;
 *  - com um único resultado, redireciona direto para a página do processo (show.do).
 */

namespace Consulta;

class Html
{
    /** @var \DOMXPath */
    public $xpath;

    public function __construct(string $html)
    {
        if (!mb_check_encoding($html, 'UTF-8')) {
            $html = mb_convert_encoding($html, 'UTF-8', 'ISO-8859-1');
        }
        $dom = new \DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);
        $this->xpath = new \DOMXPath($dom);
    }

    /** XPath que casa elementos com a classe CSS informada. */
    public static function classe(string $classe, string $tag = '*'): string
    {
        return $tag . '[' . self::temClasse($classe) . ']';
    }

    /** Condição XPath: o elemento tem a classe CSS informada. */
    public static function temClasse(string $classe): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' $classe ')";
    }

    /** @return \DOMElement[] */
    public function todos(string $expr, ?\DOMNode $raiz = null): array
    {
        $lista = $raiz ? $this->xpath->query($expr, $raiz) : $this->xpath->query($expr);
        return $lista ? iterator_to_array($lista) : [];
    }

    public function um(string $expr, ?\DOMNode $raiz = null): ?\DOMElement
    {
        $todos = $this->todos($expr, $raiz);
        return $todos[0] ?? null;
    }

    /** Textos não vazios do nó, na ordem (como os "stripped strings" do BeautifulSoup). */
    public static function textos(?\DOMNode $no): array
    {
        if (!$no) {
            return [];
        }
        $saida = [];
        $percorrer = function (\DOMNode $n) use (&$percorrer, &$saida) {
            foreach ($n->childNodes as $filho) {
                if ($filho instanceof \DOMText) {
                    $t = trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $filho->nodeValue)));
                    if ($t !== '') {
                        $saida[] = $t;
                    }
                } elseif ($filho->hasChildNodes()) {
                    $percorrer($filho);
                }
            }
        };
        $percorrer($no);
        return $saida;
    }

    public static function texto(?\DOMNode $no): string
    {
        return implode(' ', self::textos($no));
    }

    /** Texto do primeiro seletor (XPath relativo) que tiver conteúdo. */
    public function primeiro(?\DOMNode $raiz, string ...$exprs): string
    {
        foreach ($exprs as $expr) {
            $t = self::texto($this->um($expr, $raiz));
            if ($t !== '') {
                return $t;
            }
        }
        return '';
    }

    public function porId(string $id): string
    {
        return self::texto($this->um("//*[@id='$id']"));
    }

    public function temCaptcha(): bool
    {
        return (bool) $this->um('//' . self::classe('g-recaptcha')
            . " | //*[@id='captchaCodigo'] | //img[contains(@id, 'aptcha')] | //input[contains(@name, 'aptcha')]");
    }

    public static function resolverUrl(string $base, string $href): string
    {
        if ($href === '' || preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        $origem = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($href[0] === '/') {
            return $origem . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        return $origem . $dir . $href;
    }
}

abstract class Esaj extends Fonte
{
    public const BASE_URL = 'https://esaj.tjsp.jus.br';
    /** "/cpopg" ou "/cposg" */
    protected const CAMINHO = '';

    private const SEM_RESULTADOS = '/n[ãa]o (existem informa|foram encontrad)/iu';
    private const SEGREDO = '/segredo de justi[çc]a/iu';

    abstract protected function parametros(string $tipo, string $valor): array;

    /** @return array{0: Processo[], 1: ?string} processos da página e link da próxima */
    public static function parseListagem(string $html, string $urlBase, string $fonte): array
    {
        $h = new Html($html);
        $processos = [];
        foreach ($h->todos('//' . Html::classe('linkProcesso', 'a')) as $link) {
            $numero = Html::texto($link);
            if ($numero === '') {
                continue;
            }
            $bloco = $h->um('ancestor::li[1]', $link)
                ?: $h->um('ancestor::div[' . Html::temClasse('row') . '][1]', $link)
                ?: $link->parentNode;
            $c = function (string ...$classes) use ($h, $bloco) {
                return $h->primeiro($bloco, ...array_map(function ($cl) {
                    return './/' . Html::classe($cl);
                }, $classes));
            };
            $local = explode(' - ', $c('dataLocalDistribuicaoProcesso', 'dataLocalDistribuicao'), 2);
            $processos[] = new Processo($numero, $fonte, [
                'url' => Html::resolverUrl($urlBase, $link->getAttribute('href')),
                'classe' => $c('classeProcesso', 'classe'),
                'assunto' => $c('assuntoPrincipalProcesso', 'assuntoProcesso', 'assunto'),
                'distribuicao' => trim($local[0]),
                'foro' => trim($local[1] ?? '') ?: $c('secaoProcesso', 'orgaoJulgadorProcesso'),
                'parte_consultada' => $c('nomeParte'),
                'participacao' => $c('tipoDeParticipacao'),
            ]);
        }

        $proxima = null;
        $link = $h->um('//' . Html::classe('unj-pagination__next', 'a')
            . " | //a[@title='Próxima página'] | //a[@title='Próxima']");
        if ($link && $link->getAttribute('href') !== ''
            && strpos(' ' . $link->getAttribute('class') . ' ', ' disabled ') === false) {
            $proxima = Html::resolverUrl($urlBase, $link->getAttribute('href'));
        }
        return [$processos, $proxima];
    }

    /** Extrai os dados da página de um processo (show.do). Retorna null se não for uma. */
    public static function parseDetalhes(string $html, string $url, string $fonte): ?Processo
    {
        $h = new Html($html);
        $numero = $h->porId('numeroProcesso');
        if ($numero === '') {
            return null;
        }
        $id = function (string ...$ids) use ($h) {
            foreach ($ids as $i) {
                if (($t = $h->porId($i)) !== '') {
                    return $t;
                }
            }
            return '';
        };
        $p = new Processo($numero, $fonte, [
            'url' => $url,
            'classe' => $id('classeProcesso'),
            'assunto' => $id('assuntoProcesso'),
            'foro' => $id('foroProcesso', 'secaoProcesso'),
            'vara' => $id('varaProcesso', 'orgaoJulgadorProcesso'),
            'juiz' => $id('juizProcesso', 'relatorProcesso'),
            'distribuicao' => $id('dataHoraDistribuicaoProcesso'),
            'valor_acao' => $id('valorAcaoProcesso'),
            'situacao' => $id('labelSituacaoProcesso', 'situacaoProcesso'),
        ]);

        $tabela = $h->um("//*[@id='tableTodasPartes']") ?: $h->um("//*[@id='tablePartesPrincipais']");
        if ($tabela) {
            foreach ($h->todos('.//tr', $tabela) as $linha) {
                $celula = $h->um('.//' . Html::classe('nomeParteEAdvogado'), $linha);
                if (!$celula) {
                    continue;
                }
                $textos = Html::textos($celula);
                $advogados = [];
                foreach ($textos as $i => $t) {
                    $rotulo = mb_strtolower(rtrim($t, ':'));
                    if (isset($textos[$i + 1]) && in_array($rotulo, ['advogado', 'advogada', 'advogados', 'advogadas'], true)) {
                        $advogados[] = $textos[$i + 1];
                    }
                }
                $tipo = $h->primeiro($linha, './/' . Html::classe('tipoDeParticipacao'), './/td');
                $p->partes[] = new Parte(rtrim($tipo, ': '), $textos[0] ?? '', $advogados);
            }
        }

        $tabela = $h->um("//*[@id='tabelaTodasMovimentacoes']") ?: $h->um("//*[@id='tabelaUltimasMovimentacoes']");
        if ($tabela) {
            foreach ($h->todos('.//tr', $tabela) as $linha) {
                $data = $h->primeiro($linha, './/' . Html::classe('dataMovimentacao'), './/' . Html::classe('dataMovimentacaoProcesso'));
                $descricao = $h->primeiro($linha, './/' . Html::classe('descricaoMovimentacao'), './/' . Html::classe('descricaoMovimentacaoProcesso'));
                if ($data !== '' || $descricao !== '') {
                    $p->movimentacoes[] = new Movimentacao($data, $descricao);
                }
            }
        }

        $cabecalho = $h->primeiro(null, "//*[@id='mensagemRetorno']", '//' . Html::classe('unj-entity-header'));
        $p->segredo_justica = (bool) preg_match(self::SEGREDO, $cabecalho);
        return $p;
    }

    private function buscar(string $tipo, string $valor, int $maxPaginas): \Generator
    {
        $resp = $this->http->get(static::BASE_URL . static::CAMINHO . '/search.do', $this->parametros($tipo, $valor));
        $vistos = [];
        for ($pagina = 0; $pagina < $maxPaginas; $pagina++) {
            // Resultado único: o e-SAJ redireciona para a página do processo.
            $detalhe = self::parseDetalhes($resp->texto, $resp->url, static::CODIGO);
            if ($detalhe) {
                yield $detalhe;
                return;
            }
            [$processos, $proxima] = self::parseListagem($resp->texto, $resp->url, static::CODIGO);
            if (!$processos) {
                $this->verificarPaginaVazia($resp->texto);
                return;
            }
            $novos = 0;
            foreach ($processos as $p) {
                if (!isset($vistos[$p->numero])) {
                    $vistos[$p->numero] = true;
                    $novos++;
                    yield $p;
                }
            }
            if (!$novos || !$proxima) {
                return;
            }
            $resp = $this->http->get($proxima);
        }
    }

    private function verificarPaginaVazia(string $html): void
    {
        $h = new Html($html);
        $mensagem = $h->primeiro(null, "//*[@id='mensagemRetorno']", '//' . Html::classe('unj-alert'), "//*[@id='spwTabelaMensagem']");
        if (preg_match(self::SEM_RESULTADOS, $mensagem ?: Html::texto($h->um('//body')))) {
            return;
        }
        if ($h->temCaptcha()) {
            throw new CaptchaRequerido(static::CODIGO . ': o e-SAJ pediu CAPTCHA. Faça a consulta manualmente em '
                . static::BASE_URL . static::CAMINHO . '/open.do ou tente novamente mais tarde.');
        }
        throw new ErroFonte(static::CODIGO . ': resposta inesperada do e-SAJ'
            . ($mensagem !== '' ? " ($mensagem)" : '; o layout do site pode ter mudado'));
    }

    public function buscarDocumento(string $documento, int $maxPaginas = 10): \Generator
    {
        return $this->buscar('DOCPARTE', $documento, $maxPaginas);
    }

    public function buscarNome(string $nome, int $maxPaginas = 10): \Generator
    {
        return $this->buscar('NMPARTE', $nome, $maxPaginas);
    }

    public function detalhar(Processo $processo): Processo
    {
        if (strpos($processo->url, 'show.do') === false) {
            return $processo;
        }
        $resp = $this->http->get($processo->url);
        $detalhe = self::parseDetalhes($resp->texto, $resp->url, static::CODIGO);
        if (!$detalhe) {
            $h = new Html($resp->texto);
            if ($h->temCaptcha()) {
                throw new CaptchaRequerido(static::CODIGO . ": o e-SAJ pediu CAPTCHA ao abrir {$processo->numero}.");
            }
            if (preg_match(self::SEGREDO, Html::texto($h->um('//body')))) {
                $processo->segredo_justica = true;
            }
            return $processo;
        }
        $detalhe->mesclar($processo);
        return $detalhe;
    }
}

class TJSP1Grau extends Esaj
{
    public const CODIGO = 'tjsp-1g';
    public const DESCRICAO = 'TJSP — 1º grau: varas cíveis, criminais, Fazenda Pública, execuções fiscais, Juizados';
    protected const CAMINHO = '/cpopg';

    protected function parametros(string $tipo, string $valor): array
    {
        $params = [
            'conversationId' => '',
            'cbPesquisa' => $tipo,
            'dadosConsulta.valorConsulta' => $valor,
            'cdForo' => '-1', // todos os foros
        ];
        if ($tipo === 'NMPARTE') {
            $params['chNmCompleto'] = 'true';
        }
        return $params;
    }
}

class TJSP2Grau extends Esaj
{
    public const CODIGO = 'tjsp-2g';
    public const DESCRICAO = 'TJSP — 2º grau: recursos, agravos e ações originárias';
    protected const CAMINHO = '/cposg';

    protected function parametros(string $tipo, string $valor): array
    {
        $params = [
            'conversationId' => '',
            'paginaConsulta' => '0',
            'cbPesquisa' => $tipo,
            'dePesquisa' => $valor,
            'localPesquisa.cdLocal' => '-1',
        ];
        if ($tipo === 'NMPARTE') {
            $params['chNmCompleto'] = 'true';
        }
        return $params;
    }
}

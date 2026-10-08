<?php
/**
 * Testes sem dependências (não precisa de PHPUnit): php tests/run.php
 * Usam páginas de exemplo em tests/fixtures/ no formato do e-SAJ.
 */

use Consulta\{CaptchaRequerido, ClienteHttp, Consulta, Documento, ErroFonte, Esaj, Exportar, Fonte, Historico, Processo, Resposta, TJSP1Grau};

require __DIR__ . '/../src/bootstrap.php';

const URL_BUSCA = 'https://esaj.tjsp.jus.br/cpopg/search.do?x=1';

function fixture(string $nome): string
{
    return file_get_contents(__DIR__ . "/fixtures/$nome");
}

class HttpFalso implements ClienteHttp
{
    public $chamadas = [];
    private $rotas;

    /** @param array<string, array{0: string, 1: string}> $rotas trecho da URL => [url final, html] */
    public function __construct(array $rotas)
    {
        $this->rotas = $rotas;
    }

    public function get(string $url, array $params = []): Resposta
    {
        $completa = $url . ($params ? '?' . http_build_query($params) : '');
        $this->chamadas[] = $completa;
        foreach ($this->rotas as $trecho => [$final, $html]) {
            if (strpos($completa, $trecho) !== false) {
                return new Resposta($final ?: $completa, $html);
            }
        }
        throw new ErroFonte("rota não mapeada: $completa");
    }
}

$testes = [];
function teste(string $nome, callable $f) { global $testes; $testes[$nome] = $f; }
function igual($esperado, $obtido, string $msg = '')
{
    if ($esperado !== $obtido) {
        throw new Exception("$msg\n    esperado: " . var_export($esperado, true) . "\n    obtido:   " . var_export($obtido, true));
    }
}
function lanca(string $classe, callable $f)
{
    try {
        $f();
    } catch (Throwable $e) {
        if ($e instanceof $classe) {
            return;
        }
        throw new Exception("esperava $classe, veio " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new Exception("esperava $classe, nada foi lançado");
}

// ---------------------------------------------------------------- documentos
teste('cpf válido', function () {
    foreach (['529.982.247-25', '52998224725', ' 529 982 247 25 '] as $c) igual(true, Documento::validarCpf($c), $c);
});
teste('cpf inválido', function () {
    foreach (['529.982.247-24', '111.111.111-11', '123', ''] as $c) igual(false, Documento::validarCpf($c), $c);
});
teste('cnpj válido (inclusive alfanumérico)', function () {
    foreach (['11.222.333/0001-81', '11222333000181', '12.ABC.345/01DE-35', '12abc34501de35'] as $c) igual(true, Documento::validarCnpj($c), $c);
});
teste('cnpj inválido', function () {
    foreach (['11.222.333/0001-80', '00000000000000', '12.ABC.345/01DE-36', '12.ABC.345/01DE-3A'] as $c) igual(false, Documento::validarCnpj($c), $c);
});
teste('identificar formata', function () {
    igual('529.982.247-25', Documento::identificar('52998224725')->formatado());
    $d = Documento::identificar('12abc34501de35');
    igual(['CNPJ', '12.ABC.345/01DE-35'], [$d->tipo, $d->formatado()]);
    lanca(\Consulta\DocumentoInvalido::class, function () { Documento::identificar('12345678900'); });
});

// ---------------------------------------------------------------- e-SAJ
teste('listagem', function () {
    [$ps, $proxima] = Esaj::parseListagem(fixture('listagem_pagina1.html'), URL_BUSCA, 'tjsp-1g');
    igual(['1000001-11.2023.8.26.0100', '1500002-22.2021.8.26.0090'], array_map(function ($p) { return $p->numero; }, $ps));
    $p = $ps[1];
    igual('Execução Fiscal', $p->classe);
    igual('IPTU/ Imposto Predial e Territorial Urbano', $p->assunto);
    igual('05/07/2021', $p->distribuicao);
    igual('Foro das Execuções Fiscais Municipais', $p->foro);
    igual('Executado', $p->participacao);
    igual('FULANO DE TAL', $p->parte_consultada);
    igual(0, strpos($p->url, 'https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=AB0002&processo.foro=90'));
    igual(0, strpos($proxima, 'https://esaj.tjsp.jus.br/cpopg/trocarPagina.do?paginaConsulta=2&conversationId='));
});
teste('listagem última página', function () {
    [$ps, $proxima] = Esaj::parseListagem(fixture('listagem_pagina2.html'), URL_BUSCA, 'tjsp-1g');
    igual(1, count($ps));
    igual(null, $proxima);
    igual('Foro de Campinas', $ps[0]->foro);
});
teste('detalhes', function () {
    $p = Esaj::parseDetalhes(fixture('detalhe.html'), 'https://esaj.tjsp.jus.br/cpopg/show.do?x', 'tjsp-1g');
    igual('1000001-11.2023.8.26.0100', $p->numero);
    igual(['10ª Vara Cível', 'JUIZA EXEMPLO', 'Em andamento', 'R$ 10.000,00'], [$p->vara, $p->juiz, $p->situacao, $p->valor_acao]);
    igual([['Reqte', 'FULANO DE TAL', ['CICLANO ADVOGADO']], ['Reqdo', 'EMPRESA RÉ LTDA', []]],
        array_map(function ($x) { return [$x->tipo, $x->nome, $x->advogados]; }, $p->partes));
    igual('20/09/2026', $p->movimentacoes[0]->data);
    igual('Conclusos para Despacho Aguardando análise', $p->movimentacoes[0]->descricao);
    igual(false, $p->segredo_justica);
});
teste('listagem não é detalhe', function () {
    igual(null, Esaj::parseDetalhes(fixture('listagem_pagina1.html'), URL_BUSCA, 'tjsp-1g'));
});
teste('html em ISO-8859-1', function () {
    $html = mb_convert_encoding(fixture('listagem_pagina1.html'), 'ISO-8859-1', 'UTF-8');
    [$ps] = Esaj::parseListagem($html, URL_BUSCA, 'tjsp-1g');
    igual('Execução Fiscal', $ps[1]->classe);
});
teste('busca com paginação', function () {
    $http = new HttpFalso([
        'trocarPagina.do' => ['', fixture('listagem_pagina2.html')],
        'search.do' => ['', fixture('listagem_pagina1.html')],
    ]);
    $numeros = [];
    foreach ((new TJSP1Grau($http))->buscarDocumento('529.982.247-25') as $p) $numeros[] = $p->numero;
    igual(['1000001-11.2023.8.26.0100', '1500002-22.2021.8.26.0090', '0003333-33.2019.8.26.0114'], $numeros);
    igual(true, strpos($http->chamadas[0], 'cbPesquisa=DOCPARTE') !== false);
    igual(true, strpos($http->chamadas[0], 'cdForo=-1') !== false);
    igual(2, count($http->chamadas));
});
teste('resultado único redireciona para o processo', function () {
    $url = 'https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=AB0001';
    $ps = iterator_to_array((new TJSP1Grau(new HttpFalso(['search.do' => [$url, fixture('detalhe.html')]])))->buscarDocumento('x'), false);
    igual(1, count($ps));
    igual($url, $ps[0]->url);
    igual(2, count($ps[0]->partes));
});
teste('sem resultados', function () {
    $f = new TJSP1Grau(new HttpFalso(['search.do' => ['', fixture('sem_resultados.html')]]));
    igual([], iterator_to_array($f->buscarDocumento('x'), false));
});
teste('captcha', function () {
    $f = new TJSP1Grau(new HttpFalso(['search.do' => ['', fixture('captcha.html')]]));
    lanca(CaptchaRequerido::class, function () use ($f) { iterator_to_array($f->buscarDocumento('x')); });
});
teste('layout inesperado', function () {
    $f = new TJSP1Grau(new HttpFalso(['search.do' => ['', '<html><body>manutenção</body></html>']]));
    lanca(ErroFonte::class, function () use ($f) { iterator_to_array($f->buscarDocumento('x')); });
});
teste('detalhar mescla dados da listagem', function () {
    [$ps] = Esaj::parseListagem(fixture('listagem_pagina1.html'), URL_BUSCA, 'tjsp-1g');
    $p = (new TJSP1Grau(new HttpFalso(['show.do' => ['', fixture('detalhe.html')]])))->detalhar($ps[0]);
    igual(['Reqte', '10ª Vara Cível'], [$p->participacao, $p->vara]);
});

// ---------------------------------------------------------------- consulta, histórico e exportação
function consultaFalsa(?Historico $h): Consulta
{
    $http = new HttpFalso([
        'cpopg/search.do' => ['', fixture('listagem_pagina2.html')],
        'cposg/search.do' => ['', fixture('sem_resultados.html')],
        'brasilapi' => ['', '{"razao_social":"EMPRESA EXEMPLO LTDA","descricao_situacao_cadastral":"ATIVA","qsa":[{"nome_socio":"FULANO"}]}'],
    ]);
    return new Consulta($http, ['tjsp-1g', 'tjsp-2g'], $h);
}

teste('histórico marca processos novos', function () {
    $db = tempnam(sys_get_temp_dir(), 'hist');
    $r1 = consultaFalsa(new Historico($db))->executar(['529.982.247-25']);
    igual(['0003333-33.2019.8.26.0114'], array_keys($r1[0]->novos));
    $r2 = consultaFalsa(new Historico($db))->executar(['529.982.247-25']);
    igual([], $r2[0]->novos);
    unlink($db);
});
teste('documento inválido é ignorado; nome deduplica', function () {
    $c = consultaFalsa(null);
    $r = $c->executar(['123'], ['FULANO DE TAL']);
    igual(1, count($r));
    igual(1, count($c->ignorados));
    igual('Nome: FULANO DE TAL', $r[0]->consulta);
    igual(1, count($r[0]->processos));
});
teste('cnpj com receita e exportação', function () {
    $r = consultaFalsa(null)->executar(['11.222.333/0001-81']);
    igual('EMPRESA EXEMPLO LTDA', $r[0]->empresa['razao_social']);
    igual(['FULANO'], $r[0]->empresa['socios']);
    $json = json_decode(Exportar::json($r), true);
    igual('CNPJ 11.222.333/0001-81', $json[0]['consulta']);
    igual('Cumprimento de sentença', $json[0]['processos'][0]['classe']);
    igual(true, strpos(Exportar::csv($r), 'Cumprimento de sentença') !== false);
    $texto = Exportar::texto($r);
    igual(true, strpos($texto, 'Empresa: EMPRESA EXEMPLO LTDA') !== false);
    igual(true, strpos($texto, 'Classe:       Cumprimento de sentença') !== false, $texto);
});
teste('erro de uma fonte não impede as outras', function () {
    $http = new HttpFalso(['cposg/search.do' => ['', fixture('listagem_pagina2.html')]]);
    $r = (new Consulta($http, ['tjsp-1g', 'tjsp-2g']))->executar(['529.982.247-25']);
    igual(1, count($r[0]->processos));
    igual(1, count($r[0]->erros));
});

// ---------------------------------------------------------------- execução
$falhas = 0;
foreach ($testes as $nome => $f) {
    try {
        $f();
        echo "  ok    $nome\n";
    } catch (Throwable $e) {
        $falhas++;
        echo "  FALHA $nome: " . $e->getMessage() . "\n";
    }
}
echo "\n" . (count($testes) - $falhas) . ' de ' . count($testes) . " testes passaram\n";
exit($falhas ? 1 : 0);

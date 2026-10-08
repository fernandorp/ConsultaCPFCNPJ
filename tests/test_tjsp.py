import pytest

from consulta.fontes import CaptchaRequerido, ErroFonte, TJSP1Grau
from consulta.fontes.tjsp_esaj import parse_detalhes, parse_listagem
from tests.conftest import fixture

URL_BUSCA = "https://esaj.tjsp.jus.br/cpopg/search.do?x=1"


def test_parse_listagem():
    processos, proxima = parse_listagem(fixture("listagem_pagina1.html"), URL_BUSCA, "tjsp-1g")
    assert [p.numero for p in processos] == ["1000001-11.2023.8.26.0100", "1500002-22.2021.8.26.0090"]
    p = processos[1]
    assert p.classe == "Execução Fiscal"
    assert p.assunto.startswith("IPTU")
    assert p.distribuicao == "05/07/2021"
    assert p.foro == "Foro das Execuções Fiscais Municipais"
    assert p.participacao == "Executado"
    assert p.parte_consultada == "FULANO DE TAL"
    assert p.url.startswith("https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=AB0002")
    assert proxima.startswith("https://esaj.tjsp.jus.br/cpopg/trocarPagina.do?paginaConsulta=2")


def test_parse_listagem_ultima_pagina():
    processos, proxima = parse_listagem(fixture("listagem_pagina2.html"), URL_BUSCA, "tjsp-1g")
    assert len(processos) == 1 and proxima is None


def test_parse_detalhes():
    p = parse_detalhes(fixture("detalhe.html"), "https://esaj.tjsp.jus.br/cpopg/show.do?x", "tjsp-1g")
    assert p.numero == "1000001-11.2023.8.26.0100"
    assert (p.vara, p.juiz, p.situacao, p.valor_acao) == ("10ª Vara Cível", "JUIZA EXEMPLO", "Em andamento", "R$ 10.000,00")
    assert [(x.tipo, x.nome, x.advogados) for x in p.partes] == [
        ("Reqte", "FULANO DE TAL", ["CICLANO ADVOGADO"]),
        ("Reqdo", "EMPRESA RÉ LTDA", []),
    ]
    assert p.movimentacoes[0].data == "20/09/2026"
    assert p.movimentacoes[0].descricao == "Conclusos para Despacho Aguardando análise"


def test_parse_detalhes_nao_e_detalhe():
    assert parse_detalhes(fixture("listagem_pagina1.html"), URL_BUSCA, "tjsp-1g") is None


def test_busca_com_paginacao(sessao_falsa):
    sessao = sessao_falsa({
        "trocarPagina.do": ("", fixture("listagem_pagina2.html")),
        "search.do": ("", fixture("listagem_pagina1.html")),
    })
    fonte = TJSP1Grau(sessao=sessao, atraso=0)
    numeros = [p.numero for p in fonte.buscar_documento("529.982.247-25")]
    assert numeros == ["1000001-11.2023.8.26.0100", "1500002-22.2021.8.26.0090", "0003333-33.2019.8.26.0114"]
    assert "cbPesquisa=DOCPARTE" in sessao.chamadas[0]
    assert "cdForo=-1" in sessao.chamadas[0]
    assert len(sessao.chamadas) == 2


def test_busca_resultado_unico_redireciona(sessao_falsa):
    url_show = "https://esaj.tjsp.jus.br/cpopg/show.do?processo.codigo=AB0001"
    sessao = sessao_falsa({"search.do": (url_show, fixture("detalhe.html"))})
    processos = list(TJSP1Grau(sessao=sessao, atraso=0).buscar_documento("x"))
    assert len(processos) == 1
    assert processos[0].url == url_show and processos[0].partes


def test_busca_sem_resultados(sessao_falsa):
    sessao = sessao_falsa({"search.do": ("", fixture("sem_resultados.html"))})
    assert list(TJSP1Grau(sessao=sessao, atraso=0).buscar_documento("x")) == []


def test_busca_captcha(sessao_falsa):
    sessao = sessao_falsa({"search.do": ("", fixture("captcha.html"))})
    with pytest.raises(CaptchaRequerido):
        list(TJSP1Grau(sessao=sessao, atraso=0).buscar_documento("x"))


def test_busca_layout_inesperado(sessao_falsa):
    sessao = sessao_falsa({"search.do": ("", "<html><body>manutenção</body></html>")})
    with pytest.raises(ErroFonte):
        list(TJSP1Grau(sessao=sessao, atraso=0).buscar_documento("x"))


def test_detalhar_mescla_listagem(sessao_falsa):
    processos, _ = parse_listagem(fixture("listagem_pagina1.html"), URL_BUSCA, "tjsp-1g")
    sessao = sessao_falsa({"show.do": ("", fixture("detalhe.html"))})
    p = TJSP1Grau(sessao=sessao, atraso=0).detalhar(processos[0])
    assert p.participacao == "Reqte" and p.vara == "10ª Vara Cível"

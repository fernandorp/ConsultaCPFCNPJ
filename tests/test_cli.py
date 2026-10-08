import io
import json

import consulta.cli as cli
from consulta.historico import Historico
from consulta.fontes.base import Processo


class FonteFake:
    codigo = "fake"
    descricao = "Fonte de teste"

    def __init__(self, sessao=None, atraso=0):
        pass

    def buscar_documento(self, documento, max_paginas=20):
        yield Processo(numero="1000001-11.2023.8.26.0100", fonte="fake", classe="Execução Fiscal")

    def buscar_nome(self, nome, max_paginas=20):
        yield Processo(numero="1000001-11.2023.8.26.0100", fonte="fake")
        yield Processo(numero="2000002-22.2024.8.26.0100", fonte="fake")

    def detalhar(self, p):
        return p


def _rodar(monkeypatch, tmp_path, *argv):
    monkeypatch.setattr(cli, "FONTES", {"fake": FonteFake})
    monkeypatch.setattr(cli, "consultar_cnpj", lambda cnpj, sessao: None)
    args = cli.criar_parser().parse_args(
        ["buscar", *argv, "-f", "fake", "--historico", str(tmp_path / "h.db")]
    )
    return cli.executar(args, log=io.StringIO())


def test_historico_marca_novos(monkeypatch, tmp_path):
    monkeypatch.setattr(cli, "FONTES", {"fake": FonteFake})
    r1 = _rodar(monkeypatch, tmp_path, "529.982.247-25")
    assert r1[0].novos == {"1000001-11.2023.8.26.0100"}
    r2 = _rodar(monkeypatch, tmp_path, "529.982.247-25")
    assert r2[0].novos == set()


def test_busca_por_nome_deduplica(monkeypatch, tmp_path):
    monkeypatch.setattr(cli, "FONTES", {"fake": FonteFake})
    res = _rodar(monkeypatch, tmp_path, "--nome", "FULANO DE TAL", "--sem-historico")
    assert [p.numero for p in res[0].processos] == ["1000001-11.2023.8.26.0100", "2000002-22.2024.8.26.0100"]


def test_documento_invalido_ignorado(monkeypatch, tmp_path):
    monkeypatch.setattr(cli, "FONTES", {"fake": FonteFake})
    assert _rodar(monkeypatch, tmp_path, "123", "--sem-historico") == []


def test_saidas(monkeypatch, tmp_path):
    monkeypatch.setattr(cli, "FONTES", {"fake": FonteFake})
    res = _rodar(monkeypatch, tmp_path, "11.222.333/0001-81")
    buf = io.StringIO()
    cli.escrever_json(res, buf)
    dados = json.loads(buf.getvalue())
    assert dados[0]["consulta"] == "CNPJ 11.222.333/0001-81"
    assert dados[0]["processos"][0]["novo"] is True
    buf = io.StringIO()
    cli.escrever_csv(res, buf)
    assert "Execução Fiscal" in buf.getvalue()
    buf = io.StringIO()
    cli.escrever_tabela(res, buf, com_historico=True)
    assert "[NOVO]" in buf.getvalue()


def test_validar(capsys):
    assert cli.main(["validar", "529.982.247-25"]) == 0
    assert cli.main(["validar", "529.982.247-24"]) == 1

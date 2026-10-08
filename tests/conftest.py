from pathlib import Path
from urllib.parse import urlencode

import pytest

FIXTURES = Path(__file__).parent / "fixtures"


def fixture(nome: str) -> str:
    return (FIXTURES / nome).read_text(encoding="utf-8")


class RespostaFalsa:
    def __init__(self, url: str, texto: str, status: int = 200):
        self.url, self.text, self.status_code = url, texto, status

    def raise_for_status(self):
        pass

    def json(self):
        import json
        return json.loads(self.text)


class SessaoFalsa:
    """Responde conforme um mapa {trecho da URL: (url final, html)}; registra as chamadas."""

    def __init__(self, rotas: dict[str, tuple[str, str]]):
        self.rotas, self.chamadas, self.headers = rotas, [], {}

    def get(self, url, params=None, timeout=None):
        completa = url + ("?" + urlencode(params) if params else "")
        self.chamadas.append(completa)
        for trecho, (final, html) in self.rotas.items():
            if trecho in completa:
                return RespostaFalsa(final or completa, html)
        raise AssertionError(f"rota não mapeada: {completa}")


@pytest.fixture
def sessao_falsa():
    return SessaoFalsa

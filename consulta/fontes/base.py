"""Modelos e interface comum das fontes de processos."""

from __future__ import annotations

import time
from abc import ABC, abstractmethod
from dataclasses import asdict, dataclass, field
from typing import Iterator

import requests

USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
    "Chrome/126.0 Safari/537.36 ConsultaCPFCNPJ/0.1"
)


class ErroFonte(RuntimeError):
    """Falha ao consultar uma fonte (rede, HTML inesperado, bloqueio)."""


class CaptchaRequerido(ErroFonte):
    """A fonte exigiu CAPTCHA; a consulta precisa ser feita manualmente no navegador."""


@dataclass
class Parte:
    tipo: str
    nome: str
    advogados: list[str] = field(default_factory=list)


@dataclass
class Movimentacao:
    data: str
    descricao: str


@dataclass
class Processo:
    numero: str
    fonte: str
    url: str = ""
    classe: str = ""
    assunto: str = ""
    foro: str = ""
    vara: str = ""
    distribuicao: str = ""
    parte_consultada: str = ""
    participacao: str = ""
    situacao: str = ""
    valor_acao: str = ""
    juiz: str = ""
    partes: list[Parte] = field(default_factory=list)
    movimentacoes: list[Movimentacao] = field(default_factory=list)
    segredo_justica: bool = False

    def para_dict(self) -> dict:
        return asdict(self)

    def mesclar(self, outro: "Processo") -> None:
        """Completa campos vazios com os de `outro` (ex.: detalhes sobre a listagem)."""
        for nome, valor in outro.__dict__.items():
            if valor and not getattr(self, nome):
                setattr(self, nome, valor)


class Fonte(ABC):
    """Uma origem de processos (um tribunal/sistema)."""

    codigo: str
    descricao: str

    def __init__(self, sessao: requests.Session | None = None, atraso: float = 1.5, timeout: float = 30):
        self.sessao = sessao or requests.Session()
        self.sessao.headers.setdefault("User-Agent", USER_AGENT)
        self.atraso = atraso
        self.timeout = timeout
        self._ultima_requisicao = 0.0

    def _get(self, url: str, params: dict | None = None) -> requests.Response:
        # Respeita um intervalo mínimo entre requisições para não sobrecarregar o tribunal.
        espera = self.atraso - (time.monotonic() - self._ultima_requisicao)
        if espera > 0:
            time.sleep(espera)
        try:
            resp = self.sessao.get(url, params=params, timeout=self.timeout)
            resp.raise_for_status()
        except requests.RequestException as exc:
            raise ErroFonte(f"{self.codigo}: falha ao acessar {url}: {exc}") from exc
        finally:
            self._ultima_requisicao = time.monotonic()
        return resp

    @abstractmethod
    def buscar_documento(self, documento: str, max_paginas: int = 20) -> Iterator[Processo]:
        """Busca processos em que o CPF/CNPJ (apenas dígitos) figura como parte."""

    @abstractmethod
    def buscar_nome(self, nome: str, max_paginas: int = 20) -> Iterator[Processo]:
        """Busca processos pelo nome completo da parte."""

    @abstractmethod
    def detalhar(self, processo: Processo) -> Processo:
        """Carrega partes, movimentações e demais dados do processo."""

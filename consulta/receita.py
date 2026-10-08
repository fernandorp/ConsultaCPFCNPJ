"""Dados cadastrais públicos de CNPJ via BrasilAPI (espelho dos dados abertos da Receita)."""

from __future__ import annotations

from dataclasses import dataclass, field

import requests

URL_BRASILAPI = "https://brasilapi.com.br/api/cnpj/v1/{cnpj}"


@dataclass
class Empresa:
    cnpj: str
    razao_social: str
    nome_fantasia: str = ""
    situacao: str = ""
    municipio: str = ""
    uf: str = ""
    socios: list[str] = field(default_factory=list)


def consultar_cnpj(cnpj: str, sessao: requests.Session | None = None, timeout: float = 20) -> Empresa | None:
    """Retorna os dados da empresa ou None se não for possível obtê-los."""
    sessao = sessao or requests.Session()
    try:
        resp = sessao.get(URL_BRASILAPI.format(cnpj=cnpj), timeout=timeout)
        if resp.status_code != 200:
            return None
        dados = resp.json()
    except (requests.RequestException, ValueError):
        return None
    return Empresa(
        cnpj=cnpj,
        razao_social=dados.get("razao_social") or "",
        nome_fantasia=dados.get("nome_fantasia") or "",
        situacao=dados.get("descricao_situacao_cadastral") or "",
        municipio=dados.get("municipio") or "",
        uf=dados.get("uf") or "",
        socios=[s.get("nome_socio", "") for s in dados.get("qsa") or [] if s.get("nome_socio")],
    )

"""Validação, normalização e formatação de CPF e CNPJ.

Suporta o CNPJ alfanumérico (IN RFB nº 2.229/2024), em vigor desde julho de 2026:
as 12 primeiras posições podem conter letras maiúsculas e os dígitos
verificadores continuam numéricos. O cálculo usa o valor ASCII do caractere
menos 48, o que preserva o resultado para CNPJs só numéricos.
"""

from __future__ import annotations

import re
from dataclasses import dataclass

_PESOS_CNPJ = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]


class DocumentoInvalido(ValueError):
    pass


@dataclass(frozen=True)
class Documento:
    tipo: str  # "CPF" ou "CNPJ"
    numero: str  # apenas dígitos/letras, sem pontuação

    @property
    def formatado(self) -> str:
        n = self.numero
        if self.tipo == "CPF":
            return f"{n[:3]}.{n[3:6]}.{n[6:9]}-{n[9:]}"
        return f"{n[:2]}.{n[2:5]}.{n[5:8]}/{n[8:12]}-{n[12:]}"

    def __str__(self) -> str:
        return f"{self.tipo} {self.formatado}"


def _limpar(valor: str) -> str:
    return re.sub(r"[.\-/\s]", "", valor).upper()


def _dv_cpf(base: str) -> str:
    for tamanho in (9, 10):
        soma = sum(int(d) * p for d, p in zip(base[:tamanho], range(tamanho + 1, 1, -1)))
        resto = soma % 11
        base = base[:tamanho] + str(0 if resto < 2 else 11 - resto)
    return base[9:11]


def _dv_cnpj(base: str) -> str:
    for tamanho in (12, 13):
        pesos = _PESOS_CNPJ[13 - tamanho :]
        soma = sum((ord(c) - 48) * p for c, p in zip(base[:tamanho], pesos))
        resto = soma % 11
        base = base[:tamanho] + str(0 if resto < 2 else 11 - resto)
    return base[12:14]


def validar_cpf(valor: str) -> bool:
    n = _limpar(valor)
    if not re.fullmatch(r"\d{11}", n) or n == n[0] * 11:
        return False
    return _dv_cpf(n) == n[9:]


def validar_cnpj(valor: str) -> bool:
    n = _limpar(valor)
    if not re.fullmatch(r"[0-9A-Z]{12}\d{2}", n) or n == n[0] * 14:
        return False
    return _dv_cnpj(n) == n[12:]


def identificar(valor: str) -> Documento:
    """Detecta se o valor é CPF ou CNPJ válido; levanta DocumentoInvalido caso contrário."""
    n = _limpar(valor)
    if len(n) == 11 and validar_cpf(n):
        return Documento("CPF", n)
    if len(n) == 14 and validar_cnpj(n):
        return Documento("CNPJ", n)
    raise DocumentoInvalido(f"CPF/CNPJ inválido: {valor!r}")

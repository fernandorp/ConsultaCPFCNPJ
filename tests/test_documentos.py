import pytest

from consulta.documentos import DocumentoInvalido, identificar, validar_cnpj, validar_cpf


@pytest.mark.parametrize("cpf", ["529.982.247-25", "52998224725", " 529 982 247 25 "])
def test_cpf_valido(cpf):
    assert validar_cpf(cpf)


@pytest.mark.parametrize("cpf", ["529.982.247-24", "111.111.111-11", "123", ""])
def test_cpf_invalido(cpf):
    assert not validar_cpf(cpf)


@pytest.mark.parametrize("cnpj", ["11.222.333/0001-81", "11222333000181", "12.ABC.345/01DE-35", "12abc34501de35"])
def test_cnpj_valido(cnpj):
    assert validar_cnpj(cnpj)


@pytest.mark.parametrize("cnpj", ["11.222.333/0001-80", "00000000000000", "12.ABC.345/01DE-36", "12.ABC.345/01DE-3A"])
def test_cnpj_invalido(cnpj):
    assert not validar_cnpj(cnpj)


def test_identificar_formata():
    assert identificar("52998224725").formatado == "529.982.247-25"
    doc = identificar("12abc34501de35")
    assert (doc.tipo, doc.formatado) == ("CNPJ", "12.ABC.345/01DE-35")


def test_identificar_invalido():
    with pytest.raises(DocumentoInvalido):
        identificar("12345678900")

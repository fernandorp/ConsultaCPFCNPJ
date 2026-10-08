"""Consulta pública de processos do TJSP (e-SAJ), 1º e 2º graus.

O e-SAJ é o sistema do Tribunal de Justiça de São Paulo. Ele também concentra
os processos que envolvem municípios paulistas (execuções fiscais de IPTU/ISS,
ações contra prefeituras etc.), pois municípios não têm Justiça própria.

Fluxo da consulta:
  * `search.do` com `cbPesquisa=DOCPARTE` (CPF/CNPJ) ou `NMPARTE` (nome);
  * com vários resultados, o e-SAJ devolve uma listagem paginada;
  * com um único resultado, redireciona direto para a página do processo (`show.do`).
"""

from __future__ import annotations

import re
from typing import Iterator
from urllib.parse import urljoin

from bs4 import BeautifulSoup, Tag

from consulta.fontes.base import (
    CaptchaRequerido,
    ErroFonte,
    Fonte,
    Movimentacao,
    Parte,
    Processo,
)

BASE_URL = "https://esaj.tjsp.jus.br"

_SEM_RESULTADOS = re.compile(r"n[ãa]o (existem informa|foram encontrad)", re.IGNORECASE)
_SEGREDO = re.compile(r"segredo de justi[çc]a", re.IGNORECASE)


def _texto(el: Tag | None) -> str:
    return " ".join(el.get_text(" ", strip=True).split()) if el else ""


def _primeiro(raiz: Tag, *seletores: str) -> str:
    for seletor in seletores:
        el = raiz.select_one(seletor)
        if el is not None and _texto(el):
            return _texto(el)
    return ""


def _checar_captcha(soup: BeautifulSoup) -> bool:
    return bool(
        soup.select_one(".g-recaptcha, #captchaCodigo, img[id*=aptcha], input[name*=aptcha]")
    )


def parse_listagem(html: str, url_base: str, fonte: str) -> tuple[list[Processo], str | None]:
    """Extrai processos de uma página de listagem e o link da próxima página (se houver)."""
    soup = BeautifulSoup(html, "lxml")
    processos: list[Processo] = []
    for link in soup.select("a.linkProcesso"):
        numero = _texto(link)
        if not numero:
            continue
        bloco = link.find_parent("li") or link.find_parent("div", class_="row") or link.parent
        local = _primeiro(bloco, ".dataLocalDistribuicaoProcesso", ".dataLocalDistribuicao")
        data, _, foro = local.partition(" - ")
        processos.append(
            Processo(
                numero=numero,
                fonte=fonte,
                url=urljoin(url_base, link.get("href", "")),
                classe=_primeiro(bloco, ".classeProcesso", ".classe"),
                assunto=_primeiro(bloco, ".assuntoPrincipalProcesso", ".assuntoProcesso", ".assunto"),
                distribuicao=data.strip(),
                foro=foro.strip() or _primeiro(bloco, ".secaoProcesso", ".orgaoJulgadorProcesso"),
                parte_consultada=_primeiro(bloco, ".nomeParte"),
                participacao=_primeiro(bloco, ".tipoDeParticipacao"),
            )
        )

    proxima = None
    link_proxima = soup.select_one("a.unj-pagination__next, a[title='Próxima página'], a[title='Próxima']")
    if link_proxima is not None and link_proxima.get("href") and "disabled" not in (link_proxima.get("class") or []):
        proxima = urljoin(url_base, link_proxima["href"])
    return processos, proxima


def parse_detalhes(html: str, url: str, fonte: str) -> Processo | None:
    """Extrai os dados da página de um processo (`show.do`). Retorna None se não for uma."""
    soup = BeautifulSoup(html, "lxml")
    numero = _primeiro(soup, "#numeroProcesso")
    if not numero:
        return None

    processo = Processo(
        numero=numero,
        fonte=fonte,
        url=url,
        classe=_primeiro(soup, "#classeProcesso"),
        assunto=_primeiro(soup, "#assuntoProcesso"),
        foro=_primeiro(soup, "#foroProcesso", "#secaoProcesso"),
        vara=_primeiro(soup, "#varaProcesso", "#orgaoJulgadorProcesso"),
        juiz=_primeiro(soup, "#juizProcesso", "#relatorProcesso"),
        distribuicao=_primeiro(soup, "#dataHoraDistribuicaoProcesso"),
        valor_acao=_primeiro(soup, "#valorAcaoProcesso"),
        situacao=_primeiro(soup, "#labelSituacaoProcesso", "#situacaoProcesso"),
    )

    tabela_partes = soup.select_one("#tableTodasPartes") or soup.select_one("#tablePartesPrincipais")
    if tabela_partes is not None:
        for linha in tabela_partes.select("tr"):
            celula_nome = linha.select_one(".nomeParteEAdvogado")
            if celula_nome is None:
                continue
            textos = list(celula_nome.stripped_strings)
            advogados = [
                textos[i + 1]
                for i, t in enumerate(textos[:-1])
                if t.rstrip(":").lower() in ("advogado", "advogada", "advogados", "advogadas")
            ]
            tipo = _primeiro(linha, ".tipoDeParticipacao") or _texto(linha.select_one("td"))
            processo.partes.append(Parte(tipo=tipo.rstrip(": "), nome=textos[0] if textos else "", advogados=advogados))

    tabela_mov = soup.select_one("#tabelaTodasMovimentacoes") or soup.select_one("#tabelaUltimasMovimentacoes")
    if tabela_mov is not None:
        for linha in tabela_mov.select("tr"):
            data = _primeiro(linha, ".dataMovimentacao", ".dataMovimentacaoProcesso")
            descricao = _primeiro(linha, ".descricaoMovimentacao", ".descricaoMovimentacaoProcesso")
            if data or descricao:
                processo.movimentacoes.append(Movimentacao(data=data, descricao=descricao))

    processo.segredo_justica = bool(_SEGREDO.search(_primeiro(soup, "#mensagemRetorno", ".unj-entity-header")))
    return processo


class _ESAJ(Fonte):
    caminho: str  # "/cpopg" ou "/cposg"
    parametro_valor: str  # nome do parâmetro com o valor pesquisado

    def _parametros(self, tipo: str, valor: str) -> dict:
        raise NotImplementedError

    def _buscar(self, tipo: str, valor: str, max_paginas: int) -> Iterator[Processo]:
        url = f"{BASE_URL}{self.caminho}/search.do"
        resp = self._get(url, self._parametros(tipo, valor))
        vistos: set[str] = set()

        for _ in range(max_paginas):
            html = resp.text
            # Resultado único: o e-SAJ redireciona para a página do processo.
            detalhe = parse_detalhes(html, resp.url, self.codigo)
            if detalhe is not None:
                yield detalhe
                return

            processos, proxima = parse_listagem(html, resp.url, self.codigo)
            novos = [p for p in processos if p.numero not in vistos]
            if not processos:
                self._verificar_pagina_vazia(html)
                return
            for p in novos:
                vistos.add(p.numero)
                yield p
            if not novos or not proxima:
                return
            resp = self._get(proxima)

    def _verificar_pagina_vazia(self, html: str) -> None:
        soup = BeautifulSoup(html, "lxml")
        mensagem = _primeiro(soup, "#mensagemRetorno", ".unj-alert", "#spwTabelaMensagem")
        if _SEM_RESULTADOS.search(mensagem or soup.get_text(" ")):
            return
        if _checar_captcha(soup):
            raise CaptchaRequerido(
                f"{self.codigo}: o e-SAJ pediu CAPTCHA. Faça a consulta manualmente em "
                f"{BASE_URL}{self.caminho}/open.do ou tente novamente mais tarde."
            )
        raise ErroFonte(
            f"{self.codigo}: resposta inesperada do e-SAJ"
            + (f" ({mensagem})" if mensagem else "; o layout do site pode ter mudado")
        )

    def buscar_documento(self, documento: str, max_paginas: int = 20) -> Iterator[Processo]:
        return self._buscar("DOCPARTE", documento, max_paginas)

    def buscar_nome(self, nome: str, max_paginas: int = 20) -> Iterator[Processo]:
        return self._buscar("NMPARTE", nome, max_paginas)

    def detalhar(self, processo: Processo) -> Processo:
        if not processo.url or "show.do" not in processo.url:
            return processo
        resp = self._get(processo.url)
        detalhe = parse_detalhes(resp.text, resp.url, self.codigo)
        if detalhe is None:
            soup = BeautifulSoup(resp.text, "lxml")
            if _checar_captcha(soup):
                raise CaptchaRequerido(f"{self.codigo}: o e-SAJ pediu CAPTCHA ao abrir {processo.numero}.")
            if _SEGREDO.search(soup.get_text(" ")):
                processo.segredo_justica = True
            return processo
        detalhe.mesclar(processo)
        return detalhe


class TJSP1Grau(_ESAJ):
    codigo = "tjsp-1g"
    descricao = "TJSP — 1º grau (e-SAJ cpopg): varas cíveis, criminais, fazenda pública, execuções fiscais, JECs"
    caminho = "/cpopg"

    def _parametros(self, tipo: str, valor: str) -> dict:
        params = {
            "conversationId": "",
            "cbPesquisa": tipo,
            "dadosConsulta.valorConsulta": valor,
            "cdForo": "-1",  # todos os foros
        }
        if tipo == "NMPARTE":
            params["chNmCompleto"] = "true"
        return params


class TJSP2Grau(_ESAJ):
    codigo = "tjsp-2g"
    descricao = "TJSP — 2º grau (e-SAJ cposg): recursos, agravos e ações originárias"
    caminho = "/cposg"

    def _parametros(self, tipo: str, valor: str) -> dict:
        params = {
            "conversationId": "",
            "paginaConsulta": "0",
            "cbPesquisa": tipo,
            "dePesquisa": valor,
            "localPesquisa.cdLocal": "-1",
        }
        if tipo == "NMPARTE":
            params["chNmCompleto"] = "true"
        return params

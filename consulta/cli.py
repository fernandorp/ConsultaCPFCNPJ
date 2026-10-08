"""Linha de comando: python -m consulta buscar <CPF/CNPJ> ..."""

from __future__ import annotations

import argparse
import csv
import json
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Iterable, TextIO

import requests

from consulta.documentos import DocumentoInvalido, identificar
from consulta.fontes import FONTES, ErroFonte, Fonte, Processo
from consulta.historico import CAMINHO_PADRAO, Historico
from consulta.receita import Empresa, consultar_cnpj


@dataclass
class Resultado:
    consulta: str  # rótulo exibido: "CPF 123.456.789-09" ou "Nome: FULANO"
    chave: str  # chave do histórico
    empresa: Empresa | None = None
    processos: list[Processo] = field(default_factory=list)
    novos: set[str] = field(default_factory=set)
    erros: list[str] = field(default_factory=list)


def _ler_arquivo(caminho: str) -> list[str]:
    linhas = Path(caminho).read_text(encoding="utf-8").splitlines()
    return [l.split("#", 1)[0].strip() for l in linhas if l.split("#", 1)[0].strip()]


def _consultar(
    resultado: Resultado,
    fontes: Iterable[Fonte],
    buscar,  # Callable[[Fonte], Iterator[Processo]]
    detalhar: bool,
    max_processos: int,
    log: TextIO,
) -> None:
    vistos = {(p.fonte, p.numero) for p in resultado.processos}
    for fonte in fontes:
        print(f"  · {fonte.descricao} ...", file=log, flush=True)
        try:
            for processo in buscar(fonte):
                if (processo.fonte, processo.numero) in vistos:
                    continue
                if len(resultado.processos) >= max_processos:
                    resultado.erros.append(f"{fonte.codigo}: limite de {max_processos} processos atingido")
                    break
                if detalhar:
                    processo = fonte.detalhar(processo)
                vistos.add((processo.fonte, processo.numero))
                resultado.processos.append(processo)
        except ErroFonte as exc:
            resultado.erros.append(str(exc))


def executar(args: argparse.Namespace, log: TextIO = sys.stderr) -> list[Resultado]:
    sessao = requests.Session()
    fontes = [FONTES[c](sessao=sessao, atraso=args.atraso) for c in args.fontes]
    historico = None if args.sem_historico else Historico(args.historico)

    documentos = list(args.documentos)
    if args.arquivo:
        documentos += _ler_arquivo(args.arquivo)

    resultados: list[Resultado] = []
    for valor in documentos:
        try:
            doc = identificar(valor)
        except DocumentoInvalido as exc:
            print(f"Ignorando: {exc}", file=log)
            continue
        print(f"Consultando {doc} ...", file=log, flush=True)
        res = Resultado(consulta=str(doc), chave=doc.numero)

        if doc.tipo == "CNPJ" and not args.sem_receita:
            res.empresa = consultar_cnpj(doc.numero, sessao)
            if res.empresa:
                print(f"  {res.empresa.razao_social} ({res.empresa.situacao})", file=log)

        _consultar(
            res, fontes, lambda f: f.buscar_documento(doc.formatado, args.max_paginas),
            args.detalhes, args.max_processos, log,
        )
        # Muitos processos antigos não têm o CPF/CNPJ cadastrado; a razão social complementa.
        if args.incluir_razao_social and res.empresa and res.empresa.razao_social:
            razao = res.empresa.razao_social
            print(f"  Buscando também pela razão social: {razao}", file=log)
            _consultar(
                res, fontes, lambda f: f.buscar_nome(razao, args.max_paginas),
                args.detalhes, args.max_processos, log,
            )
        resultados.append(res)

    for nome in args.nome or []:
        print(f"Consultando nome {nome!r} ...", file=log, flush=True)
        res = Resultado(consulta=f"Nome: {nome}", chave=f"NOME:{nome.upper()}")
        _consultar(
            res, fontes, lambda f: f.buscar_nome(nome, args.max_paginas),
            args.detalhes, args.max_processos, log,
        )
        resultados.append(res)

    if historico is not None:
        for res in resultados:
            res.novos = {p.numero for p in res.processos if historico.registrar(res.chave, p)}
        historico.fechar()
    return resultados


def escrever_tabela(resultados: list[Resultado], saida: TextIO, com_historico: bool) -> None:
    for res in resultados:
        print("=" * 78, file=saida)
        print(res.consulta, file=saida)
        if res.empresa:
            e = res.empresa
            print(f"  Empresa: {e.razao_social} — {e.situacao} — {e.municipio}/{e.uf}", file=saida)
        print(f"  Processos encontrados: {len(res.processos)}", file=saida)
        for p in res.processos:
            marca = " [NOVO]" if com_historico and p.numero in res.novos else ""
            print(f"\n  {p.numero}{marca}  ({p.fonte})", file=saida)
            for rotulo, valor in (
                ("Classe", p.classe), ("Assunto", p.assunto), ("Foro", p.foro), ("Vara", p.vara),
                ("Distribuição", p.distribuicao), ("Situação", p.situacao), ("Valor", p.valor_acao),
                ("Parte", f"{p.parte_consultada} ({p.participacao})" if p.participacao else p.parte_consultada),
            ):
                if valor:
                    print(f"    {rotulo + ':':<14}{valor}", file=saida)
            if p.segredo_justica:
                print("    Segredo de justiça: detalhes indisponíveis na consulta pública", file=saida)
            for parte in p.partes:
                print(f"    - {parte.tipo}: {parte.nome}", file=saida)
            if p.movimentacoes:
                ultima = p.movimentacoes[0]
                print(f"    Última movimentação: {ultima.data} — {ultima.descricao[:120]}", file=saida)
            if p.url:
                print(f"    {p.url}", file=saida)
        for erro in res.erros:
            print(f"\n  ! {erro}", file=saida)
    print("=" * 78, file=saida)


def escrever_json(resultados: list[Resultado], saida: TextIO) -> None:
    dados = [
        {
            "consulta": r.consulta,
            "empresa": r.empresa.__dict__ if r.empresa else None,
            "processos": [dict(p.para_dict(), novo=p.numero in r.novos) for p in r.processos],
            "erros": r.erros,
        }
        for r in resultados
    ]
    json.dump(dados, saida, ensure_ascii=False, indent=2)
    saida.write("\n")


def escrever_csv(resultados: list[Resultado], saida: TextIO) -> None:
    colunas = ["consulta", "numero", "fonte", "novo", "classe", "assunto", "foro", "vara",
               "distribuicao", "situacao", "valor_acao", "participacao", "ultima_movimentacao", "url"]
    w = csv.DictWriter(saida, fieldnames=colunas, extrasaction="ignore")
    w.writeheader()
    for r in resultados:
        for p in r.processos:
            linha = p.para_dict()
            ultima = p.movimentacoes[0] if p.movimentacoes else None
            linha.update(
                consulta=r.consulta,
                novo="sim" if p.numero in r.novos else "",
                ultima_movimentacao=f"{ultima.data} {ultima.descricao}" if ultima else "",
            )
            w.writerow(linha)


def criar_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="consulta",
        description="Consulta processos judiciais por CPF/CNPJ (inicialmente TJSP, 1º e 2º graus).",
    )
    sub = parser.add_subparsers(dest="comando", required=True)

    b = sub.add_parser("buscar", help="busca processos por CPF/CNPJ e/ou nome")
    b.add_argument("documentos", nargs="*", help="CPFs/CNPJs (com ou sem pontuação)")
    b.add_argument("-a", "--arquivo", help="arquivo com um CPF/CNPJ por linha (# para comentários)")
    b.add_argument("-n", "--nome", action="append", help="busca também por nome completo (pode repetir)")
    b.add_argument("--incluir-razao-social", action="store_true",
                   help="para CNPJ, busca também pela razão social obtida na Receita")
    b.add_argument("-f", "--fontes", nargs="+", choices=sorted(FONTES), default=sorted(FONTES),
                   help="fontes a consultar (padrão: todas)")
    b.add_argument("-d", "--detalhes", action="store_true",
                   help="abre cada processo para obter partes e movimentações (mais lento)")
    b.add_argument("--formato", choices=["tabela", "json", "csv"], default="tabela")
    b.add_argument("-o", "--saida", help="grava o resultado em arquivo em vez da tela")
    b.add_argument("--historico", default=str(CAMINHO_PADRAO),
                   help=f"banco SQLite para marcar processos novos (padrão: {CAMINHO_PADRAO})")
    b.add_argument("--sem-historico", action="store_true", help="não grava nem compara com o histórico")
    b.add_argument("--sem-receita", action="store_true", help="não consulta os dados do CNPJ na BrasilAPI")
    b.add_argument("--atraso", type=float, default=1.5, help="segundos entre requisições (padrão: 1.5)")
    b.add_argument("--max-paginas", type=int, default=20, help="páginas de resultado por fonte (padrão: 20)")
    b.add_argument("--max-processos", type=int, default=500, help="limite de processos por consulta")

    v = sub.add_parser("validar", help="valida CPFs/CNPJs sem consultar nada")
    v.add_argument("documentos", nargs="+")

    sub.add_parser("fontes", help="lista as fontes disponíveis")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = criar_parser().parse_args(argv)

    if args.comando == "validar":
        ok = True
        for valor in args.documentos:
            try:
                print(f"{identificar(valor)}: válido")
            except DocumentoInvalido as exc:
                print(exc)
                ok = False
        return 0 if ok else 1

    if args.comando == "fontes":
        for codigo, classe in sorted(FONTES.items()):
            print(f"{codigo:<10} {classe.descricao}")
        return 0

    if not args.documentos and not args.arquivo and not args.nome:
        print("Informe ao menos um CPF/CNPJ, --arquivo ou --nome.", file=sys.stderr)
        return 1

    resultados = executar(args)
    saida = open(args.saida, "w", encoding="utf-8", newline="") if args.saida else sys.stdout
    try:
        if args.formato == "json":
            escrever_json(resultados, saida)
        elif args.formato == "csv":
            escrever_csv(resultados, saida)
        else:
            escrever_tabela(resultados, saida, com_historico=not args.sem_historico)
    finally:
        if args.saida:
            saida.close()
    return 2 if any(r.erros for r in resultados) else 0

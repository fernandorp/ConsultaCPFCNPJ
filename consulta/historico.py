"""Histórico local (SQLite) para identificar processos novos entre consultas."""

from __future__ import annotations

import json
import sqlite3
from datetime import datetime
from pathlib import Path

from consulta.fontes.base import Processo

CAMINHO_PADRAO = Path.home() / ".consultacpfcnpj" / "historico.db"


class Historico:
    def __init__(self, caminho: Path | str = CAMINHO_PADRAO):
        caminho = Path(caminho)
        caminho.parent.mkdir(parents=True, exist_ok=True)
        self.conn = sqlite3.connect(caminho)
        self.conn.execute(
            """
            CREATE TABLE IF NOT EXISTS processos (
                documento TEXT NOT NULL,
                fonte TEXT NOT NULL,
                numero TEXT NOT NULL,
                primeira_vez TEXT NOT NULL,
                ultima_vez TEXT NOT NULL,
                dados TEXT NOT NULL,
                PRIMARY KEY (documento, fonte, numero)
            )
            """
        )

    def registrar(self, documento: str, processo: Processo) -> bool:
        """Grava o processo e retorna True se ele ainda não tinha sido visto para o documento."""
        agora = datetime.now().isoformat(timespec="seconds")
        dados = json.dumps(processo.para_dict(), ensure_ascii=False)
        with self.conn:
            cur = self.conn.execute(
                "UPDATE processos SET ultima_vez = ?, dados = ? WHERE documento = ? AND fonte = ? AND numero = ?",
                (agora, dados, documento, processo.fonte, processo.numero),
            )
            if cur.rowcount:
                return False
            self.conn.execute(
                "INSERT INTO processos VALUES (?, ?, ?, ?, ?, ?)",
                (documento, processo.fonte, processo.numero, agora, agora, dados),
            )
        return True

    def fechar(self) -> None:
        self.conn.close()

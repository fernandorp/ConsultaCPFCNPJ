<?php
/** Histórico local (SQLite) para identificar processos novos entre consultas. */

namespace Consulta;

class Historico
{
    /** @var \PDO */
    private $pdo;

    public function __construct(string $caminho)
    {
        $dir = dirname($caminho);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->pdo = new \PDO('sqlite:' . $caminho, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS processos (
                documento TEXT NOT NULL,
                fonte TEXT NOT NULL,
                numero TEXT NOT NULL,
                primeira_vez TEXT NOT NULL,
                ultima_vez TEXT NOT NULL,
                dados TEXT NOT NULL,
                PRIMARY KEY (documento, fonte, numero)
            )'
        );
    }

    /** Grava o processo e retorna true se ele ainda não tinha sido visto para o documento. */
    public function registrar(string $documento, Processo $p): bool
    {
        $agora = date('Y-m-d\TH:i:s');
        $dados = json_encode($p->paraArray(), JSON_UNESCAPED_UNICODE);
        $upd = $this->pdo->prepare('UPDATE processos SET ultima_vez = ?, dados = ? WHERE documento = ? AND fonte = ? AND numero = ?');
        $upd->execute([$agora, $dados, $documento, $p->fonte, $p->numero]);
        if ($upd->rowCount()) {
            return false;
        }
        $this->pdo->prepare('INSERT INTO processos VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$documento, $p->fonte, $p->numero, $agora, $agora, $dados]);
        return true;
    }
}

<?php
/**
 * Uso pela linha de comando (SSH ou cron da hospedagem):
 *
 *   php cli.php                                   # usa os documentos/nomes do config.php
 *   php cli.php 123.456.789-09 12.345.678/0001-90
 *   php cli.php 123.456.789-09 --nome="FULANO DE TAL" --detalhes
 *   php cli.php --formato=csv > processos.csv     # tabela (padrão), csv ou json
 *   php cli.php --somente-novos                   # imprime só se houver processo novo (bom para cron com e-mail)
 *
 * Códigos de saída: 0 = ok, 1 = parâmetros inválidos, 2 = alguma fonte falhou.
 */

use Consulta\{Consulta, Exportar, Historico, Http, Resultado};

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/src/bootstrap.php';
$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : require __DIR__ . '/config.exemplo.php';

$documentos = $nomes = [];
$opcoes = ['formato' => 'tabela', 'detalhes' => false, 'razao-social' => false, 'sem-historico' => false, 'somente-novos' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--nome=(.+)$/', $arg, $m)) {
        $nomes[] = $m[1];
    } elseif (preg_match('/^--formato=(tabela|csv|json)$/', $arg, $m)) {
        $opcoes['formato'] = $m[1];
    } elseif (preg_match('/^--([a-z-]+)$/', $arg, $m) && array_key_exists($m[1], $opcoes)) {
        $opcoes[$m[1]] = true;
    } elseif ($arg === '-h' || $arg === '--ajuda') {
        echo preg_replace('/^ ?\* ?/m', '', explode('*/', explode('/**', file_get_contents(__FILE__))[1])[0]);
        exit(0);
    } elseif ($arg[0] === '-') {
        fwrite(STDERR, "Opção desconhecida: $arg (use --ajuda)\n");
        exit(1);
    } else {
        $documentos[] = $arg;
    }
}
if (!$documentos && !$nomes) {
    $documentos = $config['documentos'] ?? [];
    $nomes = $config['nomes'] ?? [];
}
if (!$documentos && !$nomes) {
    fwrite(STDERR, "Informe CPFs/CNPJs ou preencha 'documentos' no config.php.\n");
    exit(1);
}

$historico = $opcoes['sem-historico'] ? null : new Historico($config['historico'] ?? __DIR__ . '/dados/historico.db');
$consulta = new Consulta(new Http((float) ($config['atraso'] ?? 1.5)), $config['fontes'] ?? array_keys(Consulta::FONTES), $historico);
$consulta->detalhes = $opcoes['detalhes'];
$consulta->incluirRazaoSocial = $opcoes['razao-social'];
$consulta->maxPaginas = (int) ($config['max_paginas'] ?? 10);
$resultados = $consulta->executar($documentos, $nomes);
foreach ($consulta->ignorados as $i) {
    fwrite(STDERR, "Ignorado: $i\n");
}

$temNovos = (bool) array_filter($resultados, function (Resultado $r) { return $r->novos; });
$temErros = (bool) array_filter($resultados, function (Resultado $r) { return $r->erros; });
if ($opcoes['somente-novos'] && !$temNovos && !$temErros) {
    exit(0);
}

if ($opcoes['formato'] === 'csv') {
    echo Exportar::csv($resultados);
} elseif ($opcoes['formato'] === 'json') {
    echo Exportar::json($resultados);
} else {
    echo Exportar::texto($resultados, $historico !== null);
}
exit($temErros ? 2 : 0);

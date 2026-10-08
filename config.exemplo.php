<?php
/*
 * Copie este arquivo para config.php e ajuste.
 * O config.php não vai para o git (está no .gitignore).
 */
return [
    // Hash da senha de acesso. Deixe vazio na primeira vez: o próprio sistema mostra
    // uma tela para você digitar a senha e gera o hash para colar aqui.
    'senha_hash' => '',

    // Seus documentos, já preenchidos no formulário e usados pelo cli.php quando nenhum for informado.
    'documentos' => [
        // '123.456.789-09',      // meu CPF
        // '12.345.678/0001-90',  // minha empresa
    ],
    'nomes' => [
        // 'FULANO DE TAL DA SILVA',
    ],

    // Fontes consultadas por padrão.
    'fontes' => ['tjsp-1g', 'tjsp-2g'],

    // Banco SQLite do histórico (marca processos novos). Se possível, aponte para uma pasta
    // FORA da pasta pública da hospedagem, ex.: '/home/SEU_USUARIO/consulta-dados/historico.db'.
    'historico' => __DIR__ . '/dados/historico.db',

    // Segundos entre requisições ao tribunal, para não sobrecarregar o site.
    'atraso' => 1.5,
    // Páginas de resultado por fonte (cada página tem até 25 processos).
    'max_paginas' => 10,
    // Tempo máximo de execução da página em segundos (algumas hospedagens limitam a 30–60 s).
    'tempo_maximo' => 300,
];

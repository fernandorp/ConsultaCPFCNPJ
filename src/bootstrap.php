<?php
// Carrega todas as classes (sem Composer, para funcionar em qualquer hospedagem).
foreach (['Documento', 'Processo', 'Http', 'Fonte', 'EsajTjsp', 'Receita', 'Historico', 'Consulta', 'Exportar'] as $arquivo) {
    require_once __DIR__ . "/$arquivo.php";
}

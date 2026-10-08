<?php
/**
 * Interface web: consulta de processos do TJSP por CPF/CNPJ, protegida por senha.
 */

use Consulta\{Consulta, Exportar, Historico, Http};

require __DIR__ . '/src/bootstrap.php';

function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function linhas(string $texto): array
{
    $saida = [];
    foreach (preg_split('/\R/', $texto) as $linha) {
        $linha = trim(explode('#', $linha, 2)[0]);
        if ($linha !== '') {
            $saida[] = $linha;
        }
    }
    return $saida;
}

$arquivoConfig = __DIR__ . '/config.php';
$config = is_file($arquivoConfig) ? require $arquivoConfig : null;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$csrfOk = ($_POST['csrf'] ?? '') !== '' && hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '');
$acao = $_POST['acao'] ?? '';
$mensagem = '';
$hashGerado = '';
$resultados = null;
$ignorados = [];

if ($config && !empty($config['senha_hash'])) {
    if ($acao === 'entrar' && $csrfOk) {
        if (password_verify($_POST['senha'] ?? '', $config['senha_hash'])) {
            session_regenerate_id(true);
            $_SESSION['logado'] = true;
        } else {
            sleep(2); // dificulta tentativas em massa
            $mensagem = 'Senha incorreta.';
        }
    } elseif ($acao === 'sair' && $csrfOk) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
} elseif ($config && $acao === 'gerar_hash' && $csrfOk) {
    $senha = $_POST['senha'] ?? '';
    if (strlen($senha) < 10) {
        $mensagem = 'Use uma senha com pelo menos 10 caracteres.';
    } else {
        $hashGerado = password_hash($senha, PASSWORD_DEFAULT);
    }
}

$logado = $config && !empty($config['senha_hash']) && !empty($_SESSION['logado']);

// Exportação do último resultado.
if ($logado && isset($_GET['exportar'], $_SESSION['ultimo'][$_GET['exportar']])) {
    $formato = $_GET['exportar'];
    header($formato === 'csv' ? 'Content-Type: text/csv; charset=utf-8' : 'Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="processos-' . date('Y-m-d') . '.' . $formato . '"');
    echo $_SESSION['ultimo'][$formato];
    exit;
}

$fontesSelecionadas = $config['fontes'] ?? array_keys(Consulta::FONTES);
$documentosTexto = implode("\n", $config['documentos'] ?? []);
$nomesTexto = implode("\n", $config['nomes'] ?? []);
$detalhes = false;
$razaoSocial = false;

if ($logado && $acao === 'consultar') {
    if (!$csrfOk) {
        $mensagem = 'Sessão expirada. Tente novamente.';
    } else {
        $documentosTexto = $_POST['documentos'] ?? '';
        $nomesTexto = $_POST['nomes'] ?? '';
        $fontesSelecionadas = array_values(array_intersect((array) ($_POST['fontes'] ?? []), array_keys(Consulta::FONTES)));
        $detalhes = !empty($_POST['detalhes']);
        $razaoSocial = !empty($_POST['razao_social']);

        @set_time_limit((int) ($config['tempo_maximo'] ?? 300));
        ignore_user_abort(true);
        session_write_close(); // não bloqueia outras abas durante a consulta

        try {
            $historico = new Historico($config['historico'] ?? __DIR__ . '/dados/historico.db');
        } catch (Throwable $ex) {
            $historico = null;
            $mensagem = 'Histórico indisponível (verifique a permissão de escrita da pasta de dados): ' . $ex->getMessage();
        }
        $consulta = new Consulta(new Http((float) ($config['atraso'] ?? 1.5)), $fontesSelecionadas, $historico);
        $consulta->detalhes = $detalhes;
        $consulta->incluirRazaoSocial = $razaoSocial;
        $consulta->maxPaginas = (int) ($config['max_paginas'] ?? 10);
        $resultados = $consulta->executar(linhas($documentosTexto), linhas($nomesTexto));
        $ignorados = $consulta->ignorados;

        session_start();
        $_SESSION['ultimo'] = ['csv' => Exportar::csv($resultados), 'json' => Exportar::json($resultados)];
    }
}
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Consulta de Processos</title>
<style>
  :root { --fundo:#f5f6f8; --cartao:#fff; --texto:#1d2330; --suave:#5d6577; --borda:#dde1e8; --primaria:#1f5fbf; --novo:#0a7d3c; --erro:#b42318; }
  @media (prefers-color-scheme: dark) {
    :root { --fundo:#14171d; --cartao:#1d2129; --texto:#e6e8ec; --suave:#9aa3b2; --borda:#323846; --primaria:#6aa1ff; --novo:#4cc27f; --erro:#ff7b72; }
  }
  * { box-sizing: border-box; }
  body { margin:0; font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--fundo); color:var(--texto); }
  main { max-width: 960px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 1.4rem; margin: 0 0 4px; }
  .sub { color: var(--suave); margin: 0 0 20px; }
  .cartao { background: var(--cartao); border: 1px solid var(--borda); border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; }
  label { font-weight: 600; display:block; margin-bottom: 4px; }
  textarea, input[type=password], input[type=text] { width:100%; padding:8px 10px; border:1px solid var(--borda); border-radius:6px; background:var(--fundo); color:var(--texto); font:inherit; }
  textarea { min-height: 84px; font-family: ui-monospace, Consolas, monospace; }
  .grade { display:grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 640px) { .grade { grid-template-columns: 1fr; } }
  .opcoes { display:flex; flex-wrap:wrap; gap: 8px 20px; margin: 12px 0; }
  .opcoes label { font-weight: 400; display:flex; gap:6px; align-items:center; margin:0; }
  .dica { color: var(--suave); font-size: .85rem; margin: 4px 0 0; }
  button { background: var(--primaria); color:#fff; border:0; border-radius:6px; padding:9px 18px; font:inherit; font-weight:600; cursor:pointer; }
  button.link { background:none; color:var(--primaria); padding:0; font-weight:400; }
  .topo { display:flex; justify-content:space-between; align-items:flex-start; gap: 12px; }
  .alerta { border-left: 4px solid var(--erro); }
  .erro { color: var(--erro); }
  .processo { border-top: 1px solid var(--borda); padding: 12px 0; }
  .processo:first-of-type { border-top: 0; }
  .numero { font-family: ui-monospace, Consolas, monospace; font-weight: 600; }
  .selo { display:inline-block; font-size:.75rem; font-weight:700; padding:1px 8px; border-radius:999px; margin-left:6px; vertical-align: 1px; }
  .selo.novo { background: var(--novo); color:#fff; }
  .selo.fonte { border:1px solid var(--borda); color: var(--suave); font-weight:500; }
  dl { display:grid; grid-template-columns: max-content 1fr; gap: 2px 12px; margin: 6px 0 0; }
  dt { color: var(--suave); }
  dd { margin:0; overflow-wrap:anywhere; }
  details { margin-top: 6px; }
  summary { cursor:pointer; color: var(--primaria); }
  table { border-collapse: collapse; width: 100%; font-size: .9rem; margin-top: 6px; }
  td { border-top: 1px solid var(--borda); padding: 4px 8px 4px 0; vertical-align: top; }
  a { color: var(--primaria); }
  code { background: var(--fundo); padding: 2px 5px; border-radius: 4px; overflow-wrap:anywhere; }
</style>
</head>
<body>
<main>
<h1>Consulta de Processos — TJSP</h1>
<p class="sub">Busca processos em que um CPF ou CNPJ figura como parte, no 1º e 2º graus do Tribunal de Justiça de São Paulo.</p>

<?php if (!$config): ?>
  <div class="cartao alerta">
    <strong>Falta configurar.</strong> Copie <code>config.exemplo.php</code> para <code>config.php</code> e recarregue esta página.
  </div>

<?php elseif (empty($config['senha_hash'])): ?>
  <div class="cartao">
    <h2>Definir senha de acesso</h2>
    <?php if ($hashGerado): ?>
      <p>Cole a linha abaixo no <code>config.php</code>, no lugar de <code>'senha_hash' => ''</code>, e recarregue a página:</p>
      <p><code>'senha_hash' => '<?= e($hashGerado) ?>',</code></p>
    <?php else: ?>
      <p>Digite a senha que você vai usar para entrar. O sistema gera um código (hash) para você colar no <code>config.php</code>. A senha em si não é gravada.</p>
      <?php if ($mensagem): ?><p class="erro"><?= e($mensagem) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
        <input type="hidden" name="acao" value="gerar_hash">
        <label for="senha">Nova senha (mínimo 10 caracteres)</label>
        <input type="password" id="senha" name="senha" required minlength="10" autocomplete="new-password">
        <p><button>Gerar hash</button></p>
      </form>
    <?php endif; ?>
  </div>

<?php elseif (!$logado): ?>
  <div class="cartao" style="max-width:380px">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
      <input type="hidden" name="acao" value="entrar">
      <label for="senha">Senha</label>
      <input type="password" id="senha" name="senha" required autofocus autocomplete="current-password">
      <?php if ($mensagem): ?><p class="erro"><?= e($mensagem) ?></p><?php endif; ?>
      <p><button>Entrar</button></p>
    </form>
  </div>

<?php else: ?>
  <form method="post" class="cartao">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="acao" value="consultar">
    <div class="grade">
      <div>
        <label for="documentos">CPFs / CNPJs</label>
        <textarea id="documentos" name="documentos" placeholder="123.456.789-09&#10;12.345.678/0001-90"><?= e($documentosTexto) ?></textarea>
        <p class="dica">Um por linha, com ou sem pontuação.</p>
      </div>
      <div>
        <label for="nomes">Nomes completos (opcional)</label>
        <textarea id="nomes" name="nomes" placeholder="FULANO DE TAL DA SILVA"><?= e($nomesTexto) ?></textarea>
        <p class="dica">Encontra processos antigos sem CPF/CNPJ cadastrado.</p>
      </div>
    </div>
    <div class="opcoes">
      <?php foreach (Consulta::FONTES as $codigo => $classe): ?>
        <label><input type="checkbox" name="fontes[]" value="<?= e($codigo) ?>" <?= in_array($codigo, $fontesSelecionadas, true) ? 'checked' : '' ?>> <?= e($classe::DESCRICAO) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="opcoes">
      <label><input type="checkbox" name="detalhes" value="1" <?= $detalhes ? 'checked' : '' ?>> Abrir cada processo (partes e movimentações — mais lento)</label>
      <label><input type="checkbox" name="razao_social" value="1" <?= $razaoSocial ? 'checked' : '' ?>> Para CNPJ, buscar também pela razão social</label>
    </div>
    <div class="topo">
      <button>Consultar</button>
    </div>
  </form>
  <form method="post" style="text-align:right;margin:-8px 0 16px">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="acao" value="sair">
    <button class="link">Sair</button>
  </form>

  <?php if ($mensagem): ?><div class="cartao alerta"><?= e($mensagem) ?></div><?php endif; ?>
  <?php foreach ($ignorados as $i): ?><div class="cartao alerta">Ignorado: <?= e($i) ?></div><?php endforeach; ?>

  <?php if ($resultados !== null): ?>
    <?php if ($resultados): ?>
      <p>Exportar: <a href="?exportar=csv">planilha (CSV)</a> · <a href="?exportar=json">JSON</a></p>
    <?php endif; ?>
    <?php foreach ($resultados as $r): ?>
      <section class="cartao">
        <h2 style="margin:0 0 4px;font-size:1.1rem"><?= e($r->consulta) ?></h2>
        <?php if ($r->empresa): ?>
          <p class="sub" style="margin:0 0 6px"><?= e($r->empresa['razao_social']) ?> — <?= e($r->empresa['situacao']) ?> — <?= e($r->empresa['municipio']) ?>/<?= e($r->empresa['uf']) ?></p>
        <?php endif; ?>
        <p style="margin:0 0 8px"><strong><?= count($r->processos) ?></strong> processo(s) encontrado(s)<?= $r->novos ? ', <strong>' . count($r->novos) . ' novo(s)</strong>' : '' ?>.</p>
        <?php foreach ($r->erros as $erro): ?><p class="erro">⚠ <?= e($erro) ?></p><?php endforeach; ?>

        <?php foreach ($r->processos as $p): ?>
          <div class="processo">
            <span class="numero"><?= e($p->numero) ?></span>
            <?php if (isset($r->novos[$p->numero])): ?><span class="selo novo">NOVO</span><?php endif; ?>
            <span class="selo fonte"><?= e($p->fonte) ?></span>
            <dl>
              <?php foreach ([
                  'Classe' => $p->classe, 'Assunto' => $p->assunto, 'Foro' => $p->foro, 'Vara' => $p->vara,
                  'Distribuição' => $p->distribuicao, 'Situação' => $p->situacao, 'Valor' => $p->valor_acao,
                  'Parte' => trim($p->parte_consultada . ($p->participacao ? " ({$p->participacao})" : '')),
              ] as $rotulo => $valor): if ($valor !== ''): ?>
                <dt><?= e($rotulo) ?></dt><dd><?= e($valor) ?></dd>
              <?php endif; endforeach; ?>
            </dl>
            <?php if ($p->segredo_justica): ?><p class="dica">Segredo de justiça: detalhes indisponíveis na consulta pública.</p><?php endif; ?>
            <?php if ($p->partes || $p->movimentacoes): ?>
              <details>
                <summary>Partes e movimentações</summary>
                <?php if ($p->partes): ?>
                  <table>
                    <?php foreach ($p->partes as $parte): ?>
                      <tr><td><?= e($parte->tipo) ?></td><td><?= e($parte->nome) ?><?= $parte->advogados ? '<br><span class="dica">Adv.: ' . e(implode(', ', $parte->advogados)) . '</span>' : '' ?></td></tr>
                    <?php endforeach; ?>
                  </table>
                <?php endif; ?>
                <?php if ($p->movimentacoes): ?>
                  <table>
                    <?php foreach ($p->movimentacoes as $m): ?>
                      <tr><td style="white-space:nowrap"><?= e($m->data) ?></td><td><?= e($m->descricao) ?></td></tr>
                    <?php endforeach; ?>
                  </table>
                <?php endif; ?>
              </details>
            <?php endif; ?>
            <?php if ($p->url): ?><p style="margin:6px 0 0"><a href="<?= e($p->url) ?>" target="_blank" rel="noopener noreferrer">Abrir no TJSP ↗</a></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>

<p class="dica">Consulta pública, sem valor de certidão. Processos em segredo de justiça não aparecem ou aparecem sem detalhes.</p>
</main>
</body>
</html>

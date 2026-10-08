# ConsultaCPFCNPJ (PHP)

Sistema web para encontrar **processos judiciais em que um CPF ou CNPJ figura como parte**. Ele roda em
qualquer hospedagem PHP comum, sem Composer e sem banco de dados externo. A primeira versão consulta o
**Tribunal de Justiça de São Paulo (TJSP)** pelo e-SAJ, nos dois graus:

| Fonte     | O que cobre |
|-----------|-------------|
| `tjsp-1g` | 1º grau: varas cíveis, criminais, família, Fazenda Pública, **execuções fiscais estaduais e municipais**, Juizados Especiais |
| `tjsp-2g` | 2º grau: recursos (apelações, agravos) e ações originárias |

> **E os processos "municipais"?** Municípios não têm Justiça própria. Toda ação judicial envolvendo uma
> prefeitura paulista (cobrança de IPTU/ISS, multas, ações contra o município) corre no TJSP e já aparece nas
> buscas acima. Processos **administrativos** das prefeituras ficam em sistemas de cada município, que em geral
> não permitem busca pública por CPF.

## Requisitos da hospedagem

- PHP **8.0 ou superior**, com as extensões `curl`, `dom`, `mbstring` e `pdo_sqlite`. A maioria das
  hospedagens já vem com elas; confira no painel (cPanel → "Selecionar versão do PHP").
- Permissão para o PHP **fazer requisições externas** (cURL para `esaj.tjsp.jus.br` e `brasilapi.com.br`).
- Apache com `.htaccess` (padrão em cPanel/Hostinger/HostGator/Locaweb). Em Nginx, veja "Segurança" abaixo.

## Instalação

1. **Baixe o código.** No GitHub, abra a branch deste projeto e use **Code → Download ZIP**.
2. **Envie para a hospedagem.** Pelo Gerenciador de Arquivos ou FTP, crie uma pasta dentro de `public_html`
   (ex.: `public_html/processos`) e envie todo o conteúdo, **inclusive os arquivos `.htaccess`**. Alguns
   clientes de FTP escondem arquivos que começam com ponto.
3. **Crie o `config.php`.** Copie `config.exemplo.php` para `config.php`.
4. **Defina a senha.** Acesse `https://seudominio.com.br/processos/`. Na primeira vez aparece a tela
   "Definir senha de acesso". Digite uma senha (mínimo 10 caracteres) e o sistema mostra uma linha como
   `'senha_hash' => '$2y$10$...',`. Cole essa linha no `config.php`, no lugar de `'senha_hash' => '',`.
5. **(Opcional) Preencha seus documentos.** Em `'documentos'` e `'nomes'` do `config.php`, coloque seu CPF,
   CNPJs e nome completo. Eles já virão preenchidos no formulário.
6. **Verifique a pasta `dados/`.** Ela precisa permitir escrita (permissão 755 ou 775); é onde fica o histórico.

## Uso

Entre com a senha, confira os CPFs/CNPJs e clique em **Consultar**. Para cada documento aparecem:

- a empresa, no caso de CNPJ (razão social, situação e cidade, pela BrasilAPI);
- os processos, com número, classe, assunto, foro, data e link para abrir no TJSP;
- o selo **NOVO** nos processos que não tinham aparecido em consultas anteriores;
- avisos de erro, se alguma fonte falhar (CAPTCHA, site fora do ar, mudança de layout).

Opções do formulário:

- **Abrir cada processo:** traz partes, advogados e movimentações. É mais lento.
- **Buscar também pela razão social:** pega processos antigos de empresas sem CNPJ cadastrado.
- **Nomes completos:** idem para pessoas físicas.
- **Exportar:** depois da consulta, baixe uma **planilha CSV** (abre direto no Excel) ou **JSON**.

### Consulta automática (cron)

Se a hospedagem tiver **Tarefas Cron** (cPanel → Cron Jobs), dá para receber por e-mail só quando surgir
processo novo:

```
0 8 * * 1  php /home/SEU_USUARIO/public_html/processos/cli.php --somente-novos
```

Por SSH, `php cli.php --ajuda` mostra todas as opções. Por exemplo:

```bash
php cli.php                                    # usa os documentos do config.php
php cli.php 123.456.789-09 --nome="FULANO DE TAL" --detalhes
php cli.php --formato=csv > processos.csv
```

## Segurança

- O sistema inteiro é protegido por **senha**, guardada só como hash no `config.php`. Ele também tem
  proteção contra CSRF e espera alguns segundos após cada senha errada.
- Os `.htaccess` bloqueiam o acesso pelo navegador a `config.php`, `cli.php`, `src/`, `dados/` e `tests/`.
- Para mais segurança, aponte `'historico'` no `config.php` para uma pasta **fora** de `public_html`
  (ex.: `/home/SEU_USUARIO/consulta-dados/historico.db`).
- **Nginx** ignora `.htaccess`. Nesse caso, mova o histórico para fora da pasta pública, como acima, e bloqueie
  `/dados`, `/src` e `/tests` na configuração do servidor.
- Use **HTTPS** (Let's Encrypt é gratuito na maioria das hospedagens).

## Estrutura

```
index.php            interface web (login, formulário, resultados, exportação)
cli.php              linha de comando / cron
config.exemplo.php   modelo de configuração
src/Documento.php    validação de CPF/CNPJ (inclusive CNPJ alfanumérico, em vigor desde jul/2026)
src/EsajTjsp.php     busca no e-SAJ: listagem paginada, redirecionamento de resultado único, detalhes,
                     detecção de CAPTCHA e de "nenhum resultado"
src/Receita.php      dados do CNPJ via BrasilAPI
src/Historico.php    histórico SQLite para marcar processos novos
src/Consulta.php     orquestra documentos × fontes
src/Exportar.php     CSV (Excel), JSON e texto
tests/run.php        testes: php tests/run.php
```

Para adicionar outro tribunal, crie uma classe que estenda `Fonte` (`src/Fonte.php`) e registre-a em
`Consulta::FONTES`.

## Limitações

- **Ainda não testado contra o site real do TJSP.** Os testes usam páginas de exemplo em `tests/fixtures/`.
  Se aparecer "resposta inesperada do e-SAJ", salve a página da consulta feita no navegador e use-a para
  ajustar o parser.
- **Bloqueio de IPs de servidor.** Alguns tribunais bloqueiam ou pedem CAPTCHA para IPs de datacenter. Se isso
  acontecer na hospedagem, a ferramenta avisa e mostra o link para a consulta manual. Ela não tenta contornar o
  CAPTCHA.
- **Migração para o eproc.** O TJSP está migrando gradualmente para o eproc; uma fonte nova pode ser necessária.
- **Tempo de execução.** Algumas hospedagens limitam o tempo de cada página (30–60 s). Com muitos documentos ou
  com "Abrir cada processo" marcado, consulte menos documentos por vez ou use o `cli.php` pelo cron.
- **Segredo de justiça.** Esses processos não aparecem na consulta pública, ou aparecem sem detalhes.
- **Não substitui certidão.** Para fins formais, emita as certidões do TJSP, TRF3 e TRT2/TRT15.
- **Uso responsável.** Feito para consultar **seus próprios documentos** ou de quem autorizou; dados de
  terceiros estão sujeitos à LGPD.

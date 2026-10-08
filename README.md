# ConsultaCPFCNPJ

Ferramenta de linha de comando para encontrar **processos judiciais em que um CPF ou CNPJ figura como parte**.
A primeira versão consulta o **Tribunal de Justiça de São Paulo (TJSP)** via e-SAJ, nos dois graus:

| Fonte     | O que cobre |
|-----------|-------------|
| `tjsp-1g` | 1º grau: varas cíveis, criminais, família, Fazenda Pública, **execuções fiscais estaduais e municipais**, Juizados Especiais |
| `tjsp-2g` | 2º grau: recursos (apelações, agravos) e ações originárias |

> **E os processos "municipais"?** Municípios não têm Justiça própria. Toda ação judicial envolvendo uma
> prefeitura paulista (cobrança de IPTU/ISS, multas, ações contra o município) corre no TJSP, então já aparece
> nas buscas acima, em geral nas varas de Fazenda Pública ou de Execuções Fiscais Municipais. Processos
> **administrativos** das prefeituras (SEI, protocolos, dívida ativa não ajuizada) ficam em sistemas de cada
> município. Esses sistemas, de modo geral, não permitem busca pública por CPF e ficam para uma próxima etapa.

## Instalação

```bash
python -m venv .venv && source .venv/bin/activate
pip install -e ".[dev]"
```

## Uso

```bash
# Seu CPF e suas empresas
consulta buscar 123.456.789-09 12.345.678/0001-90

# Lendo de um arquivo (copie meus_documentos.exemplo.txt -> meus_documentos.txt)
consulta buscar -a meus_documentos.txt

# Com partes, advogados e movimentações de cada processo (mais lento)
consulta buscar -a meus_documentos.txt --detalhes

# Processos antigos às vezes não têm o documento cadastrado: busque também pelo nome
consulta buscar 123.456.789-09 --nome "FULANO DE TAL DA SILVA"
consulta buscar 12.345.678/0001-90 --incluir-razao-social   # usa a razão social da Receita

# Exportar
consulta buscar -a meus_documentos.txt --formato csv -o resultados.csv
consulta buscar -a meus_documentos.txt --formato json -o resultados.json

# Utilitários
consulta validar 123.456.789-09     # valida CPF/CNPJ (inclusive CNPJ alfanumérico)
consulta fontes                     # lista as fontes
```

Sem instalar, use `python -m consulta ...`.

### Acompanhamento (processos novos)

Cada consulta é gravada em `~/.consultacpfcnpj/historico.db` (SQLite). Nas consultas seguintes, processos
que ainda não tinham aparecido para aquele documento são marcados com **[NOVO]** (ou `"novo": true` no JSON).
Para monitorar, agende uma execução periódica, por exemplo no `cron`:

```cron
0 8 * * 1  cd ~/ConsultaCPFCNPJ && .venv/bin/consulta buscar -a meus_documentos.txt -o ~/processos.txt
```

Use `--sem-historico` para não gravar nada.

### Códigos de saída

`0` = sucesso, `1` = parâmetros inválidos, `2` = alguma fonte falhou. Nesse caso o erro aparece no relatório
com `!`, por exemplo CAPTCHA, site fora do ar ou mudança de layout.

## Como funciona

- `consulta/documentos.py`: valida e formata CPF/CNPJ, incluindo o **CNPJ alfanumérico** em vigor desde julho/2026.
- `consulta/fontes/tjsp_esaj.py`: faz a busca pública do e-SAJ (`cbPesquisa=DOCPARTE`/`NMPARTE`, todos os foros),
  segue a paginação e trata o redirecionamento direto quando há um único processo. Também detecta CAPTCHA,
  "nenhum resultado" e respostas inesperadas.
- `consulta/receita.py`: dados cadastrais do CNPJ (razão social, situação, sócios) pela [BrasilAPI](https://brasilapi.com.br).
- `consulta/historico.py`: histórico local para destacar processos novos.
- `consulta/cli.py`: linha de comando e saídas em tabela, JSON e CSV.

Para adicionar outro tribunal, crie uma subclasse de `Fonte` (`consulta/fontes/base.py`) com `buscar_documento`,
`buscar_nome` e `detalhar`, e registre-a em `consulta/fontes/__init__.py`.

## Limitações e cuidados

- **Processos em segredo de justiça** (família, alguns criminais) não aparecem ou aparecem sem detalhes na
  consulta pública. Para vê-los, acesse o e-SAJ com certificado digital ou peça uma **certidão de distribuição**
  no próprio TJSP.
- **O layout do e-SAJ pode mudar.** Além disso, o TJSP está migrando gradualmente para o **eproc**. Se uma consulta
  retornar "resposta inesperada", provavelmente será preciso ajustar o parser ou acrescentar uma fonte eproc.
  Os testes (`pytest`) usam páginas de exemplo em `tests/fixtures/`, que podem ser atualizadas com HTML real.
- **CAPTCHA:** se o tribunal exigir, a ferramenta avisa e indica o endereço para consulta manual. Ela não tenta
  contornar o CAPTCHA.
- **Uso responsável:** a ferramenta espera 1,5 s entre requisições (`--atraso`). Ela foi feita para consultar os
  **seus próprios documentos** ou documentos de quem autorizou. Dados de terceiros estão sujeitos à LGPD.
- Esta ferramenta não substitui uma certidão oficial. Para fins formais (licitações, financiamentos etc.), emita
  as certidões do TJSP, da Justiça Federal (TRF3) e da Justiça do Trabalho (TRT2/TRT15).

## Próximos passos sugeridos

- Justiça Federal da 3ª Região (TRF3, PJe) e Justiça do Trabalho (TRT2/TRT15).
- Fonte eproc do TJSP, à medida que a migração avançar.
- Emissão automática de certidões e notificação por e-mail quando surgir processo novo.

## Testes

```bash
pytest
```

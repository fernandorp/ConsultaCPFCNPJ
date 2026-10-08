<?php

namespace Consulta;

class ErroFonte extends \RuntimeException
{
}

/** A fonte exigiu CAPTCHA; a consulta precisa ser feita manualmente no navegador. */
class CaptchaRequerido extends ErroFonte
{
}

class Resposta
{
    public $url;
    public $texto;
    public $status;

    public function __construct(string $url, string $texto, int $status = 200)
    {
        $this->url = $url;
        $this->texto = $texto;
        $this->status = $status;
    }
}

interface ClienteHttp
{
    /** @throws ErroFonte em falha de rede ou status HTTP de erro */
    public function get(string $url, array $params = []): Resposta;
}

/** Cliente cURL com cookies em memória e intervalo mínimo entre requisições. */
class Http implements ClienteHttp
{
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/126.0 Safari/537.36 ConsultaCPFCNPJ/0.2';

    private $curl;
    private $atraso;
    private $ultimaRequisicao = 0.0;

    public function __construct(float $atraso = 1.5, int $timeout = 30)
    {
        $this->atraso = $atraso;
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_COOKIEFILE => '', // ativa cookies em memória (sessão do e-SAJ)
            CURLOPT_ENCODING => '',
        ]);
    }

    public function get(string $url, array $params = []): Resposta
    {
        // Respeita um intervalo mínimo entre requisições para não sobrecarregar o tribunal.
        $espera = $this->atraso - (microtime(true) - $this->ultimaRequisicao);
        if ($espera > 0) {
            usleep((int) ($espera * 1e6));
        }
        if ($params) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }
        curl_setopt($this->curl, CURLOPT_URL, $url);
        $corpo = curl_exec($this->curl);
        $this->ultimaRequisicao = microtime(true);

        if ($corpo === false) {
            throw new ErroFonte("falha ao acessar $url: " . curl_error($this->curl));
        }
        $status = (int) curl_getinfo($this->curl, CURLINFO_RESPONSE_CODE);
        if ($status >= 400) {
            throw new ErroFonte("falha ao acessar $url: HTTP $status");
        }
        return new Resposta((string) curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL), $corpo, $status);
    }
}

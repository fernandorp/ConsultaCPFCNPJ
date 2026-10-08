<?php
/**
 * Validação, normalização e formatação de CPF e CNPJ.
 *
 * Suporta o CNPJ alfanumérico (IN RFB nº 2.229/2024), em vigor desde julho de 2026:
 * as 12 primeiras posições podem conter letras maiúsculas e os dígitos verificadores
 * continuam numéricos. O cálculo usa o código ASCII do caractere menos 48, o que
 * preserva o resultado para CNPJs só numéricos.
 */

namespace Consulta;

class DocumentoInvalido extends \InvalidArgumentException
{
}

class Documento
{
    private const PESOS_CNPJ = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** @var string "CPF" ou "CNPJ" */
    public $tipo;
    /** @var string apenas dígitos/letras, sem pontuação */
    public $numero;

    public function __construct(string $tipo, string $numero)
    {
        $this->tipo = $tipo;
        $this->numero = $numero;
    }

    public function formatado(): string
    {
        $n = $this->numero;
        if ($this->tipo === 'CPF') {
            return substr($n, 0, 3) . '.' . substr($n, 3, 3) . '.' . substr($n, 6, 3) . '-' . substr($n, 9);
        }
        return substr($n, 0, 2) . '.' . substr($n, 2, 3) . '.' . substr($n, 5, 3) . '/' . substr($n, 8, 4) . '-' . substr($n, 12);
    }

    public function __toString(): string
    {
        return $this->tipo . ' ' . $this->formatado();
    }

    public static function limpar(string $valor): string
    {
        return strtoupper(preg_replace('/[.\-\/\s]/', '', $valor));
    }

    private static function dvCpf(string $base): string
    {
        foreach ([9, 10] as $tamanho) {
            $soma = 0;
            for ($i = 0; $i < $tamanho; $i++) {
                $soma += (int) $base[$i] * ($tamanho + 1 - $i);
            }
            $resto = $soma % 11;
            $base = substr($base, 0, $tamanho) . ($resto < 2 ? 0 : 11 - $resto);
        }
        return substr($base, 9, 2);
    }

    private static function dvCnpj(string $base): string
    {
        foreach ([12, 13] as $tamanho) {
            $pesos = array_slice(self::PESOS_CNPJ, 13 - $tamanho);
            $soma = 0;
            for ($i = 0; $i < $tamanho; $i++) {
                $soma += (ord($base[$i]) - 48) * $pesos[$i];
            }
            $resto = $soma % 11;
            $base = substr($base, 0, $tamanho) . ($resto < 2 ? 0 : 11 - $resto);
        }
        return substr($base, 12, 2);
    }

    public static function validarCpf(string $valor): bool
    {
        $n = self::limpar($valor);
        if (!preg_match('/^\d{11}$/', $n) || $n === str_repeat($n[0], 11)) {
            return false;
        }
        return self::dvCpf($n) === substr($n, 9);
    }

    public static function validarCnpj(string $valor): bool
    {
        $n = self::limpar($valor);
        if (!preg_match('/^[0-9A-Z]{12}\d{2}$/', $n) || $n === str_repeat($n[0], 14)) {
            return false;
        }
        return self::dvCnpj($n) === substr($n, 12);
    }

    /** Detecta se o valor é CPF ou CNPJ válido; lança DocumentoInvalido caso contrário. */
    public static function identificar(string $valor): self
    {
        $n = self::limpar($valor);
        if (strlen($n) === 11 && self::validarCpf($n)) {
            return new self('CPF', $n);
        }
        if (strlen($n) === 14 && self::validarCnpj($n)) {
            return new self('CNPJ', $n);
        }
        throw new DocumentoInvalido("CPF/CNPJ inválido: \"$valor\"");
    }
}

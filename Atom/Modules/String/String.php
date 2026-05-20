<?php

namespace App\Utils;

/**
 * Klasa do obsługi operacji na ciągach znaków.
 */
class StringMasterUtils
{
    private array $config;
    public const string VERSION = "1.0.0";

    /**
     * Konstruktor z wstrzykiwaniem zależności.
     * * @param array $config Tablica obiektów lub ustawień konfiguracyjnych.
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Główna metoda uruchamiająca logikę pakietu.
     * * @param string $input Ciąg znaków do przetworzenia.
     * @return string Przetworzony wynik.
     */
    public function run(string $input): string
    {
        // Przykład prostej logiki wykorzystującej wstrzykniętą konfigurację
        $prefix = $this->config['prefix'] ?? '';

        return $prefix . strrev($input);
    }
}

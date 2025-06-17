<?php
/**
 * Łatwe „ładne” debugowanie – wystarczy wywołać dbg($zmienna);
 *
 *  • pokazuje plik i linię, z której wywołano dbg()
 *  • dokleja dokładny czas (HH:MM:SS.milisek)
 *  • wypisuje wartość przez print_r (dla skalarnych i tablic) lub var_dump (dla obiektów)
 *  • działa zarówno w CLI, jak i w przeglądarce (kolor w HTML)
 */

function dbg(mixed $val)
{
    // 1) miejsce wywołania
    $trace   = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
    $file    = $trace['file'] ?? '??';
    $line    = $trace['line'] ?? 0;

    // 2) czas z milisekundami
    [$sec, $ms] = explode('.', sprintf('%.6f', microtime(true)));
    $timestamp  = date('H:i:s', (int) $sec) . '.' . substr($ms, 0, 3);

    // 3) czy wyjście HTML-owe?
    $isHtml  = (PHP_SAPI !== 'cli');
    $start   = $isHtml ? '<pre style="background:#222;color:#9ef;padding:6px;border-radius:4px;">' : '';
    $end     = $isHtml ? '</pre>' : '';

    // 4) nagłówek
    echo $start .
         "📍  {$file}:{$line}\n" .
         "⏰  {$timestamp}\n" .
         "—".str_repeat('─', 60)."\n";

    // var_dump($val);
    print_r($val);

    echo "\n".$end;

    return $val;
}

function appendIdIfNew(string $path, string|int $id): void
{
    // 'c+'  ➜   otwórz do R/W, utwórz plik jeśli nie istnieje
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException("Nie mogę otworzyć pliku: $path");
    }

    // pełna blokada pliku – inni poczekają
    flock($fp, LOCK_EX);

    // sprawdź, czy ID jest już w pliku
    $exists = false;
    rewind($fp);                          // na początek pliku
    while (($line = fgets($fp)) !== false) {
        if (trim($line) === (string)$id) {
            $exists = true;
            break;
        }
    }

    // jeśli brak – dopisz na koniec
    if (!$exists) {
        fseek($fp, 0, SEEK_END);          // skok na koniec
        fwrite($fp, $id . PHP_EOL);
    }

    // odblokuj i zamknij
    flock($fp, LOCK_UN);
    fclose($fp);
}


/**
 * Rozdziela NIP/VAT-ID na:
 *   • country – dwuliterowy prefiks kraju (lub '')
 *   • number  – same cyfry
 *   • pure    – oryginał bez spacji (do logów / klucza)
 *
 * Akceptuje:
 *   DK37538795   DK 37538795   37538795
 *
 * @throws InvalidArgumentException – gdy format nie pasuje
 */
function splitVatId(string $raw): array
{
    $raw  = trim($raw);
    $pure = preg_replace('/\s+/', '', $raw);   // wywal wszystkie białe znaki

    // A) prefiks kraju + (opcjonalne spacje) + cyfry
    if (preg_match('/^([A-Z]{2})\s*(\d+)$/i', $raw, $m)) {
        return [
            'country' => strtoupper($m[1]),
            'number'  => $m[2],
            'pure'    => $pure,
        ];
    }

    // B) same cyfry
    if (preg_match('/^\d+$/', $raw)) {
        return [
            'country' => '',
            'number'  => $pure,   // tu pure == number
            'pure'    => $pure,
        ];
    }

    return ['country' => '', 'number' => '', 'pure' => '' ];
    // throw new InvalidArgumentException("Niepoprawny format NIP/VAT ID: $raw");
}

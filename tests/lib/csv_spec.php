<?php
declare(strict_types=1);

/**
 * Spesifikasi offline untuk render CSV yang aman.
 *
 * Ini BUKAN helper ekspor produksi. Test yang memakai helper ini hanya
 * memverifikasi perilaku spesifikasi offline, bukan keberadaan fitur ekspor
 * CSV di aplikasi.
 */

/**
 * Menetralkan formula injection pada satu sel.
 *
 * Nilai yang setelah whitespace/tab diawali =, +, -, atau @ diberi awalan
 * apostrof agar tidak dieksekusi sebagai formula oleh aplikasi spreadsheet.
 */
function csv_spec_cell(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $text = $value ? '1' : '0';
    } elseif (is_scalar($value)) {
        $text = (string) $value;
    } else {
        throw new InvalidArgumentException('Sel CSV harus berupa nilai skalar atau null.');
    }

    $trimmed = ltrim($text, " \t\n\r\v\f");

    if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
        return "'" . $text;
    }

    return $text;
}

/**
 * Merender header dan baris menjadi CSV dengan newline LF yang konsisten.
 *
 * @param array<int, mixed> $headers
 * @param array<int, mixed> $rows
 */
function csv_spec_render(array $headers, array $rows): string
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Tidak dapat membuat stream CSV sementara.');
    }

    try {
        $writeRow = static function (array $fields) use ($stream): void {
            $safeFields = [];
            foreach ($fields as $field) {
                $safeFields[] = csv_spec_cell($field);
            }

            if (fputcsv($stream, $safeFields, ',', '"', '', "\n") === false) {
                throw new RuntimeException('Gagal menulis baris CSV.');
            }
        };

        $writeRow($headers);

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Baris CSV indeks ' . $index . ' harus berupa array.');
            }

            $writeRow(array_values($row));
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            throw new RuntimeException('Gagal membaca hasil CSV.');
        }

        return $contents;
    } finally {
        fclose($stream);
    }
}

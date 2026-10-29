<?php
declare(strict_types=1);

/**
 * Spesifikasi offline untuk estimasi jumlah ikan.
 *
 * Ini BUKAN fungsi produksi dan tidak membuktikan implementasi produksi
 * sudah benar. Helper ini hanya menjadi oracle untuk test offline.
 *
 * Aturan:
 * - estimasi = stok awal - mortalitas aktif - panen aktif berdasarkan ekor
 * - panen berat tidak mengurangi estimasi
 * - record berstatus cancelled tidak dihitung
 * - nilai negatif, tipe salah, status salah, dan jenis panen salah ditolak
 * - estimasi di bawah nol dikembalikan sebagai hasil tidak valid, bukan
 *   dipotong menjadi nol
 *
 * @param mixed $initial
 * @param array<int, mixed> $mortalities
 * @param array<int, mixed> $harvests
 * @return array{
 *     ok: bool,
 *     estimate: int,
 *     initial: int,
 *     mortality: int,
 *     harvest_count: int,
 *     reason: string|null
 * }
 */
function fish_estimate_spec(mixed $initial, array $mortalities, array $harvests): array
{
    if (!is_int($initial) || $initial < 0) {
        throw new InvalidArgumentException('Stok awal harus bilangan bulat non-negatif.');
    }

    $mortalityTotal = 0;

    foreach ($mortalities as $index => $record) {
        if (!is_array($record)) {
            throw new InvalidArgumentException('Record mortalitas indeks ' . $index . ' harus berupa array.');
        }

        $status = $record['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'cancelled'], true)) {
            throw new InvalidArgumentException('Status mortalitas indeks ' . $index . ' tidak valid.');
        }

        $count = $record['count'] ?? null;
        if (!is_int($count) || $count < 0) {
            throw new InvalidArgumentException('Jumlah mortalitas indeks ' . $index . ' harus bilangan bulat non-negatif.');
        }

        if ($status === 'active') {
            $mortalityTotal += $count;
        }
    }

    $harvestCount = 0;

    foreach ($harvests as $index => $record) {
        if (!is_array($record)) {
            throw new InvalidArgumentException('Record panen indeks ' . $index . ' harus berupa array.');
        }

        $status = $record['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['active', 'cancelled'], true)) {
            throw new InvalidArgumentException('Status panen indeks ' . $index . ' tidak valid.');
        }

        $kind = $record['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, ['count', 'weight'], true)) {
            throw new InvalidArgumentException('Jenis panen indeks ' . $index . ' tidak valid.');
        }

        if ($kind !== 'count') {
            continue;
        }

        $count = $record['count'] ?? null;
        if (!is_int($count) || $count < 0) {
            throw new InvalidArgumentException('Jumlah panen ekor indeks ' . $index . ' harus bilangan bulat non-negatif.');
        }

        if ($status === 'active') {
            $harvestCount += $count;
        }
    }

    $estimate = $initial - $mortalityTotal - $harvestCount;

    return [
        'ok' => $estimate >= 0,
        'estimate' => $estimate,
        'initial' => $initial,
        'mortality' => $mortalityTotal,
        'harvest_count' => $harvestCount,
        'reason' => $estimate < 0 ? 'negative_estimate' : null,
    ];
}

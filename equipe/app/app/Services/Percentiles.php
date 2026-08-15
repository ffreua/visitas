<?php

namespace App\Services;

/**
 * SQLite não tem PERCENTILE_CONT nativo — calculamos em PHP sobre os
 * valores já filtrados pela query (volume esperado: um serviço hospitalar
 * de Neurologia, não milhões de linhas).
 */
class Percentiles
{
    /**
     * @param  array<int, float>  $values
     * @return array{p25: ?float, median: ?float, p75: ?float, p90: ?float}
     */
    public static function summarize(array $values): array
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        sort($values);

        if (count($values) === 0) {
            return ['p25' => null, 'median' => null, 'p75' => null, 'p90' => null];
        }

        return [
            'p25' => self::percentile($values, 0.25),
            'median' => self::percentile($values, 0.5),
            'p75' => self::percentile($values, 0.75),
            'p90' => self::percentile($values, 0.9),
        ];
    }

    private static function percentile(array $sorted, float $fraction): float
    {
        $count = count($sorted);
        if ($count === 1) {
            return round($sorted[0], 2);
        }

        $index = $fraction * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return round($sorted[$lower], 2);
        }

        $weight = $index - $lower;

        return round($sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * $weight, 2);
    }
}

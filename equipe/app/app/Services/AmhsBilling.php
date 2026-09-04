<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Pagadores cujo atendimento da equipe é faturado pela AMHS, e não pelo
 * próprio convênio.
 *
 * É regra de FATURAMENTO, não de cadastro: o episódio continua com o plano
 * que tem: o que muda é para onde a conta vai. Por isso mora aqui e não em
 * HealthPlan — nenhum cadastro é alterado, nada precisa de migration, e
 * incluir um pagador novo é acrescentar um item à lista abaixo.
 *
 * A comparação é por TRECHO de um nome reduzido a letras e números
 * (sem acento, minúsculas, sem espaço nem pontuação) porque o nome
 * cadastrado varia com o tempo e entre digitações: "Bradesco",
 * "Bradesco Saúde" e "BRADESCO SAUDE" são o mesmo pagador para a AMHS,
 * assim como "Allianz (AGF)" e "Allianz Saúde", ou "Saúde Caixa" e
 * "SaudeCaixa".
 */
class AmhsBilling
{
    public const LABEL = 'Cobrar via AMHS';

    /**
     * Trechos já reduzidos (só letras/números, minúsculas): basta um estar
     * contido no nome reduzido do pagador.
     */
    private const NEEDLES = [
        'allianz',
        'bradesco',
        'cassi',
        'particular',
        'saudecaixa',
        'unafisco',
        'sindfisco',
        'mediservice',
    ];

    public static function applies(?string $payerType, ?string $payerName): bool
    {
        // "Particular" está na lista e não é um convênio: é o payer_type
        // PRIVATE, que por definição não tem nome de plano associado.
        if ($payerType === 'PRIVATE') {
            return true;
        }

        $reduced = self::reduce($payerName);

        if ($reduced === '') {
            return false;
        }

        foreach (self::NEEDLES as $needle) {
            if (str_contains($reduced, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function reduce(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();
    }
}

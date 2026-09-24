<?php

namespace App\Domain\Preview;

use App\Models\Project;
use NumberFormatter;

/**
 * Preview stand-in for WooCommerce's price_html: localised currency, and
 * <del>regular</del> <ins>sale</ins> when on sale. Output is built from numbers
 * only, so it is safe for the allowlisted {{{price_html}}} tag.
 */
final class PriceFormatter
{
    private ?NumberFormatter $formatter = null;

    public function __construct(private readonly Project $project)
    {
        if (class_exists(NumberFormatter::class)) {
            $this->formatter = new NumberFormatter($project->language, NumberFormatter::CURRENCY);
        }
    }

    public function format(float $amount): string
    {
        $currency = $this->project->currency ?: 'USD';
        $formatted = $this->formatter?->formatCurrency($amount, $currency);

        return e($formatted !== false && $formatted !== null ? $formatted : number_format($amount, 2).' '.$currency);
    }

    public function html(?string $regular, ?string $sale): string
    {
        if ($regular === null && $sale === null) {
            return '';
        }

        if ($sale !== null && $regular !== null && (float) $sale < (float) $regular) {
            return '<del>'.$this->format((float) $regular).'</del> <ins>'.$this->format((float) $sale).'</ins>';
        }

        return $this->format((float) ($regular ?? $sale));
    }
}

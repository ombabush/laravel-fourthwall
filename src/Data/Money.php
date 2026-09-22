<?php

namespace Ombabush\Fourthwall\Data;

use JsonSerializable;
use Stringable;

/**
 * An amount of money: integer minor units and a currency code.
 *
 * Never a float. Fourthwall sends `unitPrice.value` as a JSON number, and
 * `19.93` held in a float is 19.929999999999999715782905696… — which is
 * harmless until two of them are added, compared or multiplied by a quantity.
 * The conversion happens once, here, at the edge; everything past this class
 * counts cents.
 */
final class Money implements JsonSerializable, Stringable
{
    /** Currencies with no minor unit among the ones Fourthwall offers. */
    private const ZERO_DECIMAL = ['JPY'];

    public function __construct(
        public readonly int $minor,
        public readonly string $currency,
    ) {}

    /**
     * From a decimal as the APIs send it: 19.93, "19.93", "20.00 USD".
     */
    public static function fromDecimal(int|float|string $value, string $currency): self
    {
        $currency = strtoupper($currency);

        // A string is parsed as a string, so "19.93" never passes through a
        // float at all. A float is rounded to the currency's precision, which
        // is exact for any value that was written with that many decimals.
        if (is_string($value)) {
            $value = trim(preg_replace('/[^0-9.\-]/', '', $value));
            $negative = str_starts_with($value, '-');
            [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
            $digits = self::digits($currency);
            $fraction = substr(str_pad($fraction, $digits, '0'), 0, $digits);
            $minor = (int) ($whole === '' ? '0' : $whole) * (10 ** $digits) + (int) ($fraction === '' ? '0' : $fraction);

            return new self($negative ? -$minor : $minor, $currency);
        }

        return new self((int) round($value * (10 ** self::digits($currency))), $currency);
    }

    public static function digits(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** "19.93" — for forms, feeds and anything that wants a plain number. */
    public function decimal(): string
    {
        $digits = self::digits($this->currency);

        return $digits === 0
            ? (string) $this->minor
            : number_format($this->minor / (10 ** $digits), $digits, '.', '');
    }

    /**
     * "$19.93", or "19,93 $" for a Russian reader when ext-intl is there.
     *
     * Whole amounts drop their zeros — "$15", not "$15.00" — because a price
     * tag is read, not reconciled. Pass `$exact` to keep them.
     */
    public function format(?string $locale = null, bool $exact = false): string
    {
        $locale ??= function_exists('app') && app()->bound('translator') ? app()->getLocale() : 'en';
        $digits = self::digits($this->currency);
        $whole = $this->minor % (10 ** $digits) === 0;

        if (class_exists(\NumberFormatter::class)) {
            $f = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

            if ($whole && ! $exact) {
                $f->setAttribute(\NumberFormatter::FRACTION_DIGITS, 0);
            }

            $out = $f->formatCurrency($this->minor / (10 ** $digits), $this->currency);

            if ($out !== false) {
                // Some locales write «US$»; on a page that only ever shows one
                // currency, the plain symbol is what people expect.
                return str_replace(['US$', ' '], ['$', ' '], $out);
            }
        }

        $symbol = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'INR' => '₹', 'PLN' => 'zł'][$this->currency] ?? null;
        $number = ($whole && ! $exact) ? number_format($this->minor / (10 ** $digits)) : number_format($this->minor / (10 ** $digits), $digits);

        return $symbol ? $symbol.$number : $number.' '.$this->currency;
    }

    public function plus(self $other): self
    {
        $this->sameCurrency($other);

        return new self($this->minor + $other->minor, $this->currency);
    }

    public function times(int $quantity): self
    {
        return new self($this->minor * $quantity, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->sameCurrency($other);

        return $this->minor <=> $other->minor;
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    private function sameCurrency(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new \InvalidArgumentException("Cannot mix {$this->currency} and {$other->currency}.");
        }
    }

    public function toArray(): array
    {
        return ['minor' => $this->minor, 'currency' => $this->currency];
    }

    public static function fromArray(?array $a): ?self
    {
        return $a === null ? null : new self((int) $a['minor'], (string) $a['currency']);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray() + ['decimal' => $this->decimal(), 'formatted' => $this->format()];
    }

    public function __toString(): string
    {
        return $this->format();
    }
}

<?php

namespace App\Support\CrossBorder;

use App\Support\Market;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * What the person actually asked for, read from their own messages: the
 * amounts (with a currency when they named one) and the phone numbers.
 *
 * The Send Money agent may only quote or start a transfer for an amount and a
 * number the person said. It can't fill gaps from earlier transfers.
 */
final class TransferIntent
{
    /**
     * Words and symbols people use for a currency. "kwacha" and "K" depend
     * on the country, so they're resolved against it.
     */
    private const NAMES = [
        'usd' => 'USD', 'us$' => 'USD', '$' => 'USD', 'dollar' => 'USD', 'dollars' => 'USD', 'bucks' => 'USD',
        'eur' => 'EUR', '€' => 'EUR', 'euro' => 'EUR', 'euros' => 'EUR',
        'gbp' => 'GBP', '£' => 'GBP', 'pound' => 'GBP', 'pounds' => 'GBP',
        'cad' => 'CAD', 'aud' => 'AUD',
        'zmw' => 'ZMW', 'mwk' => 'MWK', 'kes' => 'KES', 'ksh' => 'KES', 'ugx' => 'UGX', 'tzs' => 'TZS',
        'ghs' => 'GHS', 'rwf' => 'RWF', 'xof' => 'XOF', 'xaf' => 'XAF', 'ngn' => 'NGN', 'zar' => 'ZAR',
        'shilling' => 'SHILLING', 'shillings' => 'SHILLING',
        'kwacha' => 'KWACHA', 'k' => 'KWACHA',
    ];

    /**
     * @param  list<array{amount: BigDecimal, currency: ?string}>  $amounts  oldest first
     * @param  list<string>  $phones  digits only, oldest first
     */
    private function __construct(public readonly array $amounts, public readonly array $phones) {}

    /**
     * @param  iterable<string>  $messages  the person's messages, oldest first
     */
    public static function fromMessages(iterable $messages): self
    {
        $amounts = [];
        $phones = [];

        foreach ($messages as $message) {
            $text = mb_strtolower((string) $message);

            // Phone numbers first, so their digits aren't read as amounts.
            if (preg_match_all('/\+?\d[\d\s\-]{7,}\d/u', $text, $found)) {
                foreach ($found[0] as $number) {
                    $digits = preg_replace('/\D/', '', $number);

                    if (strlen($digits) >= 9) {
                        $phones[] = $digits;
                        $text = str_replace($number, ' ', $text);
                    }
                }
            }

            $amounts = [...$amounts, ...self::amountsIn($text)];
        }

        return new self($amounts, $phones);
    }

    /**
     * The amount the person asked for most recently.
     *
     * @return array{amount: BigDecimal, currency: ?string}|null
     */
    public function latestAmount(): ?array
    {
        return $this->amounts === [] ? null : $this->amounts[array_key_last($this->amounts)];
    }

    /**
     * Why a proposed transfer doesn't match what the person asked, or null
     * when it does.
     */
    public function mismatch(string|float|int $amount, string $mode, string $country, ?string $sourceCurrency, ?string $accountNumber = null): ?string
    {
        $asked = $this->latestAmount();

        if ($asked === null) {
            return 'The user has not said how much to send in this conversation. Ask them for the amount; never reuse an amount from an earlier transfer.';
        }

        $target = Market::currency(strtoupper($country));
        $source = strtoupper($sourceCurrency ?: 'USD');
        $currency = self::resolve($asked['currency'], $target);

        try {
            $proposed = BigDecimal::of((string) $amount);
        } catch (MathException) {
            return 'The amount is not a number.';
        }

        $said = $asked['amount'].($currency ? " {$currency}" : '');

        if (! $proposed->isEqualTo($asked['amount'])) {
            return "The user asked for {$said}, not {$proposed}. Quote exactly what they asked for.";
        }

        if ($currency !== null) {
            if ($currency === $target && $mode !== 'target') {
                return "The user asked for {$said}, which is what the recipient receives: use mode \"target\".";
            }

            if ($currency !== $target && $currency !== $source) {
                return $mode === 'source'
                    ? "The user asked for {$said}: set source_currency to {$currency}."
                    : "The user asked for {$said}, which the recipient can't receive in ".Market::name(strtoupper($country)).": use mode \"source\" with source_currency {$currency}.";
            }

            if ($currency === $source && $currency !== $target && $mode !== 'source') {
                return "The user asked for {$said}, which is what the sender pays: use mode \"source\".";
            }
        }

        if ($accountNumber !== null) {
            if ($this->phones === []) {
                return 'The user has not given the recipient\'s mobile number in this conversation. Ask for it; never reuse a number from an earlier transfer.';
            }

            $digits = preg_replace('/\D/', '', $accountNumber);
            $matches = collect($this->phones)->contains(fn (string $phone): bool => substr($phone, -9) === substr($digits, -9));

            if (! $matches) {
                return 'The user did not give that mobile number. Use the number they gave, or ask them for it.';
            }
        }

        return null;
    }

    /**
     * @return list<array{amount: BigDecimal, currency: ?string}>
     */
    private static function amountsIn(string $text): array
    {
        $names = implode('|', array_map(fn (string $name): string => preg_quote($name, '/'), array_keys(self::NAMES)));
        $number = '(\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?)';
        $found = [];

        // "K2,500", "$20", "usd 20" or "20 kwacha", "20usd", or a bare number.
        $pattern = "/(?:(?<pre>{$names})\s?{$number}|{$number}\s?(?<post>{$names})\b|(?<![\w.]){$number}(?![\w.]))/u";

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $value = $match[2] ?? '' ?: ($match[3] ?? '' ?: ($match[5] ?? ''));
            $name = ($match['pre'] ?? '') ?: ($match['post'] ?? '');

            if ($value === '') {
                continue;
            }

            try {
                $found[] = ['amount' => BigDecimal::of(str_replace(',', '', $value)), 'currency' => $name !== '' ? self::NAMES[$name] : null];
            } catch (MathException) {
                continue;
            }
        }

        return $found;
    }

    /**
     * A named currency in terms of the recipient's country: "kwacha" is ZMW in
     * Zambia and MWK in Malawi; "shilling" is the local shilling.
     */
    private static function resolve(?string $currency, ?string $target): ?string
    {
        return match ($currency) {
            'KWACHA' => in_array($target, ['ZMW', 'MWK'], true) ? $target : 'ZMW',
            'SHILLING' => in_array($target, ['KES', 'UGX', 'TZS'], true) ? $target : 'KES',
            default => $currency,
        };
    }
}

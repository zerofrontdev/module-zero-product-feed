<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model\Format;

/**
 * Where a format writes its XML: memory (render() returns it), or a file
 * (`file` in the options: written as it goes, flushed every FLUSH offers, so
 * a feed of 100 000 products never sits in memory; render() returns '').
 * Every string goes through clean() first (cleanCatalog()): XML 1.0 forbids
 * most control characters, and one pasted into a description made the whole
 * feed unreadable.
 */
class Writer
{
    public const FLUSH = 200;

    /**
     * Valid UTF-8 without the characters XML 1.0 forbids (control characters but tab and newlines, U+FFFE/U+FFFF).
     */
    public static function clean(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');

        return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
    }

    /**
     * The catalogue with every string cleaned: the shop and the categories at once, the offers as they are read (a
     * generator stays one), the names of their params too.
     *
     * @param array{shop: array, categories: array, offers: iterable} $catalog
     * @return array{shop: array, categories: array, offers: iterable}
     */
    public static function cleanCatalog(array $catalog): array
    {
        $catalog['shop'] = self::cleanValue($catalog['shop'] ?? []);
        $catalog['categories'] = self::cleanValue($catalog['categories'] ?? []);
        $catalog['offers'] = self::cleanOffers($catalog['offers'] ?? []);

        return $catalog;
    }

    /**
     * @param iterable<array<string, mixed>> $offers
     * @return \Generator<array<string, mixed>>
     */
    private static function cleanOffers(iterable $offers): \Generator
    {
        foreach ($offers as $key => $offer) {
            yield $key => self::cleanValue($offer);
        }
    }

    /**
     * Strings cleaned, arrays (their string keys too) cleaned all the way down; anything else as it is.
     */
    private static function cleanValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::clean($value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? self::clean($key) : $key] = self::cleanValue($item);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function open(\XMLWriter $xml, array $options): void
    {
        if (!empty($options['file'])) {
            if (!$xml->openUri((string) $options['file'])) {
                throw new \RuntimeException('Cannot write ' . $options['file']);
            }
            return;
        }
        $xml->openMemory();
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function flushEvery(\XMLWriter $xml, array $options): void
    {
        static $count = 0;
        if (!empty($options['file']) && ++$count % self::FLUSH === 0) {
            $xml->flush();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function close(\XMLWriter $xml, array $options): string
    {
        if (!empty($options['file'])) {
            $xml->flush();
            return '';
        }
        return $xml->outputMemory();
    }
}

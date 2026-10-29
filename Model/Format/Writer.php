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
 */
class Writer
{
    public const FLUSH = 200;

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

<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model\Format;

/**
 * One marketplace's feed: Model\Catalog's data as that marketplace's XML.
 */
interface FormatInterface
{
    /**
     * @param array{shop: array, categories: array, offers: iterable} $catalog Model\Catalog::build()
     * @param array<string, mixed> $options the format's own, and `file`: write there (Format\Writer) and return ''
     */
    public function render(array $catalog, array $options = []): string;
}

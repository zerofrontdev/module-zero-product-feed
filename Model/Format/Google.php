<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model\Format;

/**
 * Google Merchant Center feed (RSS 2.0, g: fields), also read by Meta's
 * catalogs. Each size/color is an item with item_group_id; brand + mpn (SKU)
 * instead of GTINs; on sale, price is regular and sale_price the paid one.
 */
class Google implements FormatInterface
{
    private const NS = 'http://base.google.com/ns/1.0';
    private const TITLE_MAX = 150;
    private const DESCRIPTION_MAX = 5000;
    private const ADDITIONAL_IMAGES_MAX = 10;
    /** Attribute codes Google has fields for. */
    private const OPTIONS = ['size' => 'size', 'color' => 'color', 'material' => 'material', 'pattern' => 'pattern'];

    /**
     * @inheritDoc
     */
    public function render(array $catalog, array $options = []): string
    {
        $shop = $catalog['shop'];
        $paths = $this->categoryPaths($catalog['categories']);
        $xml = new \XMLWriter();
        Writer::open($xml, $options);
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss');
        $xml->writeAttribute('version', '2.0');
        $xml->writeAttribute('xmlns:g', self::NS);
        $xml->startElement('channel');
        $xml->writeElement('title', $shop['name']);
        $xml->writeElement('link', $shop['url']);
        $xml->writeElement('description', $shop['name']);
        foreach ($catalog['offers'] as $offer) {
            Writer::flushEvery($xml, $options);
            $this->item($xml, $offer, $shop['currency'], $paths);
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return Writer::close($xml, $options);
    }

    /**
     * @param array<string, mixed> $offer
     * @param array<int, string> $paths
     */
    private function item(\XMLWriter $xml, array $offer, string $currency, array $paths): void
    {
        $g = fn (string $name, string $value) => $value === '' ? null : $xml->writeElementNs('g', $name, null, $value);
        $title = $offer['name'];
        if ($offer['params']) {
            $title .= ' (' . implode(', ', $offer['params']) . ')';
        }
        $xml->startElement('item');
        $g('id', $offer['id']);
        $g('title', $this->cut($title, self::TITLE_MAX));
        $g('description', $this->cut($this->text($offer['description']) ?: $offer['name'], self::DESCRIPTION_MAX));
        $g('link', $offer['url']);
        $pictures = $offer['pictures'];
        $g('image_link', (string) array_shift($pictures));
        foreach (array_slice($pictures, 0, self::ADDITIONAL_IMAGES_MAX) as $picture) {
            $g('additional_image_link', $picture);
        }
        $g('availability', $offer['available'] ? 'in_stock' : 'out_of_stock');
        if ($offer['old_price'] !== null) {
            $g('price', $this->price($offer['old_price'], $currency));
            $g('sale_price', $this->price($offer['price'], $currency));
        } else {
            $g('price', $this->price($offer['price'], $currency));
        }
        $g('brand', $offer['vendor']);
        $g('mpn', $offer['sku']);
        $g('condition', 'new');
        if ($offer['group_id'] !== null) {
            $g('item_group_id', $offer['group_id']);
        }
        foreach ($offer['options'] ?? [] as $code => $value) {
            if (isset(self::OPTIONS[$code])) {
                $g(self::OPTIONS[$code], $value);
            }
        }
        if ($offer['category_id'] !== null && isset($paths[$offer['category_id']])) {
            $g('product_type', $paths[$offer['category_id']]);
        }
        $xml->endElement();
    }

    /**
     * "Одяг > Вишиванки" by category id.
     *
     * @param array<int, array{id: int, parent_id: int|null, name: string}> $categories
     * @return array<int, string>
     */
    private function categoryPaths(array $categories): array
    {
        $byId = array_column($categories, null, 'id');
        $paths = [];
        foreach ($byId as $id => $category) {
            $names = [];
            for ($c = $category; $c !== null && count($names) < 10; $c = $byId[$c['parent_id']] ?? null) {
                array_unshift($names, $c['name']);
            }
            $paths[$id] = implode(' > ', $names);
        }

        return $paths;
    }

    /**
     * "3490.00 UAH"
     */
    private function price(float $amount, string $currency): string
    {
        return number_format($amount, 2, '.', '') . ' ' . $currency;
    }

    private function text(string $html): string
    {
        // Block ends become line breaks, table cells "label: value" pairs.
        $html = (string) preg_replace('#<(br|/p|/li|/h\d|/tr|/div)\b[^>]*>#i', "$0\n", $html);
        $html = (string) preg_replace('#</th>\s*#i', ': ', $html);
        $html = (string) preg_replace('#</td>\s*(?=<td)#i', ', ', $html);
        $text = html_entity_decode(strip_tags($html));

        return trim((string) preg_replace('/[ \t]+/', ' ', (string) preg_replace('/\s*\n\s*/', "\n", $text)));
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }
}

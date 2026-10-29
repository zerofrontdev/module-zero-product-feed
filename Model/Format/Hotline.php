<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model\Format;

/**
 * Hotline's price list (<price>): the firm, categories and items. The price
 * goes in <priceRUAH> (hryvnias; Hotline's own tag name); a product's sizes
 * or colors are items of their own, with their options as params.
 */
class Hotline implements FormatInterface
{
    /**
     * @inheritDoc
     * @param array{firm_id?: string} $options
     */
    public function render(array $catalog, array $options = []): string
    {
        $shop = $catalog['shop'];
        $xml = new \XMLWriter();
        Writer::open($xml, $options);
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('price');
        $xml->writeElement('date', $shop['date']);
        $xml->writeElement('firmName', $shop['name']);
        $xml->writeElement('firmId', (string) ($options['firm_id'] ?? ''));

        $xml->startElement('categories');
        foreach ($catalog['categories'] as $category) {
            $xml->startElement('category');
            $xml->writeElement('id', (string) $category['id']);
            if ($category['parent_id'] !== null) {
                $xml->writeElement('parentId', (string) $category['parent_id']);
            }
            $xml->writeElement('name', $category['name']);
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('items');
        foreach ($catalog['offers'] as $offer) {
            Writer::flushEvery($xml, $options);
            $xml->startElement('item');
            $xml->writeElement('id', $offer['id']);
            if ($offer['category_id'] !== null) {
                $xml->writeElement('categoryId', (string) $offer['category_id']);
            }
            $xml->writeElement('code', $offer['sku']);
            $xml->writeElement('vendor', $offer['vendor']);
            $xml->writeElement('name', $offer['name']);
            $xml->startElement('description');
            $xml->writeCdata(str_replace(']]>', ']]&gt;', $offer['description']));
            $xml->endElement();
            $xml->writeElement('url', $offer['url']);
            foreach ($offer['pictures'] as $picture) {
                $xml->writeElement('image', $picture);
            }
            $xml->writeElement('priceRUAH', Yml::amount($offer['price']));
            if ($offer['old_price'] !== null) {
                $xml->writeElement('oldprice', Yml::amount($offer['old_price']));
            }
            $xml->writeElement('stock', $offer['available'] ? 'В наявності' : 'Немає в наявності');
            foreach ($offer['params'] as $name => $value) {
                $xml->startElement('param');
                $xml->writeAttribute('name', (string) $name);
                $xml->text($value);
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return Writer::close($xml, $options);
    }
}

<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model\Format;

/**
 * YML (yml_catalog), which Rozetka and Prom.ua read: shop, currencies,
 * categories, offers. An offer's variants share a group_id; its options are
 * params. The old price is <price_old> for Rozetka and <oldprice> for Prom
 * ($oldPriceTag).
 */
class Yml implements FormatInterface
{
    public function __construct(
        private readonly string $oldPriceTag = 'price_old'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function render(array $catalog, array $options = []): string
    {
        $shop = $catalog['shop'];
        $xml = new \XMLWriter();
        Writer::open($xml, $options);
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('yml_catalog');
        $xml->writeAttribute('date', $shop['date']);
        $xml->startElement('shop');
        $xml->writeElement('name', $shop['name']);
        $xml->writeElement('company', $shop['name']);
        $xml->writeElement('url', $shop['url']);
        $xml->startElement('currencies');
        $xml->startElement('currency');
        $xml->writeAttribute('id', $shop['currency']);
        $xml->writeAttribute('rate', '1');
        $xml->endElement();
        $xml->endElement();

        $xml->startElement('categories');
        foreach ($catalog['categories'] as $category) {
            $xml->startElement('category');
            $xml->writeAttribute('id', (string) $category['id']);
            if ($category['parent_id'] !== null) {
                $xml->writeAttribute('parentId', (string) $category['parent_id']);
            }
            $xml->text($category['name']);
            $xml->endElement();
        }
        $xml->endElement();

        $xml->startElement('offers');
        foreach ($catalog['offers'] as $offer) {
            Writer::flushEvery($xml, $options);
            $xml->startElement('offer');
            $xml->writeAttribute('id', $offer['id']);
            $xml->writeAttribute('available', $offer['available'] ? 'true' : 'false');
            if ($offer['group_id'] !== null) {
                $xml->writeAttribute('group_id', $offer['group_id']);
            }
            $xml->writeElement('url', $offer['url']);
            $xml->writeElement('price', self::amount($offer['price']));
            if ($offer['old_price'] !== null) {
                $xml->writeElement($this->oldPriceTag, self::amount($offer['old_price']));
            }
            $xml->writeElement('currencyId', $shop['currency']);
            if ($offer['category_id'] !== null) {
                $xml->writeElement('categoryId', (string) $offer['category_id']);
            }
            foreach ($offer['pictures'] as $picture) {
                $xml->writeElement('picture', $picture);
            }
            $xml->writeElement('vendor', $offer['vendor']);
            $xml->writeElement('vendorCode', $offer['sku']);
            $xml->writeElement('stock_quantity', (string) $offer['stock']);
            $xml->writeElement('name', $offer['name']);
            $xml->startElement('description');
            $xml->writeCdata(str_replace(']]>', ']]&gt;', $offer['description']));
            $xml->endElement();
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
        $xml->endElement();
        $xml->endDocument();

        return Writer::close($xml, $options);
    }

    /**
     * "3490" or "1290.5": no thousands separator, a dot, no needless decimals.
     */
    public static function amount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
    }
}

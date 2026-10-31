<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Test\Unit\Model\Format;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zero\ProductFeed\Model\Format\FormatInterface;
use Zero\ProductFeed\Model\Format\Google;
use Zero\ProductFeed\Model\Format\Hotline;
use Zero\ProductFeed\Model\Format\Writer;
use Zero\ProductFeed\Model\Format\Yml;

/**
 * The marketplaces' XML from the catalog's data: YML (Rozetka, Prom.ua) and Hotline; characters XML 1.0 forbids
 * never reach any of them.
 */
class FormatsTest extends TestCase
{
    public function testYmlOffersWithTheirGroupParamsAndOldPrice(): void
    {
        $xml = simplexml_load_string((new Yml('price_old'))->render($this->catalog()));

        $this->assertSame('2026-10-10 12:00', (string) $xml['date']);
        $this->assertSame('UAH', (string) $xml->shop->currencies->currency['id']);
        $this->assertSame('5', (string) $xml->shop->categories->category[1]['parentId']);
        $offer = $xml->shop->offers->offer[0];
        $this->assertSame('11', (string) $offer['id']);
        $this->assertSame('10', (string) $offer['group_id']);
        $this->assertSame('true', (string) $offer['available']);
        $this->assertSame('3490', (string) $offer->price);
        $this->assertSame('3990.5', (string) $offer->price_old);
        $this->assertSame('Розмір', (string) $offer->param['name']);
        $this->assertSame('92', (string) $offer->param);
        $this->assertStringContainsString('<p>Тепло & ]]&gt;', (string) $offer->description);
        $this->assertCount(2, $offer->picture);
        $this->assertSame('', (string) $xml->shop->offers->offer[1]['group_id']);
    }

    public function testPromNamesTheOldPriceOldprice(): void
    {
        $xml = simplexml_load_string((new Yml('oldprice'))->render($this->catalog()));

        $this->assertSame('3990.5', (string) $xml->shop->offers->offer[0]->oldprice);
        $this->assertCount(0, $xml->shop->offers->offer[0]->price_old);
    }

    public function testHotlineItems(): void
    {
        $xml = simplexml_load_string((new Hotline())->render($this->catalog(), ['firm_id' => '777']));

        $this->assertSame('777', (string) $xml->firmId);
        $this->assertSame('6', (string) $xml->categories->category[1]->id);
        $item = $xml->items->item[0];
        $this->assertSame('KK-KB-01-92', (string) $item->code);
        $this->assertSame('3490', (string) $item->priceRUAH);
        $this->assertSame('В наявності', (string) $item->stock);
        $this->assertCount(2, $item->image);
    }

    public function testGoogleItemsWithVariantsSalePriceAndProductType(): void
    {
        $xml = simplexml_load_string((new Google())->render($this->catalog()));
        $this->assertSame('2.0', (string) $xml['version']);
        $items = $xml->channel->item;
        $this->assertCount(2, $items);
        $g = $items[0]->children('http://base.google.com/ns/1.0');

        $this->assertSame('11', (string) $g->id);
        $this->assertSame('Комбінезон «Сніжок» (92)', (string) $g->title);
        $this->assertSame('Тепло & ]]> затишно', (string) $g->description);
        $specs = ['description' => '<p>Тепло</p><table><tr><th>Верх</th><td>мембрана</td></tr><tr><th>Догляд</th><td>прання 30 °C</td></tr></table>'];
        $catalog = $this->catalog();
        $catalog['offers'][0] = $specs + $catalog['offers'][0];
        $described = simplexml_load_string((new Google())->render($catalog))->channel->item[0]->children('http://base.google.com/ns/1.0');
        $this->assertSame("Тепло\nВерх: мембрана\nДогляд: прання 30 °C", (string) $described->description);
        $this->assertSame('https://shop.test/kids/kombinezon.html', (string) $g->link);
        $this->assertSame('https://shop.test/a.jpg', (string) $g->image_link);
        $this->assertSame('https://shop.test/b.jpg', (string) $g->additional_image_link);
        $this->assertSame('in_stock', (string) $g->availability);
        $this->assertSame('3990.50 UAH', (string) $g->price);
        $this->assertSame('3490.00 UAH', (string) $g->sale_price);
        $this->assertSame('Kids Карамель', (string) $g->brand);
        $this->assertSame('KK-KB-01-92', (string) $g->mpn);
        $this->assertSame('new', (string) $g->condition);
        $this->assertSame('10', (string) $g->item_group_id);
        $this->assertSame('92', (string) $g->size);
        $this->assertSame('Одяг > Комбінезони', (string) $g->product_type);

        $simple = $items[1]->children('http://base.google.com/ns/1.0');
        $this->assertSame('Комбінезон «Сніжок»', (string) $simple->title);
        $this->assertSame('3490.00 UAH', (string) $simple->price);
        $this->assertCount(0, $simple->sale_price);
        $this->assertCount(0, $simple->item_group_id);
    }

    public function testAmounts(): void
    {
        $this->assertSame('3490', Yml::amount(3490.0));
        $this->assertSame('1290.5', Yml::amount(1290.5));
        $this->assertSame('0.99', Yml::amount(0.99));
    }

    public function testAFileFeedIsTheSameXmlWithOffersFromAGenerator(): void
    {
        $catalog = $this->catalog();
        $memory = (new Google())->render($catalog);
        $offers = $catalog['offers'];
        $catalog['offers'] = (static function () use ($offers) {
            yield from $offers;
        })();
        $file = tempnam(sys_get_temp_dir(), 'zero-feed');
        $this->assertSame('', (new Google())->render($catalog, ['file' => $file]));
        $this->assertSame($memory, (string) file_get_contents($file));
        unlink($file);
    }

    /**
     * @return array<string, array{0: \Closure(): FormatInterface, 1: string[]}> the format, the texts it carries
     */
    public static function formats(): array
    {
        $all = ['N1ab', 'D1ab', 'V1ab', 'P1ab', 'Q1ab', 'C1ab'];

        return [
            'rozetka' => [static fn () => new Yml('price_old'), $all],
            'prom' => [static fn () => new Yml('oldprice'), $all],
            'hotline' => [static fn () => new Hotline(), $all],
            // Google has no param names: the values go into the title
            'google' => [static fn () => new Google(), ['N1ab', 'D1ab', 'V1ab', 'Q1ab', 'C1ab']],
        ];
    }

    /**
     * @param \Closure(): FormatInterface $format
     * @param string[] $texts
     */
    #[DataProvider('formats')]
    public function testControlCharactersNeverReachTheXml(\Closure $format, array $texts): void
    {
        $catalog = $this->catalog();
        $catalog['categories'][1]['name'] = "C1a\x0Bb";
        $catalog['shop']['name'] = "Shop\x01";
        foreach ($catalog['offers'] as &$offer) {
            $offer['name'] = "N1a\x0Bb";
            $offer['description'] = "<p>D1a\x0Bb</p>";
            $offer['vendor'] = "V1a\x0Bb";
            $offer['params'] = ["P1a\x0Bb" => "Q1a\x0Bb"];
        }
        unset($offer);

        $out = $format()->render($catalog, ['firm_id' => '1']);

        $this->assertNotFalse($this->parse($out), 'the feed is well-formed XML');
        $this->assertStringNotContainsString("\x0B", $out);
        $this->assertStringNotContainsString("\x01", $out);
        foreach ($texts as $text) {
            $this->assertStringContainsString($text, $out);
        }
    }

    public function testBrokenUtf8IsScrubbed(): void
    {
        foreach ([new Yml(), new Hotline(), new Google()] as $format) {
            $catalog = $this->catalog();
            $catalog['offers'][0]['name'] = "Bad \xC3\x28 name";

            $out = $format->render($catalog, ['firm_id' => '1']);

            $this->assertNotFalse($this->parse($out), get_class($format));
            $this->assertStringContainsString('Bad ?( name', $out, get_class($format));
        }
    }

    public function testCleanKeepsValidTextAsItIs(): void
    {
        $valid = "Tab\tline\nCR\r Тепло & ]]> 😀 \u{E000} \u{FFFD}";
        $this->assertSame($valid, Writer::clean($valid));
        $this->assertSame('ab', Writer::clean("a\u{FFFE}\u{FFFF}\x00\x1Fb"));
        $this->assertSame('', Writer::clean(''));
        $catalog = $this->catalog();
        $cleaned = Writer::cleanCatalog($catalog);
        $this->assertSame($catalog['shop'], $cleaned['shop']);
        $this->assertSame($catalog['categories'], $cleaned['categories']);
        $this->assertSame($catalog['offers'], iterator_to_array($cleaned['offers']));
    }

    public function testCleanedOffersAreReadOnlyWhenTheFormatReadsThem(): void
    {
        $read = 0;
        $catalog = $this->catalog();
        $offers = $catalog['offers'];
        $catalog['offers'] = (static function () use ($offers, &$read) {
            foreach ($offers as $offer) {
                $read++;
                yield $offer + ['params' => []];
            }
        })();

        $cleaned = Writer::cleanCatalog($catalog);

        $this->assertSame(0, $read, 'nothing read yet');
        $this->assertInstanceOf(\Generator::class, $cleaned['offers']);
        $cleaned['offers']->current();
        $this->assertSame(1, $read, 'one offer at a time');
    }

    private function parse(string $xml): \SimpleXMLElement|false
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return simplexml_load_string($xml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function catalog(): array
    {
        $offer = [
            'id' => '11', 'group_id' => '10', 'sku' => 'KK-KB-01-92', 'available' => true,
            'url' => 'https://shop.test/kids/kombinezon.html', 'price' => 3490.0, 'old_price' => 3990.5,
            'category_id' => 6, 'pictures' => ['https://shop.test/a.jpg', 'https://shop.test/b.jpg'],
            'vendor' => 'Kids Карамель', 'name' => 'Комбінезон «Сніжок»',
            'description' => '<p>Тепло & ]]> затишно</p>', 'stock' => 8, 'params' => ['Розмір' => '92'],
            'options' => ['size' => '92'],
        ];

        return [
            'shop' => ['name' => 'Kids Карамель', 'url' => 'https://shop.test/kids/', 'currency' => 'UAH',
                'date' => '2026-10-10 12:00'],
            'categories' => [
                ['id' => 5, 'parent_id' => null, 'name' => 'Одяг', 'level' => 2],
                ['id' => 6, 'parent_id' => 5, 'name' => 'Комбінезони', 'level' => 3],
            ],
            'offers' => [
                $offer,
                ['id' => '20', 'group_id' => null, 'old_price' => null, 'params' => [], 'options' => []] + $offer,
            ],
        ];
    }
}

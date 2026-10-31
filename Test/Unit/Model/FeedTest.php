<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\ProductFeed\Model\Catalog;
use Zero\ProductFeed\Model\Feed;
use Zero\ProductFeed\Model\Format\FormatInterface;

/**
 * The feeds written in a row: one that fails is told and the others are still written; a failed feed leaves no
 * temporary file and the last good feed in place.
 */
class FeedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/zero-feed-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/zero_product_feed', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/zero_product_feed/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir . '/zero_product_feed');
        rmdir($this->dir);
    }

    public function testAFailingFeedDoesNotStopTheNextAndLeavesNoTmp(): void
    {
        $broken = $this->createMock(FormatInterface::class);
        $broken->expects($this->once())->method('render')->willReturnCallback(static function (array $catalog, array $options) {
            file_put_contents($options['file'], '<price><items>half');
            throw new \RuntimeException('Out of memory');
        });
        $good = $this->createMock(FormatInterface::class);
        $good->expects($this->once())->method('render')->willReturnCallback(static function (array $catalog, array $options) {
            file_put_contents($options['file'], '<yml_catalog/>');

            return '';
        });
        $store = $this->store();
        $failed = [];
        $done = [];

        $failures = $this->feed(['hotline' => $broken, 'rozetka' => $good], [$store])->generateAll(
            null,
            static function (string $code, Store $s, int $bytes) use (&$done): void {
                $done[] = [$code, $bytes];
            },
            static function (string $code, Store $s, \Throwable $e) use (&$failed): void {
                $failed[] = [$code, $s->getCode(), $e->getMessage()];
            }
        );

        $this->assertSame(1, $failures);
        $this->assertSame([['hotline', 'default', 'Out of memory']], $failed);
        $this->assertSame([['rozetka', 14]], $done);
        $this->assertSame([], glob($this->dir . '/zero_product_feed/*.tmp'), 'no temporary file is left');
        $this->assertSame('<yml_catalog/>', file_get_contents($this->dir . '/zero_product_feed/rozetka-1.xml'));
        $this->assertFileDoesNotExist($this->dir . '/zero_product_feed/hotline-1.xml');
    }

    public function testAFailedFeedKeepsTheLastGoodFileAndRethrows(): void
    {
        file_put_contents($this->dir . '/zero_product_feed/google-1.xml', 'last good');
        $broken = $this->createStub(FormatInterface::class);
        $broken->method('render')->willReturnCallback(static function (array $catalog, array $options) {
            file_put_contents($options['file'], 'half');
            throw new \LogicException('Bad price');
        });

        try {
            $this->feed(['google' => $broken], [])->generate('google', $this->store());
            $this->fail('the error is rethrown');
        } catch (\LogicException $e) {
            $this->assertSame('Bad price', $e->getMessage());
        }

        $this->assertSame('last good', file_get_contents($this->dir . '/zero_product_feed/google-1.xml'));
        $this->assertSame([], glob($this->dir . '/zero_product_feed/*.tmp'));
    }

    public function testNoFailureCountsZeroAndAFeedThatIsOffIsNotWritten(): void
    {
        $off = $this->createMock(FormatInterface::class);
        $off->expects($this->never())->method('render');
        $on = $this->createStub(FormatInterface::class);
        $on->method('render')->willReturnCallback(static function (array $catalog, array $options) {
            file_put_contents($options['file'], '<x/>');

            return '';
        });

        $this->assertSame(0, $this->feed(['prom' => $off, 'google' => $on], [$this->store()], ['google'])->generateAll());
        $this->assertFileExists($this->dir . '/zero_product_feed/google-1.xml');
    }

    /**
     * @param array<string, FormatInterface> $formats
     * @param Store[] $stores
     * @param string[]|null $enabled the codes that are on (all when null)
     */
    private function feed(array $formats, array $stores, ?array $enabled = null): Feed
    {
        $read = $this->createStub(ReadInterface::class);
        $read->method('getAbsolutePath')->willReturnCallback(fn (string $path) => $this->dir . '/' . $path);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($read);
        $filesystem->method('getDirectoryWrite')->willReturn($this->createStub(WriteInterface::class));
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => $enabled === null || in_array(explode('/', $path)[1], $enabled, true)
        );
        $catalog = $this->createStub(Catalog::class);
        $catalog->method('build')->willReturn(['shop' => [], 'categories' => [], 'offers' => []]);

        return new Feed($catalog, $storeManager, $scopeConfig, $filesystem, $this->createStub(LockManagerInterface::class), $formats);
    }

    private function store(): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCode')->willReturn('default');

        return $store;
    }
}

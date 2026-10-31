<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Test\Unit\Cron;

use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\ProductFeed\Cron\Generate;
use Zero\ProductFeed\Model\Feed;

/**
 * The hourly run logs each feed that was not written, by feed and store view.
 */
class GenerateTest extends TestCase
{
    public function testEachFailedFeedIsLogged(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('uk');
        $feed = $this->createStub(Feed::class);
        $feed->method('generateAll')->willReturnCallback(static function ($only, callable $done, callable $failed) use ($store) {
            $failed('hotline', $store, new \RuntimeException('Cannot write'));
            $done('google', $store, 2048, 1.5);

            return 1;
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('Zero_ProductFeed: hotline for uk was not written: Cannot write');
        $logger->expects($this->once())->method('info')->with('Zero_ProductFeed: google for uk, 2 KB in 1.5 s');

        (new Generate($feed, $logger))->execute();
    }
}

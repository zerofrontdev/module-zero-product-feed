<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Cron;

use Psr\Log\LoggerInterface;
use Zero\ProductFeed\Model\Feed;

/**
 * Every hour: each feed that is on, in each store view, written anew; one that fails is logged and the others go on.
 */
class Generate
{
    public function __construct(
        private readonly Feed $feed,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->feed->generateAll(
                null,
                function (string $code, $store, int $bytes, float $seconds): void {
                    $this->logger->info(sprintf('Zero_ProductFeed: %s for %s, %d KB in %.1f s', $code, $store->getCode(), $bytes / 1024, $seconds));
                },
                function (string $code, $store, \Throwable $e): void {
                    $this->logger->error(sprintf('Zero_ProductFeed: %s for %s was not written: %s', $code, $store->getCode(), $e->getMessage()));
                }
            );
        } catch (\Throwable $e) {
            $this->logger->error('Zero_ProductFeed: the feeds were not written: ' . $e->getMessage());
        }
    }
}

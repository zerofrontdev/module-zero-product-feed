<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Zero\ProductFeed\Model\Format\FormatInterface;

/**
 * A store view's feed as a file, var/zero_product_feed/<code>-<store id>.xml:
 * written by the hourly cron (and bin/magento zero:product-feed:generate),
 * served as it is. The catalogue is read and written a page at a time
 * (Catalog, Format\Writer), so a feed of 100 000 products takes little
 * memory; a request never builds it unless the file is missing, and then one
 * request at a time (the others are told to come back).
 */
class Feed
{
    public const DIR = 'zero_product_feed';

    /**
     * @param array<string, FormatInterface> $formats By code: rozetka, prom, hotline, google (etc/di.xml)
     */
    public function __construct(
        private readonly Catalog $catalog,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Filesystem $filesystem,
        private readonly LockManagerInterface $locks,
        private readonly array $formats = []
    ) {
    }

    /**
     * The feed's file for the current store view: null when the feed is off (or unknown), false while it is being
     * written by another request.
     */
    public function file(string $code): string|false|null
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        if (!isset($this->formats[$code]) || !$this->isEnabled($code, (int) $store->getId())) {
            return null;
        }
        $path = $this->path($code, (int) $store->getId());
        if (is_file($path)) {
            return $path;
        }
        $lock = 'zero_product_feed_' . $code . '_' . $store->getId();
        if (!$this->locks->lock($lock, 0)) {
            return false;
        }
        try {
            set_time_limit(900);
            $this->generate($code, $store);
        } finally {
            $this->locks->unlock($lock);
        }

        return $path;
    }

    /**
     * Writes the feed's file anew (to a temporary file first, so a request never reads half a feed).
     *
     * @return int the file's size in bytes
     */
    public function generate(string $code, Store $store): int
    {
        $path = $this->path($code, (int) $store->getId());
        $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->create(self::DIR);
        $tmp = $path . '.' . getmypid() . '.tmp';
        $this->formats[$code]->render($this->catalog->build($store), [
            'firm_id' => (string) $this->scopeConfig->getValue(
                'zero_product_feed/hotline/firm_id',
                ScopeInterface::SCOPE_STORE,
                (int) $store->getId()
            ),
            'file' => $tmp,
        ]);
        rename($tmp, $path);

        return (int) filesize($path);
    }

    /**
     * Every feed that is on, in every store view: the cron's and the command's work.
     *
     * @param callable(string, Store, int, float): void|null $done told after each feed (code, store, bytes, seconds)
     */
    public function generateAll(?string $only = null, ?callable $done = null): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            foreach (array_keys($this->formats) as $code) {
                if (($only !== null && $only !== $code) || !$this->isEnabled($code, (int) $store->getId())) {
                    continue;
                }
                $start = microtime(true);
                $this->storeManager->setCurrentStore($store->getId());
                $bytes = $this->generate($code, $store);
                if ($done) {
                    $done($code, $store, $bytes, microtime(true) - $start);
                }
            }
        }
    }

    public function path(string $code, int $storeId): string
    {
        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR)
            ->getAbsolutePath(self::DIR . '/' . $code . '-' . $storeId . '.xml');
    }

    /**
     * @return string[]
     */
    public function codes(): array
    {
        return array_keys($this->formats);
    }

    public function isEnabled(string $code, int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            'zero_product_feed/' . $code . '/enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}

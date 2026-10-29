<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

/**
 * The store view's catalog as plain data for the feed formats (Format\*):
 * the shop, its categories (the store's root category tree) and offers.
 *
 * Offers: the products of the store's website that are enabled, visible in
 * the catalog and in stock. A configurable product's sizes or colors are
 * offers of their own (the child's price, stock and options as params), with
 * the parent's id as group_id, its URL, pictures and description.
 */
class Catalog
{
    /** Products read at a time. */
    private const PAGE_SIZE = 500;

    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly Visibility $visibility,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @return array{
     *     shop: array{name: string, url: string, currency: string, date: string},
     *     categories: array<int, array{id: int, parent_id: int|null, name: string, level: int}>,
     *     offers: iterable<array{id: string, group_id: string|null, sku: string, available: bool,
     *         url: string, price: float, old_price: float|null, category_id: int|null, pictures: string[],
     *         vendor: string, name: string, description: string, stock: int, params: array<string, string>,
     *         options: array<string, string>}>
     * }
     */
    public function build(Store $store): array
    {
        $storeName = trim((string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        )) ?: (string) $store->getFrontendName();
        $vendor = trim((string) $this->scopeConfig->getValue(
            'zero_product_feed/general/vendor',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        )) ?: $storeName;
        $categories = $this->categories($store);

        return [
            'shop' => [
                'name' => $storeName,
                'url' => (string) $store->getBaseUrl(),
                'currency' => (string) $store->getCurrentCurrencyCode(),
                'date' => date('Y-m-d H:i'),
            ],
            'categories' => array_values($categories),
            'offers' => $this->offers($store, $categories, $vendor),
        ];
    }

    /**
     * The active categories under the store's root, by id.
     *
     * @return array<int, array{id: int, parent_id: int|null, name: string, level: int}>
     */
    private function categories(Store $store): array
    {
        $rootId = (int) $store->getRootCategoryId();
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addAttributeToSelect('name')
            ->addAttributeToFilter('is_active', 1)
            ->addAttributeToFilter('path', ['like' => '1/' . $rootId . '/%'])
            ->setOrder('level', 'ASC')
            ->setOrder('position', 'ASC');
        $categories = [];
        foreach ($collection as $category) {
            $parentId = (int) $category->getParentId();
            $categories[(int) $category->getId()] = [
                'id' => (int) $category->getId(),
                'parent_id' => $parentId === $rootId ? null : $parentId,
                'name' => (string) $category->getName(),
                'level' => (int) $category->getLevel(),
            ];
        }

        return $categories;
    }

    /**
     * The offers one by one, the products read PAGE_SIZE at a time: a catalogue of 100 000 products never sits in
     * memory at once (it did, and the PHP worker was killed).
     *
     * @param array<int, array{id: int}> $categories
     * @return \Generator<int, array<string, mixed>>
     */
    private function offers(Store $store, array $categories, string $vendor): \Generator
    {
        $collection = $this->productCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addStoreFilter($store)
            ->addAttributeToSelect(['name', 'description', 'short_description', 'image', 'small_image',
                'manufacturer', 'url_key', 'special_price', 'special_from_date', 'special_to_date'])
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('type_id', ['in' => ['simple', Configurable::TYPE_CODE]])
            ->setVisibility($this->visibility->getVisibleInCatalogIds())
            ->addPriceData()
            ->addUrlRewrite()
            ->addCategoryIds();
        $mediaUrl = rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
        $collection->setPageSize(self::PAGE_SIZE);
        $pages = (int) $collection->getLastPageNumber();
        for ($page = 1; $page <= $pages; $page++) {
            $collection->setCurPage($page)->clear();
            /** @var Product $product */
            foreach ($collection as $product) {
                yield from $this->productOffers($store, $product, $categories, $vendor, $mediaUrl);
            }
        }
    }

    /**
     * A product's offers: itself, or a configurable product's salable children.
     *
     * @param array<int, array{id: int}> $categories
     * @return \Generator<int, array<string, mixed>>
     */
    private function productOffers(Store $store, Product $product, array $categories, string $vendor, string $mediaUrl): \Generator
    {
        $base = [
            'url' => (string) $product->getProductUrl(),
            'category_id' => $this->categoryOf($product, $categories),
            'pictures' => $this->pictures($product, $mediaUrl),
            'vendor' => trim((string) $product->getAttributeText('manufacturer')) ?: $vendor,
            'name' => (string) $product->getName(),
            'description' => trim((string) ($product->getDescription() ?: $product->getShortDescription())),
        ];
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            if ($product->isSaleable()) {
                yield $base + $this->offer($product, null, [], []);
            }
            return;
        }
        /** @var Configurable $type */
        $type = $product->getTypeInstance();
        $attributes = [];
        foreach ($type->getConfigurableAttributes($product) as $attribute) {
            $code = (string) $attribute->getProductAttribute()->getAttributeCode();
            $attributes[$code] = (string) ($attribute->getLabel()
                ?: $attribute->getProductAttribute()->getStoreLabel((int) $store->getId()));
        }
        foreach ($type->getUsedProducts($product) as $child) {
            if (!$child->isSaleable()) {
                continue;
            }
            $params = [];
            $options = [];
            foreach ($attributes as $code => $label) {
                $value = (string) $child->getAttributeText($code);
                if ($value !== '') {
                    $params[$label] = $value;
                    $options[$code] = $value;
                }
            }
            yield $base + $this->offer($child, (string) $product->getId(), $params, $options);
        }
    }

    /**
     * @param array<string, string> $params By the attribute's label in the store view
     * @param array<string, string> $options The same by attribute code (size, color…)
     * @return array<string, mixed>
     */
    private function offer(Product $product, ?string $groupId, array $params, array $options): array
    {
        // Price info amounts are in the store view's currency already.
        $final = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        $regular = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
        $stock = $this->stockRegistry->getStockItem((int) $product->getId());

        return [
            'id' => (string) $product->getId(),
            'group_id' => $groupId,
            'sku' => (string) $product->getSku(),
            'available' => true,
            'price' => round($final, 2),
            'old_price' => $regular > $final + 0.001 ? round($regular, 2) : null,
            'stock' => max(0, (int) $stock->getQty()),
            'params' => $params,
            'options' => $options,
        ];
    }

    /**
     * The product's deepest category in this store's tree.
     *
     * @param array<int, array{id: int, level: int}> $categories
     */
    private function categoryOf(Product $product, array $categories): ?int
    {
        $deepest = null;
        foreach (array_map('intval', (array) $product->getCategoryIds()) as $id) {
            $deeper = $deepest === null || ($categories[$id]['level'] ?? 0) > $categories[$deepest]['level'];
            if (isset($categories[$id]) && $deeper) {
                $deepest = $id;
            }
        }

        return $deepest;
    }

    /**
     * @return string[]
     */
    private function pictures(Product $product, string $mediaUrl): array
    {
        $pictures = [];
        foreach (['image', 'small_image'] as $code) {
            $file = (string) $product->getData($code);
            if ($file !== '' && $file !== 'no_selection') {
                $pictures[] = $mediaUrl . '/' . ltrim($file, '/');
            }
        }

        return array_values(array_unique($pictures));
    }
}

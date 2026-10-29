<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Controller;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RawFactory;
use Zero\ProductFeed\Model\Feed;

/**
 * {store URL}/productfeed/{code}: the feed's file, streamed (Model\Feed writes it by cron); 404 when the feed is
 * off, 503 while its first file is being written by another request.
 */
abstract class AbstractFeed implements HttpGetActionInterface
{
    protected const CODE = '';

    public function __construct(
        private readonly Feed $feed,
        private readonly RawFactory $rawFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly FileFactory $fileFactory
    ) {
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface|\Magento\Framework\App\ResponseInterface
     */
    public function execute()
    {
        $file = $this->feed->file(static::CODE);
        if ($file === null) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        if ($file === false) {
            $result = $this->rawFactory->create();
            $result->setHttpResponseCode(503);
            $result->setHeader('Retry-After', '300', true);
            $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);
            $result->setContents('The feed is being prepared. Try again in a few minutes.');

            return $result;
        }

        return $this->fileFactory->create(
            static::CODE . '.xml',
            ['type' => 'filename', 'value' => Feed::DIR . '/' . basename($file)],
            DirectoryList::VAR_DIR,
            'application/xml; charset=UTF-8'
        );
    }
}

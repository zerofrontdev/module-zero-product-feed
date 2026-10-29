<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\ProductFeed\Model\Feed;

/**
 * bin/magento zero:product-feed:generate [code]: the feeds that are on written now (the cron does it hourly).
 */
class GenerateCommand extends Command
{
    public function __construct(
        private readonly Feed $feed,
        private readonly State $state,
        private readonly ObjectManagerInterface $objectManager,
        private readonly ConfigLoaderInterface $configLoader
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:product-feed:generate')
            ->setDescription('Write the product feeds that are on, in every store view (the cron does it hourly)')
            ->addArgument('code', InputArgument::OPTIONAL, 'Only this feed: ' . implode(', ', $this->feed->codes()));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The storefront's area with its own DI (prices, URLs and observers as a page sees them).
        try {
            $this->state->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Throwable) {
            // already set
        }
        $this->objectManager->configure($this->configLoader->load(Area::AREA_FRONTEND));
        $code = $input->getArgument('code');
        $count = 0;
        $this->feed->generateAll($code ? (string) $code : null, function (string $c, $store, int $bytes, float $s) use ($output, &$count): void {
            $count++;
            $output->writeln(sprintf('%s for %s: %d KB in %.1f s', $c, $store->getCode(), $bytes / 1024, $s));
        });
        if ($count === 0) {
            $output->writeln('No feed is on (Stores > Configuration > Zero > Product Feeds).');
        }

        return Command::SUCCESS;
    }
}

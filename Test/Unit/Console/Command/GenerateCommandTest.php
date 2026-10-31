<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\ProductFeed\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Zero\ProductFeed\Console\Command\GenerateCommand;
use Zero\ProductFeed\Model\Feed;

/**
 * The command prints each feed that was not written and exits with FAILURE; "No feed is on" only when nothing ran.
 */
class GenerateCommandTest extends TestCase
{
    public function testAFailedFeedIsPrintedAndExitsWithFailure(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('uk');
        [$code, $text] = $this->runCommand(static function ($only, callable $done, callable $failed) use ($store) {
            $failed('hotline', $store, new \RuntimeException('Cannot write'));

            return 1;
        });

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('hotline for uk was not written: Cannot write', $text);
        $this->assertStringNotContainsString('No feed is on', $text);
    }

    public function testOneFailureAmongWrittenFeedsStillFails(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('uk');
        [$code, $text] = $this->runCommand(static function ($only, callable $done, callable $failed) use ($store) {
            $done('google', $store, 2048, 1.5);
            $failed('hotline', $store, new \RuntimeException('Cannot write'));

            return 1;
        });

        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('google for uk: 2 KB in 1.5 s', $text);
        $this->assertStringContainsString('hotline for uk was not written: Cannot write', $text);
        $this->assertStringNotContainsString('No feed is on', $text);
    }

    public function testWrittenFeedsExitWithSuccess(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getCode')->willReturn('default');
        [$code, $text] = $this->runCommand(static function ($only, callable $done) use ($store) {
            $done('google', $store, 1024, 0.2);

            return 0;
        });

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('google for default: 1 KB in 0.2 s', $text);
        $this->assertStringNotContainsString('No feed is on', $text);
    }

    public function testNothingOnSaysSoAndSucceeds(): void
    {
        [$code, $text] = $this->runCommand(static fn () => 0);

        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No feed is on (Stores > Configuration > Zero > Product Feeds).', $text);
    }

    public function testTheCodeArgumentLimitsTheRun(): void
    {
        $seen = [];
        $this->runCommand(static function ($only) use (&$seen) {
            $seen[] = $only;

            return 0;
        }, ['code' => 'google']);
        $this->runCommand(static function ($only) use (&$seen) {
            $seen[] = $only;

            return 0;
        });

        $this->assertSame(['google', null], $seen);
    }

    /**
     * @return array{0: int, 1: string} exit code and printed text
     */
    private function runCommand(callable $generateAll, array $args = []): array
    {
        $feed = $this->createStub(Feed::class);
        $feed->method('codes')->willReturn(['google', 'hotline']);
        $feed->method('generateAll')->willReturnCallback($generateAll);
        $loader = $this->createStub(ConfigLoaderInterface::class);
        $loader->method('load')->willReturn([]);
        $command = new GenerateCommand(
            $feed,
            $this->createStub(State::class),
            $this->createStub(ObjectManagerInterface::class),
            $loader
        );
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($args), $output);

        return [$code, $output->fetch()];
    }
}

<?php

namespace Webong\Gouge\Tests;

use PHPUnit\Framework\TestCase;
use Webong\Gouge\Runtime\AgentTurn;

final class ConsumerBoundaryTest extends TestCase
{
    public function test_runtime_input_rejects_objects_in_opaque_metadata(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AgentTurn('scope', 'session', 'run', 'helper', '1', str_repeat('a', 64), 'hello', ['model' => new \stdClass]);
    }

    public function test_package_has_no_consumer_symbols_or_integration_registration(): void
    {
        $root = dirname(__DIR__);
        foreach (['src', 'config', 'database'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/'.$directory));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $this->assertDoesNotMatchRegularExpression('/\\b(?:App|Figurate)\\\\|figurate|WorkExchange|ThreadActor|thread_actor|post_ulid|channels\\.protocols/i', $source, $file->getPathname());
            }
        }
        $provider = file_get_contents($root.'/src/GougeServiceProvider.php');
        $this->assertStringNotContainsString('Route::', $provider);
        $this->assertStringNotContainsString('Event::listen', $provider);
        $this->assertStringNotContainsString('ConversationStore', $provider);
    }
}

<?php

namespace Webong\LaravelGouge\Tests\Unit;

use App\Models\Server\Post;
use Tests\TestCase;
use Webong\LaravelGouge\Features\Actions\Chat\ProjectMessageExtra;

class ProjectMessageExtraTest extends TestCase
{
    public function test_it_projects_decorated_a2ui_message_extra(): void
    {
        config()->set('a2a.inbound.a2ui.catalogs.items', [
            'status-options' => [
                'title' => 'Statuses',
                'items' => [
                    ['id' => 'open', 'label' => 'Open'],
                    ['id' => 'closed', 'label' => 'Closed'],
                ],
            ],
        ]);

        $message = new Post([
            'meta' => [
                'a2ui' => [
                    'surface' => [
                        'catalogId' => 'status-options',
                    ],
                ],
                'a2ui_client_data_model' => 'v1.0',
                'a2ui_client_capabilities' => [
                    'supportedCatalogIds' => ['status-options'],
                    'acceptsInlineCatalogs' => true,
                ],
            ],
        ]);

        $projected = app(ProjectMessageExtra::class)->execute($message);

        $this->assertSame('v1.0', data_get($projected, 'a2ui.config.a2uiClientDataModel'));
        $this->assertSame(['status-options'], data_get($projected, 'a2ui.config.a2uiClientCapabilities.supportedCatalogIds'));
        $this->assertSame('status-options', data_get($projected, 'a2ui.surface.catalogs.0.id'));
    }
}

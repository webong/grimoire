<?php

namespace Webong\LaravelGouge\Features\Actions\Chat;

use App\Models\Server\Post;
use Webong\LaravelGouge\Ai\Support\A2ui\A2uiCatalogRegistry;
use Webong\LaravelGouge\Ai\Support\A2ui\A2uiPayloadContract;

class ProjectMessageExtra
{
    public function __construct(
        protected A2uiPayloadContract $a2uiPayloadContract,
        protected A2uiCatalogRegistry $a2uiCatalogRegistry,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(Post $message): ?array
    {
        $surface = data_get($message->meta, 'a2ui');
        $surface = is_array($surface) ? $surface : null;
        $dataModel = $this->trimmedString(data_get($message->meta, 'a2ui_client_data_model'));
        $capabilities = $this->a2uiPayloadContract->normalizeClientCapabilities(
            is_array(data_get($message->meta, 'a2ui_client_capabilities'))
                ? data_get($message->meta, 'a2ui_client_capabilities')
                : null
        );

        if ($surface === null && $dataModel === null && $capabilities === null) {
            return null;
        }

        if (is_array($surface)) {
            $surface = $this->a2uiCatalogRegistry->decoratePayload($surface, $capabilities);
        }

        return [
            'a2ui' => [
                'surface' => $surface,
                'config' => [
                    'a2uiClientDataModel' => $dataModel,
                    'a2uiClientCapabilities' => $capabilities,
                ],
            ],
        ];
    }

    protected function trimmedString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

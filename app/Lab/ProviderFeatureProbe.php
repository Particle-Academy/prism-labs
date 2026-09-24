<?php

declare(strict_types=1);

namespace App\Lab;

use Prism\Prism\Enums\CacheStability;
use Prism\Prism\Exceptions\PrismUrlRefused;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Media\Media;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

final class ProviderFeatureProbe
{
    /** @return array<string, mixed> */
    public function fetch(string $url): array
    {
        try {
            $media = Media::fromUrl($url)->fetchPublicUrlContent();
            $content = $media->rawContent() ?? '';

            return ['ok' => true, 'bytes' => strlen($content), 'mime_type' => $media->mimeType,
                'preview' => mb_scrub(substr($content, 0, 4096), 'UTF-8'), 'truncated' => strlen($content) > 4096];
        } catch (PrismUrlRefused $exception) {
            return ['ok' => false, 'code' => $exception->code(), 'reason' => $exception->getMessage()];
        } catch (Throwable $exception) {
            // Local response only: URLs and provider bodies can contain secrets.
            return ['ok' => false, 'code' => null, 'reason' => $exception->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    public function cache(string $model, string $prefix, string $question, string $followUp): array
    {
        $runs = [];
        foreach ([$question, $followUp] as $prompt) {
            try {
                $response = Prism::text()->using('anthropic', $model)
                    ->withMessages([
                        (new UserMessage($prefix))->withCacheHint(CacheStability::Stable),
                        (new UserMessage($prompt))->withCacheHint(CacheStability::Volatile),
                    ])
                    ->withMaxTokens(128)->withClientOptions(['timeout' => 60])->asText();
                $runs[] = ['answer' => $response->text, 'usage' => $response->usage->toArray()];
            } catch (Throwable $exception) {
                // Preserve the first call's evidence if the second one fails.
                return ['ok' => false, 'runs' => $runs, 'reason' => $exception->getMessage()];
            }
        }

        return ['ok' => true, 'runs' => $runs];
    }
}

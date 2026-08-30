<?php

declare(strict_types=1);

namespace App\Prompts;

use RuntimeException;

final class PromptFile
{
    /**
     * Load the Markdown body of a versioned prompt file.
     */
    public static function content(string $name): string
    {
        $path = dirname(__DIR__, 2).'/resources/prompts/'.$name.'.md';
        $source = file_get_contents($path);

        if ($source === false) {
            throw new RuntimeException("Prompt file [{$path}] could not be read.");
        }

        if (! preg_match('/\A---\R(?<frontmatter>.*?)\R---\R(?<content>.*)\z/s', $source, $matches)) {
            throw new RuntimeException("Prompt file [{$path}] must contain YAML frontmatter and a Markdown body.");
        }

        foreach (['id', 'mode', 'version'] as $key) {
            if (! preg_match('/^'.preg_quote($key, '/').':\s*\S.*$/m', $matches['frontmatter'])) {
                throw new RuntimeException("Prompt file [{$path}] is missing required frontmatter [{$key}].");
            }
        }

        $content = trim($matches['content']);

        if ($content === '') {
            throw new RuntimeException("Prompt file [{$path}] has an empty Markdown body.");
        }

        return $content;
    }
}

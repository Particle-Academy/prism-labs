<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Prompts\PromptFile;
use PHPUnit\Framework\TestCase;

final class PromptFileTest extends TestCase
{
    public function test_it_loads_every_system_prompt_from_markdown_with_required_frontmatter(): void
    {
        foreach (['chat', 'research', 'benchmark', 'coordinator'] as $name) {
            $source = file_get_contents(dirname(__DIR__, 2)."/resources/prompts/{$name}.md");
            $content = PromptFile::content($name);

            $this->assertStringStartsWith("---\n", $source);
            $this->assertStringContainsString("\nid: ", $source);
            $this->assertStringContainsString("\nmode: ", $source);
            $this->assertStringContainsString("\nversion: ", $source);
            $this->assertStringStartsWith('# ', $content);
            $this->assertStringNotContainsString('---', $content);
        }
    }
}

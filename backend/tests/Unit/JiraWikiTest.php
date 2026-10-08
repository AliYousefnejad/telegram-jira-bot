<?php

namespace Tests\Unit;

use App\Support\JiraWiki;
use Tests\TestCase;

class JiraWikiTest extends TestCase
{
    public function test_it_strips_the_heading_prefix_and_keeps_the_text(): void
    {
        $this->assertSame("Upload report\nDetails follow", JiraWiki::stripHeadings("h3. Upload report\nDetails follow"));
    }

    public function test_it_drops_bare_heading_lines(): void
    {
        $this->assertSame('Details follow', JiraWiki::stripHeadings("h3. \nDetails follow"));
    }

    public function test_it_handles_higher_heading_levels_and_trailing_whitespace(): void
    {
        $this->assertSame('Note   ', JiraWiki::stripHeadings('h1.   Note   '));
    }

    public function test_it_leaves_plain_text_untouched(): void
    {
        $this->assertSame("Plain text\n\nStill plain", JiraWiki::stripHeadings("Plain text\n\nStill plain"));
    }

    public function test_it_strips_headings_on_every_line(): void
    {
        $this->assertSame("One\nTwo", JiraWiki::stripHeadings("h2. One\nh4. Two"));
    }
}

<?php

declare(strict_types=1);

namespace Station\Tests\Unit\DTOs;

use PHPUnit\Framework\TestCase;
use Station\DTOs\PaginatedResult;

class PaginatedResultTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // empty()
    // ──────────────────────────────────────────────────────────────

    public function testEmptyReturnsZeroTotalWithDefaultPerPage(): void
    {
        $result = PaginatedResult::empty();

        $this->assertSame([], $result->data);
        $this->assertSame(0, $result->total);
        $this->assertSame(15, $result->per_page);
        $this->assertSame(1, $result->current_page);
        $this->assertSame(1, $result->last_page);
        $this->assertNull($result->from);
        $this->assertNull($result->to);
    }

    public function testEmptyWithCustomPerPage(): void
    {
        $result = PaginatedResult::empty(25);

        $this->assertSame(25, $result->per_page);
    }

    // ──────────────────────────────────────────────────────────────
    // buildLinks()
    // ──────────────────────────────────────────────────────────────

    public function testBuildLinksForSinglePage(): void
    {
        $links = PaginatedResult::buildLinks(1, 1, static fn(int $p): string => "/page/{$p}");

        // Previous (null url) + page 1 (active) + Next (null url)
        $this->assertCount(3, $links);
        $this->assertNull($links[0]['url']); // Previous
        $this->assertSame('1', $links[1]['label']);
        $this->assertTrue($links[1]['active']);
        $this->assertNull($links[2]['url']); // Next
    }

    public function testBuildLinksForFirstPage(): void
    {
        $links = PaginatedResult::buildLinks(1, 5, static fn(int $p): string => "/page/{$p}");

        // Previous (null) + pages 1-3 + ... + 5 + Next
        $this->assertNull($links[0]['url']); // Previous has no url on first page
        $this->assertTrue($links[1]['active']); // Page 1 is active
        $this->assertSame('/page/2', $links[array_key_last($links)]['url']); // Next points to page 2
    }

    public function testBuildLinksForLastPage(): void
    {
        $links = PaginatedResult::buildLinks(5, 5, static fn(int $p): string => "/page/{$p}");

        // Previous + 1 + ... + 3-5 + Next(null)
        $this->assertSame('/page/4', $links[0]['url']); // Previous points to page 4
        $lastLink = $links[array_key_last($links)];
        $this->assertNull($lastLink['url']); // Next has no url on last page
    }

    public function testBuildLinksForMiddlePage(): void
    {
        $links = PaginatedResult::buildLinks(5, 10, static fn(int $p): string => "/page/{$p}");

        // Previous + 1 + ... + 3-7 + ... + 10 + Next
        $this->assertSame('/page/4', $links[0]['url']); // Previous
        $this->assertSame('/page/6', $links[array_key_last($links)]['url']); // Next

        // Check that page 5 is active
        $activePage = array_filter($links, static fn(array $l): bool => $l['active']);
        $this->assertCount(1, $activePage);
        $activeLink = reset($activePage);
        $this->assertSame('5', $activeLink['label']);
    }

    public function testBuildLinksIncludesEllipsisForLargeRange(): void
    {
        $links = PaginatedResult::buildLinks(5, 10, static fn(int $p): string => "/page/{$p}");

        $labels = array_column($links, 'label');

        $this->assertContains('...', $labels);
    }

    public function testBuildLinksNoEllipsisWhenCloseToStart(): void
    {
        $links = PaginatedResult::buildLinks(2, 4, static fn(int $p): string => "/page/{$p}");

        $labels = array_column($links, 'label');

        // Pages 1-4 are all within range, no ellipsis needed
        $this->assertNotContains('...', $labels);
    }

    // ──────────────────────────────────────────────────────────────
    // fromArray
    // ──────────────────────────────────────────────────────────────

    public function testFromArrayDefaults(): void
    {
        $result = PaginatedResult::fromArray([]);

        $this->assertSame([], $result->data);
        $this->assertSame(0, $result->total);
        $this->assertSame(15, $result->per_page);
        $this->assertSame(1, $result->current_page);
        $this->assertSame(1, $result->last_page);
        $this->assertNull($result->from);
        $this->assertNull($result->to);
    }

    public function testFromArrayWithAllFields(): void
    {
        $data = [
            'data' => [['id' => 1], ['id' => 2]],
            'total' => 50,
            'per_page' => 25,
            'current_page' => 2,
            'last_page' => 2,
            'from' => 26,
            'to' => 50,
            'links' => [],
            'prev_page_url' => '/page/1',
            'next_page_url' => null,
            'first_page_url' => '/page/1',
            'last_page_url' => '/page/2',
            'path' => '/api/jobs',
        ];

        $result = PaginatedResult::fromArray($data);

        $this->assertSame(50, $result->total);
        $this->assertSame(25, $result->per_page);
        $this->assertSame(2, $result->current_page);
        $this->assertSame(26, $result->from);
        $this->assertSame(50, $result->to);
        $this->assertSame('/page/1', $result->prev_page_url);
        $this->assertNull($result->next_page_url);
        $this->assertSame('/api/jobs', $result->path);
    }

    // ──────────────────────────────────────────────────────────────
    // toArray / jsonSerialize
    // ──────────────────────────────────────────────────────────────

    public function testToArrayReturnsAllKeys(): void
    {
        $result = PaginatedResult::empty();
        $array = $result->toArray();

        $expectedKeys = [
            'data', 'total', 'per_page', 'current_page', 'last_page',
            'from', 'to', 'links', 'prev_page_url', 'next_page_url',
            'first_page_url', 'last_page_url', 'path',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    public function testJsonSerializeMatchesToArray(): void
    {
        $result = PaginatedResult::empty();

        $this->assertSame($result->toArray(), $result->jsonSerialize());
    }
}

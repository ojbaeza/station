<?php

declare(strict_types=1);

namespace Station\DTOs;

use JsonSerializable;

final readonly class PaginatedResult implements JsonSerializable
{
    /**
     * @param array<int, array{url: ?string, label: string, active: bool}> $links
     */
    public function __construct(
        public mixed $data,
        public int $total,
        public int $per_page,
        public int $current_page,
        public int $last_page,
        public ?int $from,
        public ?int $to,
        public array $links = [],
        public ?string $prev_page_url = null,
        public ?string $next_page_url = null,
        public ?string $first_page_url = null,
        public ?string $last_page_url = null,
        public ?string $path = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            data: $data['data'] ?? [],
            total: (int) ($data['total'] ?? 0),
            per_page: (int) ($data['per_page'] ?? 15),
            current_page: (int) ($data['current_page'] ?? 1),
            last_page: (int) ($data['last_page'] ?? 1),
            from: isset($data['from']) ? (int) $data['from'] : null,
            to: isset($data['to']) ? (int) $data['to'] : null,
            links: $data['links'] ?? [],
            prev_page_url: $data['prev_page_url'] ?? null,
            next_page_url: $data['next_page_url'] ?? null,
            first_page_url: $data['first_page_url'] ?? null,
            last_page_url: $data['last_page_url'] ?? null,
            path: $data['path'] ?? null,
        );
    }

    public static function empty(int $perPage = 15): self
    {
        return new self(
            data: [],
            total: 0,
            per_page: $perPage,
            current_page: 1,
            last_page: 1,
            from: null,
            to: null,
        );
    }

    /**
     * Build pagination links array.
     *
     * @param callable(int): string $urlBuilder
     * @return array<int, array{url: ?string, label: string, active: bool}>
     */
    public static function buildLinks(int $page, int $lastPage, callable $urlBuilder): array
    {
        $links = [];

        $links[] = [
            'url' => $page > 1 ? $urlBuilder($page - 1) : null,
            'label' => '&laquo; Previous',
            'active' => false,
        ];

        $startPage = max(1, $page - 2);
        $endPage = min($lastPage, $page + 2);

        if ($startPage > 1) {
            $links[] = ['url' => $urlBuilder(1), 'label' => '1', 'active' => false];
            if ($startPage > 2) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
        }

        for ($i = $startPage; $i <= $endPage; $i++) {
            $links[] = [
                'url' => $urlBuilder($i),
                'label' => (string) $i,
                'active' => $i === $page,
            ];
        }

        if ($endPage < $lastPage) {
            if ($endPage < $lastPage - 1) {
                $links[] = ['url' => null, 'label' => '...', 'active' => false];
            }
            $links[] = ['url' => $urlBuilder($lastPage), 'label' => (string) $lastPage, 'active' => false];
        }

        $links[] = [
            'url' => $page < $lastPage ? $urlBuilder($page + 1) : null,
            'label' => 'Next &raquo;',
            'active' => false,
        ];

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'per_page' => $this->per_page,
            'current_page' => $this->current_page,
            'last_page' => $this->last_page,
            'from' => $this->from,
            'to' => $this->to,
            'links' => $this->links,
            'prev_page_url' => $this->prev_page_url,
            'next_page_url' => $this->next_page_url,
            'first_page_url' => $this->first_page_url,
            'last_page_url' => $this->last_page_url,
            'path' => $this->path,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

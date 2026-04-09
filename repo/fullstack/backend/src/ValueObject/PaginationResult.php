<?php

declare(strict_types=1);

namespace App\ValueObject;

final class PaginationResult implements \JsonSerializable
{
    /**
     * @param array<mixed> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $page,
        private readonly int $perPage,
        private readonly int $total,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('Page must be at least 1.');
        }
        if ($perPage < 1) {
            throw new \InvalidArgumentException('Per page must be at least 1.');
        }
        if ($total < 0) {
            throw new \InvalidArgumentException('Total must be non-negative.');
        }
    }

    /**
     * @return array<mixed>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getPage(): int
    {
        return $this->page;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function hasNext(): bool
    {
        return ($this->page * $this->perPage) < $this->total;
    }

    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'page' => $this->page,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'has_next' => $this->hasNext(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}

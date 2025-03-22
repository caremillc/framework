<?php
namespace App\Support\Pagination;

class Paginator
{
    public function __construct(
        protected array $items,
        protected int $total,
        protected int $perPage,
        protected int $currentPage
    ) {}

    public function getItems(): array
    {
        return $this->items;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function lastPage(): int
    {
        return (int) ceil($this->total / $this->perPage);
    }

    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }
}



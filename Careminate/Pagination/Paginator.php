<?php 
namespace Careminate\Databases\Pagination;

use Traversable;
use IteratorAggregate;
use Careminate\Databases\QueryBuilder\Collection;

class Paginator implements IteratorAggregate
{
    /**
     * @var int
     */
    protected int $totalPages;

    /**
     * @param  protected ?Collection $data
     * @param  protected int $total
     * @param  protected int $currentPage
     * @param  protected int $perPage
     */
    public function __construct(protected ?Collection $data, protected int $total, protected int $currentPage, protected int $perPage)
    {
        $this->totalPages = (int) ceil($this->total / $this->perPage);
    }

    /**
     * @return Traversable
     */
    public function getIterator(): Traversable
    {
        return $this->data ?? new Collection([]);
    }

    /**
     * @return Collection
     */
    public function getData(): Collection
    {
        return $this->data ?? new Collection([]);
    }

    /**
     * @return int
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * @return int
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * @return bool
     */
    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    /**
     * @return bool
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    /**
     * @return int
     */
    public function lastPage(): int
    {
        return $this->totalPages;
    }

    /**
     * @return int
     */
    public function firstPage(): int
    {
        return 1;
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $currentPage = $this->getCurrentPage();
        $totalPages = $this->totalPages;
        $paginateHtml = '<nav area-label="Page Naviagation">';
        $paginateHtml .= '<ul class="pagination">';
        if ($this->hasPreviousPage()) {
            $prev = $currentPage - 1;
            $first = url('?page=' . 1);
            $paginateHtml .= '<li class="page-item"><a href="' . $first . '" class="page-link">First</a></li>';
            $paginateHtml .= '<li class="page-item"><a href="' . url('?page=' . $prev) . '" class="page-link">Previous</a></li>';
        }

        for ($i = 1; $i <= $totalPages; $i++) {
            $url = url('?page=' . $i);
            $activeClass = $currentPage == $i ? 'active' : '';
            $paginateHtml .= '<li class="page-item"><a class="page-link ' . $activeClass . '" href="' . $url . '">' . $i . '</a></li>';
        }

        if ($this->hasNextPage()) {
            $next = $currentPage + 1;
            $last = url('?page=' . $totalPages);
            $paginateHtml .= '<li class="page-item"><a href="' . $last . '" class="page-link">Last</a></li>';
            $paginateHtml .= '<li class="page-item"><a href="' . url('?page=' . $next) . '" class="page-link">Next</a></li>';
        }

        $paginateHtml .= '</ul>';
        $paginateHtml .= '</nav>';
        return $paginateHtml;
    }
}

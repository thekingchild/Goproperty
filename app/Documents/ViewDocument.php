<?php

declare(strict_types=1);

namespace App\Documents;

/** A Document made of its parts: a view, its data and its branding. */
final readonly class ViewDocument implements Document
{
    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private string $view,
        private array $data,
        private DocumentBranding $brand,
        private string $fileName = 'document.pdf',
    ) {}

    public function view(): string
    {
        return $this->view;
    }

    public function data(): array
    {
        return $this->data;
    }

    public function brand(): DocumentBranding
    {
        return $this->brand;
    }

    public function fileName(): string
    {
        return $this->fileName;
    }
}

<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

final readonly class ModerationContent
{
    /**
     * @param  list<Image>  $images
     * @param  list<Video>  $videos
     */
    private function __construct(
        private ?string $text,
        private array $images,
        private array $videos,
    ) {}

    public static function make(): self
    {
        return new self(null, [], []);
    }

    public function text(): ?string
    {
        return $this->text;
    }

    /** @return iterable<Image> */
    public function images(): iterable
    {
        yield from $this->images;
    }

    /** @return iterable<Video> */
    public function videos(): iterable
    {
        yield from $this->videos;
    }

    public function hasText(): bool
    {
        return $this->text !== null && $this->text !== '';
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }

    public function hasVideos(): bool
    {
        return $this->videos !== [];
    }

    public function withText(?string $text): self
    {
        return new self($text, $this->images, $this->videos);
    }

    /** @param  iterable<Image>  $images */
    public function withImages(iterable $images): self
    {
        return new self($this->text, $this->toList($images), $this->videos);
    }

    /** @param  iterable<Video>  $videos */
    public function withVideos(iterable $videos): self
    {
        return new self($this->text, $this->images, $this->toList($videos));
    }

    /**
     * @template T
     *
     * @param  iterable<T>  $items
     * @return list<T>
     */
    private function toList(iterable $items): array
    {
        return is_array($items)
            ? array_values($items)
            : iterator_to_array($items, preserve_keys: false);
    }
}

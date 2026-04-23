<?php

declare(strict_types=1);

use Dynamik\Modman\Support\Image;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Video;

it('builds an empty content with null text and no media', function (): void {
    $content = ModerationContent::make();
    expect($content->text())->toBeNull();
    expect(iterator_to_array($content->images()))->toBe([]);
    expect(iterator_to_array($content->videos()))->toBe([]);
});

it('is immutable — withText returns a new instance', function (): void {
    $a = ModerationContent::make();
    $b = $a->withText('hello');
    expect($a->text())->toBeNull();
    expect($b->text())->toBe('hello');
});

it('carries images', function (): void {
    $img = new Image('https://example.com/a.jpg');
    $content = ModerationContent::make()->withImages([$img]);
    expect(iterator_to_array($content->images()))->toBe([$img]);
});

it('reports whether it has text or images', function (): void {
    expect(ModerationContent::make()->hasText())->toBeFalse();
    expect(ModerationContent::make()->withText('x')->hasText())->toBeTrue();
    expect(ModerationContent::make()->hasImages())->toBeFalse();
    expect(ModerationContent::make()->withImages([new Image('x')])->hasImages())->toBeTrue();
});

it('carries videos', function (): void {
    $video = new Video('https://example.com/a.mp4');
    $content = ModerationContent::make()->withVideos([$video]);
    expect(iterator_to_array($content->videos()))->toBe([$video]);
});

it('reports whether it has videos', function (): void {
    expect(ModerationContent::make()->hasVideos())->toBeFalse();
    expect(
        ModerationContent::make()
            ->withVideos([new Video('x')])
            ->hasVideos()
    )->toBeTrue();
});

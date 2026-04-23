<?php

declare(strict_types=1);

use Dynamik\Modman\Support\Video;

it('holds a URL and optional mime type', function (): void {
    $video = new Video(url: 'https://example.com/a.mp4', mimeType: 'video/mp4');
    expect($video->url)->toBe('https://example.com/a.mp4');
    expect($video->mimeType)->toBe('video/mp4');
});

it('accepts a null mime type', function (): void {
    $video = new Video(url: 'https://example.com/b.webm');
    expect($video->mimeType)->toBeNull();
});

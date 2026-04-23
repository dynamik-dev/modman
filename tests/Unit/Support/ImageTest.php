<?php

declare(strict_types=1);

use Dynamik\Modman\Support\Image;

it('holds a URL and optional mime type', function (): void {
    $image = new Image(url: 'https://example.com/a.jpg', mimeType: 'image/jpeg');
    expect($image->url)->toBe('https://example.com/a.jpg');
    expect($image->mimeType)->toBe('image/jpeg');
});

it('accepts a null mime type', function (): void {
    $image = new Image(url: 'https://example.com/b.png');
    expect($image->mimeType)->toBeNull();
});

<?php

declare(strict_types=1);

namespace Dynamik\Modman\Graders;

use Closure;
use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Models\Report;
use Dynamik\Modman\Support\ModerationContent;
use Dynamik\Modman\Support\Verdict;
use Dynamik\Modman\Support\VerdictKind;

final class PerceptualHashGrader implements Grader
{
    /** @var list<string> */
    private readonly array $knownHashes;

    private readonly ?Closure $hasher;

    /** @param  list<string>  $knownHashes */
    public function __construct(array $knownHashes, ?Closure $hasher)
    {
        $this->knownHashes = $knownHashes;
        $this->hasher = $hasher;
    }

    public function key(): string
    {
        return 'perceptual_hash';
    }

    public function supports(ModerationContent $content): bool
    {
        return $content->hasImages() && $this->hasher !== null;
    }

    public function grade(ModerationContent $content, Report $report): Verdict
    {
        if ($this->hasher === null) {
            return new Verdict(VerdictKind::Skipped, 0.0, 'no hasher configured');
        }

        $hits = [];
        foreach ($content->images() as $image) {
            $hash = ($this->hasher)($image);
            if (is_string($hash) && in_array($hash, $this->knownHashes, true)) {
                $hits[] = ['url' => $image->url, 'hash' => $hash];
            }
        }

        if ($hits === []) {
            return new Verdict(VerdictKind::Approve, 0.0, 'no hash matches');
        }

        return new Verdict(
            VerdictKind::Reject,
            1.0,
            'perceptual hash match',
            ['hits' => $hits],
        );
    }
}

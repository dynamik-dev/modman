<?php

declare(strict_types=1);

namespace Dynamik\Modman\Tests\Fixtures;

use Dynamik\Modman\Concerns\Reportable as ReportableTrait;
use Dynamik\Modman\Contracts\Reportable;
use Dynamik\Modman\Support\ModerationContent;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class TestReportable extends Model implements Authenticatable, Reportable
{
    use AuthenticatableTrait;
    use HasUlids;
    use ReportableTrait;

    protected $table = 'test_reportables';

    protected $guarded = [];

    public function toModerationContent(): ModerationContent
    {
        $content = ModerationContent::make();

        $body = $this->getAttribute('body');
        if (is_string($body) && $body !== '') {
            $content = $content->withText($body);
        }

        return $content;
    }
}

<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

enum VerdictKind: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Inconclusive = 'inconclusive';
    case Error = 'error';
}

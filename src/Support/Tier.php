<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

enum Tier: string
{
    case Denylist = 'denylist';
    case Heuristic = 'heuristic';
    case HostedClassifier = 'hosted_classifier';
    case Llm = 'llm';
    case Human = 'human';
}

<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

/**
 * Verdict kinds the audit trail can contain.
 *
 * The spec at docs/superpowers/specs/2026-04-23-modman-design.md lists the
 * grader-facing verdicts as approve|reject|inconclusive|error. `Skipped` is
 * orchestrator-only: it is recorded when a grader's `supports()` returns
 * false so the audit trail explains why the pipeline advanced past it.
 * Consumers iterating decisions should treat `Skipped` as "not counted
 * toward policy" — the policy only sees grader-produced verdicts.
 */
enum VerdictKind: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Inconclusive = 'inconclusive';
    case Error = 'error';

    /**
     * Recorded by the orchestrator when `Grader::supports()` returns false
     * for the current content. Not produced by graders themselves and not
     * passed to `ModerationPolicy::decide()`.
     */
    case Skipped = 'skipped';
}

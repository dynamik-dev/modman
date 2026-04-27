<?php

declare(strict_types=1);

namespace Dynamik\Modman\Support;

/**
 * Marker interface for the policy-action sealed union.
 *
 * Implementing `PolicyAction` outside this package is unsupported in v1.
 * The orchestrator only handles the four shipped actions:
 * `Approve`, `Reject`, `EscalateTo`, `RouteToHuman`. Any unknown
 * implementer triggers a `RuntimeException` from `Orchestrator::step()`.
 *
 * An architecture test asserts that only classes under
 * `Dynamik\Modman\Support\PolicyActions` implement this interface.
 */
interface PolicyAction {}

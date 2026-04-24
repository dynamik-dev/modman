# ADR 0003: Single-package topology

## Context

The codebase splits cleanly along three axes — the core pipeline (states, orchestrator, policy), the HTTP surface (three endpoints), and the grader implementations (denylist, LLM, heuristic, OpenAI moderation, perceptual hash). A reasonable-sounding design would be `modman-core`, `modman-http`, and `modman-graders` as three Composer packages, letting consumers pull only what they need. It mirrors how many Laravel ecosystems organize — Spatie, for instance, often ships an addon per integration.

## Decision

Ship one package: `dynamik-dev/modman`. Graders are already opt-in through the `pipeline` config key (`DenylistGrader` and `LlmGrader` are the only two registered by default; the rest are shipped but inert). The HTTP routes are trivial — three endpoints behind the standard `api` middleware group — and removing them means editing the service provider, not a package boundary. v1 is headless, so there is no admin UI package to split out either.

## Consequences

One composer package, one version number, one CHANGELOG. Blast radius for a refactor is tiny: we can rename an internal class without a coordinated release across three repos. No external API surface to churn through deprecation cycles in sibling packages. The cost is that a consumer who somehow only wants the states and the HTTP controllers still pulls the grader classes — but those classes are a few hundred lines of code and cost nothing at runtime unless the pipeline config references them. If the codebase grows past that threshold (e.g. we add a heavyweight media-analysis grader with big dependencies), we'll extract *that* grader into its own package rather than preemptively fragmenting the rest.

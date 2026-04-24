# ADR 0004: Headless v1

## Context

Moderation packages in the Laravel ecosystem typically ship with an admin UI — a Filament resource, a Nova tool, or a dedicated Blade/Livewire dashboard. It's the first thing a mod team asks for. The pull is real: without a UI, a new consumer has to build one before they can resolve any reports. But bundling a UI means either picking an opinionated framework (Filament? Nova? Livewire? Inertia?) that forces that choice on every consumer, or building multiple UI packages and maintaining them in parallel. It also means the UI's design language — colors, icons, layouts — becomes part of the package's surface.

## Decision

v1 ships HTTP endpoints (`GET /modman/reports/{id}`, `POST .../resolve`, `POST .../reopen`) and nothing else. No Filament resource, no Nova tool, no Blade components, no Vue/React frontend. Consumers build their own moderation UI against the three endpoints and the documented events.

## Consequences

The package is small, framework-agnostic within Laravel, and usable in any host — a Filament app, a Nova app, a hand-rolled Livewire dashboard, a Next.js admin talking to the endpoints over HTTP, or a CMS-agnostic setup where moderation queue lives in an entirely separate deploy. The cost is that "install modman" doesn't give you a working moderation queue on day one; it gives you the engine and you bring the cockpit. We consider that cost acceptable because the alternative — maintaining, say, a Filament resource — couples our release cadence to Filament's major versions and pushes every non-Filament consumer to either delete our UI or live with an unused dependency tree. A separate `dynamik-dev/modman-filament` package is a reasonable future addition, but it stays opt-in and out of v1.

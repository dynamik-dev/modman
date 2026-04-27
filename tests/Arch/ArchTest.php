<?php

declare(strict_types=1);

use Dynamik\Modman\Contracts\Grader;
use Dynamik\Modman\Contracts\ModerationPolicy;
use Dynamik\Modman\Support\PolicyAction;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Spatie\ModelStates\State;
use Spatie\ModelStates\Transition;

arch('no debugging calls anywhere in src/')
    ->expect(['dd', 'dump', 'var_dump', 'die', 'exit', 'ray'])
    ->not->toBeUsed();

arch('strict types everywhere in src/')
    ->expect('Dynamik\Modman')
    ->toUseStrictTypes();

arch('contracts are interfaces only')
    ->expect('Dynamik\Modman\Contracts')
    ->toBeInterfaces();

arch('graders are final and implement the Grader contract')
    ->expect('Dynamik\Modman\Graders')
    ->classes()
    ->toBeFinal()
    ->toImplement(Grader::class)
    ->ignoring('Dynamik\Modman\Graders\Testing');

arch('testing graders live under Graders\\Testing and implement the contract')
    ->expect('Dynamik\Modman\Graders\Testing')
    ->classes()
    ->toBeFinal()
    ->toImplement(Grader::class);

arch('policies are final and implement ModerationPolicy')
    ->expect('Dynamik\Modman\Policy')
    ->classes()
    ->toBeFinal()
    ->toImplement(ModerationPolicy::class);

arch('events are final readonly plain classes')
    ->expect('Dynamik\Modman\Events')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

arch('models extend Eloquent')
    ->expect('Dynamik\Modman\Models')
    ->toExtend(Model::class);

arch('jobs implement ShouldQueue and are final')
    ->expect('Dynamik\Modman\Jobs')
    ->classes()
    ->toBeFinal()
    ->toImplement(ShouldQueue::class);

arch('support value objects have no illuminate/database dep')
    ->expect('Dynamik\Modman\Support')
    ->not->toUse([
        'Illuminate\Database',
        'Illuminate\Http\Client',
        'GuzzleHttp',
    ]);

arch('src does not depend on tests')
    ->expect('Dynamik\Modman')
    ->not->toUse(['Dynamik\Modman\Tests']);

arch('no direct Guzzle usage in src; use Http:: facade')
    ->expect('Dynamik\Modman')
    ->not->toUse([Client::class, ClientInterface::class]);

arch('states extend Spatie\\ModelStates\\State')
    ->expect('Dynamik\Modman\States')
    ->classes()
    ->toExtend(State::class);

arch('transitions extend Spatie\\ModelStates\\Transition')
    ->expect('Dynamik\Modman\Transitions')
    ->classes()
    ->toExtend(Transition::class);

arch('events are plain PHP objects with no Laravel event traits')
    ->expect('Dynamik\Modman\Events')
    ->not->toUse([
        Dispatchable::class,
        SerializesModels::class,
        InteractsWithSockets::class,
    ]);

// task-10: PolicyAction is a sealed union — only the shipped actions in
// Support\PolicyActions may implement it. Adding a new implementer outside
// that namespace will fail this test.
arch('PolicyAction is sealed to Support\\PolicyActions')
    ->expect(PolicyAction::class)
    ->toOnlyBeUsedIn([
        'Dynamik\Modman\Support\PolicyActions',
        'Dynamik\Modman\Contracts',
        'Dynamik\Modman\Policy',
        'Dynamik\Modman\Pipeline',
    ]);

arch('all PolicyActions are final readonly and implement the marker interface')
    ->expect('Dynamik\Modman\Support\PolicyActions')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly()
    ->toImplement(PolicyAction::class);

// task-29: graders are stateless — lock that in by requiring readonly classes.
arch('graders are final readonly')
    ->expect('Dynamik\Modman\Graders')
    ->classes()
    ->toBeReadonly()
    ->ignoring('Dynamik\Modman\Graders\Testing');

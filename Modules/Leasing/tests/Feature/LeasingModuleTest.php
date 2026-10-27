<?php

declare(strict_types=1);

use Modules\Leasing\Contracts\BillingCycle;
use Modules\Leasing\Contracts\CycleCalculator as CycleCalculatorContract;
use Modules\Leasing\CycleCalculator;
use Nwidart\Modules\Facades\Module;

/*
 * P03-02: the Leasing module is on and other modules reach the cycle maths
 * through its contract (ARCHITECTURE.md § 8 rule 3, AGENT.md § 3 rule 3).
 */

it('is an enabled module', function () {
    expect(Module::find('Leasing'))->not->toBeNull()
        ->and(Module::isEnabled('Leasing'))->toBeTrue();
});

it('binds the cycle calculator contract to the one calculator, once', function () {
    $calculator = app(CycleCalculatorContract::class);

    expect($calculator)->toBeInstanceOf(CycleCalculator::class)
        ->and(app(CycleCalculatorContract::class))->toBe($calculator)
        ->and($calculator->periodStart(today(), BillingCycle::Monthly, 0)->toDateString())->toBe(today()->toDateString());
});

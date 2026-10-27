<?php

declare(strict_types=1);

/*
 * AGENT.md § 3 rule 3 (P03-02): lease and bill period dates come from
 * Modules\Leasing\CycleCalculator only. This scan bans hand-rolled interval
 * maths — Carbon's add…/sub… for days and longer, its generic add/sub/plus/
 * minus with a date unit, modify(), interval and period classes, strtotime()
 * and mktime() — anywhere in the modules below except the calculator.
 *
 * It reads every PHP file (Blade views too) under each module except its
 * tests, so code those modules gain later is covered without touching this
 * file. Comments are ignored. Times shorter than a day (addMinutes for a
 * link's expiry, say) are not period maths and stay allowed. A module that
 * starts doing period maths (Reminders, Payments…) belongs in the list.
 */

/** Modules whose code must leave period maths to the calculator. */
const LEASING_CYCLE_MATH_MODULES = ['Leasing', 'Billing'];

/** The one file allowed to do the maths, relative to Modules/. */
const LEASING_CYCLE_MATH_HOME = 'Leasing/app/CycleCalculator.php';

/**
 * Banned constructs, as case-insensitive patterns over code with comments
 * removed.
 *
 * @return array<string, string>
 */
function leasingBannedCycleMath(): array
{
    $unitString = '[\'"]\s*[+-]?\s*\d*\s*(?:days?|weekdays?|weeks?|months?|quarters?|years?|decades?)\s*[\'"]';

    return [
        'Carbon add…/sub… by days or longer' => '/->\s*(?:add|sub)(?:Real|UTC)?(?:Day|Week|Month|Quarter|Year|Decade|Centur|Millenni)\w*\s*\(/i',
        'Carbon add/sub by unit' => '/->\s*(?:raw)?(?:add|sub)(?:Real|UTC)?Unit\w*\s*\(|->\s*raw(?:add|sub)\s*\(|->\s*setUnitNoOverflow\s*\(/i',
        'Carbon add/sub with a date unit or interval' => '/->\s*(?:add|sub)\s*\([^;]*?(?:Interval\b|'.$unitString.')/i',
        'Carbon plus/minus with a date unit' => '/->\s*(?:plus|minus)\s*\([^;]*?\b(?:years|months|weeks|days)\s*:/i',
        'modify()' => '/->\s*modify\s*\(|\bdate_modify\s*\(/i',
        'interval or period class' => '/\b(?:CarbonInterval|DateInterval|CarbonPeriod|CarbonPeriodImmutable|DatePeriod)\b/',
        'date arithmetic function' => '/(?<![\w$>:])(?:strtotime|date_add|date_sub|mktime|gmmktime)\s*\(/i',
    ];
}

/**
 * Where the code does interval maths, as "line N: what" strings.
 *
 * @return list<string>
 */
function leasingCycleMathViolations(string $source): array
{
    // Drop comments but keep their line breaks, so line numbers still match.
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= str_repeat("\n", substr_count($token[1], "\n"));
        } else {
            $code .= is_array($token) ? $token[1] : $token;
        }
    }

    $violations = [];

    foreach (leasingBannedCycleMath() as $what => $pattern) {
        preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$match, $offset]) {
            $violations[] = 'line '.(substr_count($code, "\n", 0, $offset) + 1).": {$what}: ".trim($match);
        }
    }

    return $violations;
}

/**
 * PHP files of the listed modules, outside their tests, keyed by their path
 * under Modules/.
 *
 * @return array<string, string>
 */
function leasingCycleMathFiles(string $modules): array
{
    $files = [];

    foreach (LEASING_CYCLE_MATH_MODULES as $module) {
        if (! is_dir("{$modules}/{$module}")) {
            continue; // Not built yet (Billing arrives in P03-04b); covered once it exists.
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$modules}/{$module}", FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($modules) + 1);

            if ($file->getExtension() === 'php' && ! str_starts_with($relative, "{$module}/tests/")) {
                $files[$relative] = $file->getPathname();
            }
        }
    }

    ksort($files);

    return $files;
}

it('keeps interval maths in Leasing and Billing inside CycleCalculator', function () {
    $modules = dirname(__DIR__, 3);
    $files = leasingCycleMathFiles($modules);
    $violations = [];

    foreach ($files as $relative => $path) {
        if ($relative === LEASING_CYCLE_MATH_HOME) {
            continue;
        }

        foreach (leasingCycleMathViolations((string) file_get_contents($path)) as $violation) {
            $violations[] = "Modules/{$relative} {$violation}";
        }
    }

    expect($violations)->toBe([], 'Use Modules\Leasing\Contracts\CycleCalculator for period dates (AGENT.md § 3 rule 3).')
        ->and($files)->toHaveKey(LEASING_CYCLE_MATH_HOME)
        ->and($files)->toHaveKey('Leasing/app/Providers/LeasingServiceProvider.php');
});

it('spots each banned form of interval maths', function (string $code) {
    expect(leasingCycleMathViolations("<?php\n".$code))->not->toBe([]);
})->with([
    'addMonth' => '$date->addMonth();',
    'addMonths' => '$date->addMonths(3);',
    'addMonthsNoOverflow' => '$date->addMonthsNoOverflow(1);',
    'addMonthWithOverflow' => '$date->addMonthWithOverflow();',
    'subMonth' => '$date->subMonth();',
    'addYear' => 'now()->addYear();',
    'addYears' => '$lease->start_date->addYears(2);',
    'subYearsNoOverflow' => '$date->subYearsNoOverflow(1);',
    'addQuarter' => '$date->addQuarters(1);',
    'addWeek' => 'today()->addWeek();',
    'addWeeks' => '$date->addWeeks(2);',
    'addWeekdays' => '$date->addWeekdays(5);',
    'addDay' => '$date->addDay();',
    'addDays' => 'now()->addDays(7);',
    'subDays' => '$date->subDays(7);',
    'subDay with spaces' => '$date -> subDay ();',
    'addRealDays' => '$date->addRealDays(1);',
    'addUnit' => '$date->addUnit("month", 1);',
    'subUnit' => '$date->subUnit("year", 1);',
    'rawAdd' => '$date->rawAdd($interval);',
    'add with a unit string' => '$date->add(1, \'month\');',
    'add with an interval' => '$date->add(CarbonInterval::months(1));',
    'sub with a unit string' => '$date->sub("2 weeks");',
    'plus with named units' => '$date->plus(months: 1);',
    'minus with named units' => '$date->minus(days: 3);',
    'modify' => '$date->modify("+1 month");',
    'date_modify' => 'date_modify($date, "+1 month");',
    'DateInterval' => 'new DateInterval("P1M");',
    'CarbonPeriod' => 'CarbonPeriod::create($a, "1 month", $b);',
    'strtotime' => 'strtotime("+1 month", $time);',
    'mktime' => 'mktime(0, 0, 0, $month + 1, $day, $year);',
    'in a Blade echo' => '?><p>{{ $bill->due_date->addDays(7) }}</p>',
]);

it('lets ordinary code and comments through', function () {
    $code = <<<'PHP'
        <?php
        // Never $date->addMonth() here; ask the calculator. See also DateInterval.
        /** @see CarbonInterface::addMonths() */
        $expires = now()->addMinutes(30);
        $until = now()->addHours(24);
        $total = $money->plus($other)->minus($discount);
        $errors->add('cycle', 'Pick a month');
        $items->add($line);
        $next = $calculator->nextPeriodStart($lease->start_date, $cycle, $lease->next_billing_date);
        $label = 'Every six months';
        PHP;

    expect(leasingCycleMathViolations($code))->toBe([]);
});

it('names the line of each violation', function () {
    expect(leasingCycleMathViolations("<?php\n\$a = 1;\n/*\n comment\n*/\n\$b = \$date->addMonths(1);\n"))
        ->toBe(['line 6: Carbon add…/sub… by days or longer: ->addMonths(']);
});

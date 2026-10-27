<?php

declare(strict_types=1);

use App\Support\JsonValues;

it('treats maps that differ only in key order as the same', function () {
    expect(JsonValues::same(['primary' => '#0F1B44', 'accent' => '#F59E0B'], ['accent' => '#F59E0B', 'primary' => '#0F1B44']))->toBeTrue()
        ->and(JsonValues::same(
            [['question' => 'Q', 'answer' => 'A']],
            [['answer' => 'A', 'question' => 'Q']],
        ))->toBeTrue();
});

it('keeps list order, values and types significant', function () {
    expect(JsonValues::same(['a', 'b'], ['b', 'a']))->toBeFalse()
        ->and(JsonValues::same(['x' => 1], ['x' => '1']))->toBeFalse()
        ->and(JsonValues::same(['x' => 1], ['x' => 1, 'y' => null]))->toBeFalse()
        ->and(JsonValues::same(null, []))->toBeFalse()
        ->and(JsonValues::same('same', 'same'))->toBeTrue();
});

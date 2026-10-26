<?php

declare(strict_types=1);

use App\Support\PhoneNumber;

it('normalises Nigerian numbers to +234', function (string $typed, ?string $expected) {
    expect(PhoneNumber::normalize($typed))->toBe($expected);
})->with([
    ['0803 123 4567', '+2348031234567'],
    ['08031234567', '+2348031234567'],
    ['+234 803 123 4567', '+2348031234567'],
    ['2348031234567', '+2348031234567'],
    ['00234-803-123-4567', '+2348031234567'],
    ['(0803) 123.4567', '+2348031234567'],
    ['8031234567', '+2348031234567'],
    ['+44 20 7946 0958', '+442079460958'],
    ['+2340803123456', null],
    ['0803 123 456', null],
    ['12345', null],
    ['call me', null],
    ['', null],
]);

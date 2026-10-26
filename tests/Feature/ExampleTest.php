<?php

declare(strict_types=1);

test('the home page returns a successful response', function () {
    $this->get(route('home'))->assertOk();
});

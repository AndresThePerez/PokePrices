<?php

declare(strict_types=1);

// Plan P6-DEPLOY-i1 T03 (g): the SMTP timeout is a knob, MAIL_TIMEOUT, an integer number of seconds, 5 by default.

/**
 * Evaluates config/mail.php with MAIL_TIMEOUT set to $value (null = unset), then restores the variable.
 */
function mtSmtpTimeoutWith(?string $value): mixed
{
    $before = [$_SERVER['MAIL_TIMEOUT'] ?? null, $_ENV['MAIL_TIMEOUT'] ?? null, getenv('MAIL_TIMEOUT')];
    unset($_SERVER['MAIL_TIMEOUT'], $_ENV['MAIL_TIMEOUT']);
    putenv('MAIL_TIMEOUT');
    if ($value !== null) {
        $_SERVER['MAIL_TIMEOUT'] = $value;
        $_ENV['MAIL_TIMEOUT'] = $value;
        putenv('MAIL_TIMEOUT='.$value);
    }
    try {
        $config = require config_path('mail.php');
    } finally {
        unset($_SERVER['MAIL_TIMEOUT'], $_ENV['MAIL_TIMEOUT']);
        putenv('MAIL_TIMEOUT');
        if ($before[0] !== null) {
            $_SERVER['MAIL_TIMEOUT'] = $before[0];
        }
        if ($before[1] !== null) {
            $_ENV['MAIL_TIMEOUT'] = $before[1];
        }
        if ($before[2] !== false) {
            putenv('MAIL_TIMEOUT='.$before[2]);
        }
    }

    return $config['mailers']['smtp']['timeout'];
}

it('(g) boots with the SMTP timeout as the integer 5', function (): void {
    expect(config('mail.mailers.smtp.timeout'))->toBe(5);
});

it('(g) defaults the SMTP timeout to the integer 5 when MAIL_TIMEOUT is unset', function (): void {
    expect(mtSmtpTimeoutWith(null))->toBe(5);
});

it('(g) follows MAIL_TIMEOUT as an integer', function (string $value, int $expected): void {
    expect(mtSmtpTimeoutWith($value))->toBe($expected);
})->with([
    '12' => ['12', 12],
    '30' => ['30', 30],
]);

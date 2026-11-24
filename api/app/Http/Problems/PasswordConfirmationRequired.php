<?php

declare(strict_types=1);

namespace App\Http\Problems;

use App\Domain\ProblemType;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The route needs a recently confirmed password and this session has none: 423 password-confirmation-required. */
final class PasswordConfirmationRequired extends HttpException implements ProblemType
{
    public function __construct()
    {
        parent::__construct(423, 'Confirm your password first.');
    }

    public function slug(): string
    {
        return 'password-confirmation-required';
    }

    public function title(): string
    {
        return 'Password Confirmation Required';
    }

    public function extra(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

arch('no debugging helpers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();

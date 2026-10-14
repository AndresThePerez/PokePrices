<?php

declare(strict_types=1);

// Alert delivery settings (plan P5-i1 D14). T01 adds the catalog grace; later tasks add their keys.
return [
    // Minutes after the deliverable day's newest ok run finished during which a degraded catalog read defers the
    // creation of deliveries (D3, review A10.1); after it, deliveries are created with whatever names the read gave.
    'catalog_grace_minutes' => 30,
];

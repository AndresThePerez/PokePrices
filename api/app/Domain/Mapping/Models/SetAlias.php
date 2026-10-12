<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One PokéSearch set → TCGplayer group row (plan T36/T37). Constraints live in the migration.
 *
 * @property int $id
 * @property string $pokesearch_set_id
 * @property string $source
 * @property int $source_group_id
 * @property string $role
 * @property string|null $number_rule
 * @property string $method
 * @property string|null $unlimited_means
 * @property string $note
 */
#[Table('set_aliases', timestamps: false)]
#[Guarded(['id'])]
final class SetAlias extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_group_id' => 'integer',
        ];
    }
}

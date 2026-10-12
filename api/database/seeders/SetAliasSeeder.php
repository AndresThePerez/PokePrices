<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Mapping\Models\SetAlias;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads database/data/set_aliases.csv into set_aliases (plan T37). The CSV is the whole truth for source
 * `tcgplayer`: rows are upserted on (source, set, group) and rows the CSV no longer lists are removed, so running
 * the seeder twice changes nothing. A row with an empty group (fut20, role `none`) cannot satisfy T36's CHECKs
 * and is skipped; it exists so the table stays total over the dataset's set ids.
 */
final class SetAliasSeeder extends Seeder
{
    public const string SOURCE = 'tcgplayer';

    public const array HEADER = ['pokesearch_set_id', 'source_group_id', 'role', 'number_rule', 'method', 'unlimited_means', 'note'];

    public function run(): void
    {
        $rows = self::storableRows(database_path('data/set_aliases.csv'));

        DB::transaction(function () use ($rows): void {
            SetAlias::query()->upsert(
                $rows,
                ['source', 'pokesearch_set_id', 'source_group_id'],
                ['role', 'number_rule', 'method', 'unlimited_means', 'note'],
            );

            $keep = array_map(fn (array $r): string => $r['pokesearch_set_id'].'|'.$r['source_group_id'], $rows);
            SetAlias::query()->where('source', self::SOURCE)->get(['id', 'pokesearch_set_id', 'source_group_id'])
                ->reject(fn (SetAlias $a): bool => in_array($a->pokesearch_set_id.'|'.$a->source_group_id, $keep, true))
                ->each(fn (SetAlias $a): ?bool => $a->delete());
        });
    }

    /**
     * The CSV rows that can be stored, shaped as set_aliases columns (empty cells become null).
     *
     * @return list<array{source: string, pokesearch_set_id: string, source_group_id: int, role: string, number_rule: string|null, method: string, unlimited_means: string|null, note: string}>
     */
    public static function storableRows(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("set alias table {$path} is not readable");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '');
            if ($header !== self::HEADER) {
                throw new RuntimeException("set alias table {$path} has an unexpected header");
            }

            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count(self::HEADER)) {
                    throw new RuntimeException("set alias table {$path} line {$line} does not have seven cells");
                }
                /** @var array{pokesearch_set_id: string, source_group_id: string, role: string, number_rule: string, method: string, unlimited_means: string, note: string} $row */
                $row = array_combine(self::HEADER, array_map('strval', $cells));

                if ($row['source_group_id'] === '' || $row['role'] === 'none') {
                    if ($row['source_group_id'] !== '' || $row['role'] !== 'none') {
                        throw new RuntimeException("set alias table {$path} line {$line}: an empty group and role none go together");
                    }

                    continue;
                }

                if (! ctype_digit($row['source_group_id'])) {
                    throw new RuntimeException("set alias table {$path} line {$line}: the group is not a number");
                }

                $rows[] = [
                    'source' => self::SOURCE,
                    'pokesearch_set_id' => $row['pokesearch_set_id'],
                    'source_group_id' => (int) $row['source_group_id'],
                    'role' => $row['role'],
                    'number_rule' => $row['number_rule'] === '' ? null : $row['number_rule'],
                    'method' => $row['method'],
                    'unlimited_means' => $row['unlimited_means'] === '' ? null : $row['unlimited_means'],
                    'note' => $row['note'],
                ];
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}

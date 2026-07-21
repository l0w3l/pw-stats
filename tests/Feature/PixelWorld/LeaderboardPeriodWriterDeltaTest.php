<?php

use App\Data\PixelWorld\Leaderboard\CalendarPeriod;
use App\Data\PixelWorld\Stats\LeaderboardPlayerData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldLeaderboardPeriodEntry;
use App\Models\PixelWorldPlayer;
use App\Services\PixelWorld\Leaderboard\LeaderboardPeriodWriter;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('an unchanged collection does not write entries or player profiles', function () {
    $players = [deltaPlayer('one', 1), deltaPlayer('two', 2)];
    deltaWrite($players, total: 2);
    $entryIds = PixelWorldLeaderboardPeriodEntry::query()->orderBy('place')->pluck('id')->all();
    $playerUpdatedAt = PixelWorldPlayer::query()->pluck('updated_at', 'uuid')->map->toDateTimeString()->all();
    $queries = listenForDeltaQueries();

    deltaWrite($players, total: 2, minute: 1);

    expect(deltaTableWrites($queries, 'pixel_world_leaderboard_period_entries'))->toBe([])
        ->and(deltaTableWrites($queries, 'pixel_world_players'))->toBe([])
        ->and(PixelWorldLeaderboardPeriodEntry::query()->orderBy('place')->pluck('id')->all())->toBe($entryIds)
        ->and(PixelWorldPlayer::query()->pluck('updated_at', 'uuid')->map->toDateTimeString()->all())->toBe($playerUpdatedAt);
});

test('a sparse change updates only changed columns and rows', function () {
    deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2)], total: 2);
    $ids = PixelWorldLeaderboardPeriodEntry::query()->pluck('id', 'player_uuid');
    $queries = listenForDeltaQueries();

    deltaWrite([deltaPlayer('one', 1, points: 999), deltaPlayer('two', 2)], total: 2, minute: 1);

    $writes = deltaTableWrites($queries, 'pixel_world_leaderboard_period_entries');

    expect($writes)->toHaveCount(1)
        ->and(deltaUpdatesColumn($writes[0], 'points'))->toBeTrue()
        ->and(deltaUpdatesColumn($writes[0], 'nickname'))->toBeFalse()
        ->and(PixelWorldLeaderboardPeriodEntry::query()->pluck('id', 'player_uuid')->all())->toBe($ids->all())
        ->and(PixelWorldLeaderboardPeriodEntry::query()->where('player_uuid', 'one')->value('points'))->toBe(999);
});

test('rank swaps converge without deleting or reinserting existing entries', function () {
    deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2), deltaPlayer('three', 3)], total: 3);
    $ids = PixelWorldLeaderboardPeriodEntry::query()->pluck('id', 'player_uuid')->all();
    $queries = listenForDeltaQueries();

    deltaWrite([deltaPlayer('one', 2), deltaPlayer('two', 1), deltaPlayer('three', 3)], total: 3, minute: 1);

    $writes = deltaTableWrites($queries, 'pixel_world_leaderboard_period_entries');

    expect($writes)->toHaveCount(2)
        ->each->toStartWith('update')
        ->and(PixelWorldLeaderboardPeriodEntry::query()->pluck('id', 'player_uuid')->all())->toBe($ids)
        ->and(PixelWorldLeaderboardPeriodEntry::query()->orderBy('place')->pluck('player_uuid')->all())
        ->toBe(['two', 'one', 'three']);
});

test('removed and inserted players produce only the required delete and insert', function () {
    deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2)], total: 2);
    $retainedId = PixelWorldLeaderboardPeriodEntry::query()->where('player_uuid', 'one')->value('id');
    $queries = listenForDeltaQueries();

    deltaWrite([deltaPlayer('one', 1), deltaPlayer('new', 2)], total: 2, minute: 1);

    $writes = deltaTableWrites($queries, 'pixel_world_leaderboard_period_entries');

    expect($writes)->toHaveCount(2)
        ->and(implode(' ', $writes))->toContain('delete')
        ->and(implode(' ', $writes))->toContain('insert')
        ->and(PixelWorldLeaderboardPeriodEntry::query()->where('player_uuid', 'one')->value('id'))->toBe($retainedId)
        ->and(PixelWorldLeaderboardPeriodEntry::query()->orderBy('place')->pluck('player_uuid')->all())
        ->toBe(['one', 'new']);
});

test('partial collections remove missing rows and retain accurate diagnostics', function () {
    deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2), deltaPlayer('three', 3)], total: 3);

    $period = deltaWrite([deltaPlayer('one', 1), deltaPlayer('three', 3)], total: 3, minute: 1);

    expect($period->entries_count)->toBe(2)
        ->and($period->missing_places)->toBe(1)
        ->and($period->is_partial)->toBeTrue()
        ->and($period->entries()->orderBy('place')->pluck('place')->all())->toBe([1, 3]);
});

test('duplicate UUID and place observations reconcile with the last observation winning', function () {
    $period = deltaWrite([
        deltaPlayer('moving', 2, nickname: 'stale-rank'),
        deltaPlayer('displaced', 2),
        deltaPlayer('moving', 1, nickname: 'current-rank'),
    ], total: 2);

    expect($period->entries_count)->toBe(2)
        ->and($period->missing_places)->toBe(0)
        ->and($period->is_partial)->toBeFalse()
        ->and($period->entries()->orderBy('place')->pluck('player_uuid')->all())
        ->toBe(['moving', 'displaced'])
        ->and($period->entries()->where('player_uuid', 'moving')->sole()->nickname)->toBe('current-rank');
});

test('a duplicate place that displaces a player is reported as a partial period', function () {
    $period = deltaWrite([
        deltaPlayer('displaced', 1),
        deltaPlayer('winner', 1),
    ], total: 2);

    expect($period->entries_count)->toBe(1)
        ->and($period->missing_places)->toBe(1)
        ->and($period->is_partial)->toBeTrue()
        ->and($period->entries()->sole()->player_uuid)->toBe('winner');
});

test('a failed delta reconciliation rolls back metadata players and entries', function () {
    $period = deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2)], total: 2);
    $originalCollectedAt = $period->last_collected_at;
    DB::statement(<<<'SQL'
        CREATE TRIGGER reject_entry_delta
        BEFORE UPDATE ON pixel_world_leaderboard_period_entries
        BEGIN
            SELECT RAISE(ABORT, 'forced delta failure');
        END
        SQL);

    expect(fn () => deltaWrite([
        deltaPlayer('one', 2, nickname: 'must-roll-back'),
        deltaPlayer('two', 1),
    ], total: 2, minute: 1))->toThrow(QueryException::class);

    $period = PixelWorldLeaderboardPeriod::query()->sole();
    expect($period->last_collected_at->equalTo($originalCollectedAt))->toBeTrue()
        ->and($period->entries()->orderBy('place')->pluck('player_uuid')->all())->toBe(['one', 'two'])
        ->and(PixelWorldPlayer::query()->where('uuid', 'one')->value('nickname'))->toBe('one');
});

test('player profile diffs update only changed profiles and columns', function () {
    deltaWrite([deltaPlayer('one', 1), deltaPlayer('two', 2)], total: 2);
    $queries = listenForDeltaQueries();

    deltaWrite([
        deltaPlayer('one', 1, nickname: 'renamed'),
        deltaPlayer('two', 2),
    ], total: 2, minute: 1);

    $writes = deltaTableWrites($queries, 'pixel_world_players');

    expect($writes)->toHaveCount(2)
        ->and(deltaUpdatesColumn($writes[0], 'nickname'))->toBeTrue()
        ->and(deltaUpdatesColumn($writes[0], 'level'))->toBeFalse()
        ->and(deltaUpdatesColumn($writes[1], 'updated_at'))->toBeTrue()
        ->and(PixelWorldPlayer::query()->where('uuid', 'one')->value('nickname'))->toBe('renamed');
});

test('a realistic 1000 row reconciliation batches writes by changed rows rather than total rows', function () {
    $players = deltaFixture(1000);
    deltaWrite($players, total: 1000);
    $queries = listenForDeltaQueries();
    deltaWrite(deltaFixture(1000, changedPoints: 301), total: 1000, minute: 1);

    $writes = deltaTableWriteBatches($queries, 'pixel_world_leaderboard_period_entries');
    $changedRowsPerBatch = array_map(
        static fn (array $query): int => preg_match_all('/\bwhen\b/i', $query['sql']),
        $writes,
    );
    $bindingsPerBatch = array_map(static fn (array $query): int => count($query['bindings']), $writes);

    expect($writes)->toHaveCount(2)
        ->and(array_map(static fn (array $query): bool => deltaUpdatesColumn($query['sql'], 'points'), $writes))
        ->toBe([true, true])
        ->and($changedRowsPerBatch)->toBe([300, 1])
        ->and(array_sum($changedRowsPerBatch))->toBe(301)
        ->and(min($bindingsPerBatch))->toBeGreaterThan(0)
        ->and(array_sum($bindingsPerBatch))->toBeLessThan(3000)
        ->and(PixelWorldLeaderboardPeriodEntry::query()->where('points', '>=', 1_000_000)->count())->toBe(301)
        ->and(deltaTableWrites($queries, 'pixel_world_players'))->toBe([]);
});

function deltaPlayer(
    string $uuid,
    int $place,
    int $points = 100,
    ?string $nickname = null,
): LeaderboardPlayerData {
    return LeaderboardPlayerData::from([
        'uuid' => $uuid,
        'image_url' => "https://example.test/{$uuid}.jpg",
        'mask_image_url' => null,
        'level' => 10,
        'nickname' => $nickname ?? $uuid,
        'has_premium' => false,
        'place' => $place,
        'points' => $points,
    ]);
}

/** @return array<LeaderboardPlayerData> */
function deltaFixture(int $count, int $changedPoints = 0): array
{
    $players = [];

    for ($place = 1; $place <= $count; $place++) {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $place);
        $players[] = LeaderboardPlayerData::from([
            'uuid' => $uuid,
            'image_url' => "https://cdn.example.test/avatars/{$uuid}.webp",
            'mask_image_url' => $place % 4 === 0 ? "https://cdn.example.test/masks/{$uuid}.webp" : null,
            'level' => 10 + ($place % 90),
            'nickname' => sprintf('Player %04d', $place),
            'has_premium' => $place % 7 === 0,
            'place' => $place,
            'points' => 999_999 - $place + ($place <= $changedPoints ? 1_000_000 : 0),
        ]);
    }

    return $players;
}

/** @param array<LeaderboardPlayerData> $players */
function deltaWrite(array $players, int $total, int $minute = 0): PixelWorldLeaderboardPeriod
{
    $at = CarbonImmutable::parse('2026-07-21 12:00:00', 'UTC')->addMinutes($minute);

    return (new LeaderboardPeriodWriter)->replace(
        LeaderboardRange::Day,
        new CalendarPeriod($at->startOfDay(), $at->endOfDay()),
        deltaPlayer('viewer', 999999),
        $players,
        $total,
        $at,
    );
}

/** @return object{sql: array<int, string>, bindings: array<int, array<int, mixed>>} */
function listenForDeltaQueries(): object
{
    $queries = (object) ['sql' => [], 'bindings' => []];
    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries->sql[] = strtolower(preg_replace('/\s+/', ' ', trim($query->sql)) ?? $query->sql);
        $queries->bindings[] = $query->bindings;
    });

    return $queries;
}

/**
 * @param  object{sql: array<int, string>, bindings: array<int, array<int, mixed>>}  $queries
 * @return array<int, string>
 */
function deltaTableWrites(object $queries, string $table): array
{
    return array_values(array_filter(
        $queries->sql,
        static fn (string $sql): bool => str_contains($sql, $table)
            && preg_match('/^(insert|update|delete)\b/', $sql) === 1,
    ));
}

function deltaUpdatesColumn(string $sql, string $column): bool
{
    $identifier = preg_quote($column, '/');

    return preg_match('/\bset\s+["`\[]?'.$identifier.'["`\]]?\s*=/i', $sql) === 1;
}

/**
 * @param  object{sql: array<int, string>, bindings: array<int, array<int, mixed>>}  $queries
 * @return array<int, array{sql: string, bindings: array<int, mixed>}>
 */
function deltaTableWriteBatches(object $queries, string $table): array
{
    $batches = [];

    foreach ($queries->sql as $index => $sql) {
        if (str_contains($sql, $table) && preg_match('/^(insert|update|delete)\b/', $sql) === 1) {
            $batches[] = ['sql' => $sql, 'bindings' => $queries->bindings[$index]];
        }
    }

    return $batches;
}

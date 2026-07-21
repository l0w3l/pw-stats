<?php

use App\Data\PixelWorld\Leaderboard\CalendarPeriod;
use App\Data\PixelWorld\Stats\LeaderboardPlayerData;
use App\Data\PixelWorld\Stats\PlayersLeaderboardResponseData;
use App\Jobs\SyncPixelWorldPlayerLeaderboards;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Models\PixelWorldPlayer;
use App\Services\PixelWorld\Leaderboard\LeaderboardCalendarPeriodResolver;
use App\Services\PixelWorld\Leaderboard\LeaderboardClient;
use App\Services\PixelWorld\Leaderboard\LeaderboardCollector;
use App\Services\PixelWorld\Leaderboard\LeaderboardPeriodWriter;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('services.pixel-world.leaderboard_page_limit', 1);
    config()->set('services.pixel-world.reconciliation_passes', 3);
});

test('it collects a complete period in reverse page order without adding the viewer', function () {
    $client = new FakeLeaderboardClient;
    $progress = [];
    $collector = new LeaderboardCollector($client, new LeaderboardPeriodWriter);

    $period = $collector->collect(
        LeaderboardRange::Day,
        function (string $phase, int $processed, int $total) use (&$progress): void {
            $progress[] = [$phase, $processed, $total];
        },
    );

    expect($client->calls)->toBe([1, 2, 1])
        ->and($progress)->toBe([['scan', 0, 2], ['scan', 1, 2], ['scan', 2, 2]])
        ->and($period->entries_count)->toBe(2)
        ->and($period->entries()->where('player_uuid', 'viewer')->exists())->toBeFalse();

    $entry = $period->entries()->where('player_uuid', 'player-1')->sole();
    PixelWorldPlayer::query()->where('uuid', 'player-1')->update(['nickname' => 'renamed']);

    expect($entry->nickname)->toBe('player-1');
});

test('period writer deduplicates an authenticated viewer also present in leaderboard players', function () {
    $viewer = LeaderboardPlayerData::from([
        ...leaderboardCollectorPlayer('viewer', 100),
        'nickname' => 'viewer-profile',
    ]);
    $rankedViewer = LeaderboardPlayerData::from([
        ...leaderboardCollectorPlayer('viewer', 1),
        'nickname' => 'ranked-profile',
    ]);
    $at = CarbonImmutable::parse('2026-07-20 12:00:00', 'UTC');

    $period = (new LeaderboardPeriodWriter)->replace(
        LeaderboardRange::Day,
        new CalendarPeriod($at->startOfDay(), $at->endOfDay()),
        $viewer,
        [$rankedViewer],
        1,
        $at,
    );

    expect(PixelWorldPlayer::query()->count())->toBe(1)
        ->and(PixelWorldPlayer::query()->sole()->nickname)->toBe('ranked-profile')
        ->and($period->entries()->sole()->player_uuid)->toBe('viewer');
});

test('it tolerates total changes between pages', function () {
    $client = new FakeLeaderboardClient(changedTotalOnSecondPage: true);
    $collector = new LeaderboardCollector($client, new LeaderboardPeriodWriter);

    $period = $collector->collect(LeaderboardRange::Day);

    expect($period->total)->toBe(2)
        ->and($period->is_partial)->toBeFalse();
});

test('it repairs gaps caused by a player moving between pages', function () {
    $client = new FakeLeaderboardClient(repairMovement: true);
    $collector = new LeaderboardCollector($client, new LeaderboardPeriodWriter);

    $period = $collector->collect(LeaderboardRange::Day);

    expect($client->calls)->toBe([1, 2, 1, 1, 2])
        ->and($period->is_partial)->toBeFalse()
        ->and($period->entries()->orderBy('place')->pluck('player_uuid')->all())
        ->toBe(['player-1', 'player-2']);
});

test('a failed collection leaves the previous successful period untouched', function () {
    $successful = new LeaderboardCollector(new FakeLeaderboardClient, new LeaderboardPeriodWriter);
    $existing = $successful->collect(LeaderboardRange::Day);
    $client = new FakeLeaderboardClient(wrongPageOnSecondPage: true);
    $collector = new LeaderboardCollector($client, new LeaderboardPeriodWriter);

    expect(fn () => $collector->collect(LeaderboardRange::Day))->toThrow(RuntimeException::class);

    $period = PixelWorldLeaderboardPeriod::query()->sole();
    expect($period->id)->toBe($existing->id)
        ->and($period->entries()->count())->toBe(2);
});

test('it persists partial diagnostics after reconciliation is exhausted', function () {
    $client = new FakeLeaderboardClient(duplicatePlayers: true);
    $collector = new LeaderboardCollector($client, new LeaderboardPeriodWriter);

    $period = $collector->collect(LeaderboardRange::Day);

    expect($period->is_partial)->toBeTrue()
        ->and($period->entries_count)->toBe(1)
        ->and($period->missing_places)->toBe(1)
        ->and($period->entries()->sole()->place)->toBe(1);
});

test('repeated collection replaces one period and calendar rollover creates another', function () {
    $collector = new LeaderboardCollector(new FakeLeaderboardClient, new LeaderboardPeriodWriter);
    $this->travelTo('2026-07-20 12:00:00');
    $first = $collector->collect(LeaderboardRange::Day);
    $second = $collector->collect(LeaderboardRange::Day);

    expect($second->id)->toBe($first->id)
        ->and(PixelWorldLeaderboardPeriod::query()->count())->toBe(1);

    $this->travelTo('2026-07-21 00:00:01');
    $third = $collector->collect(LeaderboardRange::Day);

    expect($third->id)->not->toBe($first->id)
        ->and(PixelWorldLeaderboardPeriod::query()->count())->toBe(2);
});

test('it refuses to write data when the calendar period changes during collection', function () {
    $resolver = new class extends LeaderboardCalendarPeriodResolver
    {
        private int $calls = 0;

        public function resolve(LeaderboardRange $range, ?DateTimeInterface $at = null): CalendarPeriod
        {
            $date = ++$this->calls === 1 ? '2026-07-20 23:59:00' : '2026-07-21 00:01:00';

            return parent::resolve($range, CarbonImmutable::parse($date, 'UTC'));
        }
    };
    $collector = new LeaderboardCollector(new FakeLeaderboardClient, new LeaderboardPeriodWriter, $resolver);

    expect(fn () => $collector->collect(LeaderboardRange::Day))
        ->toThrow(RuntimeException::class, 'calendar period changed');

    expect(PixelWorldLeaderboardPeriod::query()->count())->toBe(0);
});

test('the queued job collects one range and is unique per range', function () {
    $period = new PixelWorldLeaderboardPeriod;
    $collector = Mockery::mock(LeaderboardCollector::class);
    $collector->shouldReceive('collect')
        ->once()
        ->with(LeaderboardRange::Week)
        ->andReturn($period);
    $job = new SyncPixelWorldPlayerLeaderboards(LeaderboardRange::Week);

    $job->handle($collector);

    expect($job->uniqueId())->toBe('week');
});

class FakeLeaderboardClient implements LeaderboardClient
{
    public array $calls = [];

    public function __construct(
        private readonly bool $changedTotalOnSecondPage = false,
        private readonly bool $duplicatePlayers = false,
        private readonly bool $repairMovement = false,
        private readonly bool $wrongPageOnSecondPage = false,
    ) {}

    public function page(LeaderboardRange $range, int $page, int $limit): PlayersLeaderboardResponseData
    {
        $this->calls[] = $page;
        $total = $this->changedTotalOnSecondPage && $page === 2 ? 3 : 2;
        $pageCalls = count(array_filter($this->calls, fn (int $calledPage): bool => $calledPage === $page));
        $playerUuid = "player-{$page}";

        if ($this->duplicatePlayers) {
            $playerUuid = 'same-player';
        } elseif ($this->repairMovement && (($page === 2 && $pageCalls === 1) || ($page === 1 && $pageCalls === 2))) {
            $playerUuid = 'moving-player';
        }

        return PlayersLeaderboardResponseData::from([
            'ok' => true,
            'data' => [
                'leaderboard' => [
                    'range' => $range->value,
                    'user' => leaderboardCollectorPlayer('viewer', 100),
                    'list' => [
                        'page' => $this->wrongPageOnSecondPage && $page === 2 ? 999 : $page,
                        'total' => $total,
                        'players' => [leaderboardCollectorPlayer($playerUuid, $page)],
                    ],
                ],
            ],
        ]);
    }
}

function leaderboardCollectorPlayer(string $uuid, int $place): array
{
    return [
        'uuid' => $uuid,
        'image_url' => 'https://example.test/avatar.jpg',
        'mask_image_url' => null,
        'level' => 42,
        'nickname' => $uuid,
        'has_premium' => false,
        'place' => $place,
        'points' => 100,
    ];
}

<?php

use App\Data\PixelWorld\Leaderboard\CalendarPeriod;
use App\Data\PixelWorld\Stats\LeaderboardPlayerData;
use App\Models\PixelWorldLeaderboardPeriod;
use App\Services\PixelWorld\Leaderboard\LeaderboardPeriodWriter;
use App\Services\PixelWorld\Stats\LeaderboardRange;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

uses(DatabaseMigrations::class);

test('concurrent first writes resolve to one period row on PostgreSQL', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('This concurrency regression test requires a PostgreSQL test database.');
    }

    if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
        $this->markTestSkipped('This concurrency regression test requires pcntl and Unix sockets.');
    }

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    if ($sockets === false) {
        $this->fail('Unable to create the synchronization socket pair.');
    }

    DB::purge();
    $processId = pcntl_fork();

    if ($processId === -1) {
        $this->fail('Unable to fork the concurrent writer process.');
    }

    if ($processId === 0) {
        fclose($sockets[0]);
        DB::purge();
        fread($sockets[1], 1);

        try {
            $period = writeConcurrentLeaderboardPeriod('child-player');
            fwrite($sockets[1], json_encode(['id' => $period->getKey()], JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(0);
        } catch (Throwable $throwable) {
            fwrite($sockets[1], json_encode(['error' => $throwable->getMessage()], JSON_THROW_ON_ERROR));
            fclose($sockets[1]);
            exit(1);
        }
    }

    fclose($sockets[1]);
    fwrite($sockets[0], '1');
    $period = writeConcurrentLeaderboardPeriod('parent-player');
    $childResult = stream_get_contents($sockets[0]);
    fclose($sockets[0]);
    pcntl_waitpid($processId, $status);

    /** @var array{id?: int, error?: string} $decodedChildResult */
    $decodedChildResult = json_decode($childResult, true, flags: JSON_THROW_ON_ERROR);

    expect(pcntl_wexitstatus($status))->toBe(0)
        ->and($decodedChildResult)->not->toHaveKey('error')
        ->and($decodedChildResult['id'])->toBe($period->getKey())
        ->and(PixelWorldLeaderboardPeriod::query()->count())->toBe(1);
});

function writeConcurrentLeaderboardPeriod(string $playerUuid): PixelWorldLeaderboardPeriod
{
    $at = CarbonImmutable::parse('2026-07-20 12:00:00', 'UTC');
    $isChild = $playerUuid === 'child-player';
    $playerUuid = $isChild
        ? '00000000-0000-4000-8000-000000000001'
        : '00000000-0000-4000-8000-000000000002';
    $viewerUuid = $isChild
        ? '00000000-0000-4000-8000-000000000101'
        : '00000000-0000-4000-8000-000000000102';
    $player = static fn (string $uuid, int $place): LeaderboardPlayerData => LeaderboardPlayerData::from([
        'uuid' => $uuid,
        'image_url' => 'https://example.test/avatar.jpg',
        'mask_image_url' => null,
        'level' => 42,
        'nickname' => $uuid,
        'has_premium' => false,
        'place' => $place,
        'points' => 100,
    ]);

    return (new LeaderboardPeriodWriter)->replace(
        LeaderboardRange::Day,
        new CalendarPeriod($at->startOfDay(), $at->endOfDay()),
        $player($viewerUuid, 100),
        [$player($playerUuid, 1)],
        1,
        $at,
    );
}

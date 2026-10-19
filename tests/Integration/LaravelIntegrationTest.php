<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Config;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Laravel\LakeConnection;
use TiDBCloud\Lake\Laravel\LakeServiceProvider;

/**
 * Live tests of the Laravel driver: Eloquent and the query builder against a
 * real Lake server. Gated by LAKE_DSN like {@see LakeIntegrationTest}.
 *
 * @group integration
 */
final class LaravelIntegrationTest extends TestCase
{
    private LakeConnection $db;
    private string $table;
    private string $sequence;

    protected function setUp(): void
    {
        $dsn = getenv('LAKE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('LAKE_DSN is not set');
        }

        $suffix = bin2hex(random_bytes(4));
        $this->table = 'lake_php_it_logs_' . $suffix;
        $this->sequence = 'lake_php_it_seq_' . $suffix;

        $capsule = new Capsule();
        LakeServiceProvider::registerDriver($capsule->getContainer());
        $capsule->addConnection([
            'driver' => 'lake',
            'dsn' => $dsn,
            // Laravel's ConnectionFactory always reads "database".
            'database' => Config::fromDsn($dsn)->database,
            'sequences' => [$this->table => $this->sequence],
        ], 'lake');
        $capsule->getDatabaseManager()->setDefaultConnection('lake');
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->db = $capsule->getConnection('lake');
        $this->db->statement(
            "CREATE TABLE {$this->table} (id BIGINT, user_id BIGINT, link STRING NULL, flag BOOLEAN NULL,"
            . ' score DOUBLE NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL)',
        );
        AttributionLog::$tableName = $this->table;
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->statement("DROP TABLE IF EXISTS {$this->table}");
            $this->db->statement("DROP SEQUENCE IF EXISTS {$this->sequence}");
            $this->db->disconnect();
        }
        Carbon::setTestNow();
    }

    public function testCustomerQuery(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 42, 'link' => 'a', 'created_at' => '2025-12-31 10:00:00']);
        AttributionLog::create(['id' => 2, 'user_id' => 42, 'link' => 'b', 'created_at' => '2026-02-01 08:30:00']);

        $log = AttributionLog::query()->whereKey(1)->where('created_at', '<', '2026-01-01')->first();

        self::assertNotNull($log);
        self::assertSame(1, $log->id);
        self::assertSame(42, $log->user_id);
        self::assertSame('a', $log->link);
        self::assertSame('2025-12-31 10:00:00', $log->getRawOriginal('created_at'));
        self::assertSame('2025-12-31 10:00:00', $log->created_at->format('Y-m-d H:i:s'));

        self::assertNull(AttributionLog::query()->whereKey(2)->where('created_at', '<', '2026-01-01')->first());
    }

    public function testEloquentCrud(): void
    {
        Carbon::setTestNow('2026-03-04 05:06:07');

        $log = AttributionLog::create(['id' => 10, 'user_id' => 1, 'link' => 'x', 'score' => 1.5]);
        self::assertTrue($log->exists);
        self::assertSame('2026-03-04 05:06:07', AttributionLog::find(10)->getRawOriginal('created_at'));

        // String binding against a BIGINT column, as with request input.
        $found = AttributionLog::where('id', '10')->firstOrFail();
        $found->link = 'y';
        $found->save();
        $found->increment('user_id', 5);

        $fresh = $found->fresh();
        self::assertSame('y', $fresh->link);
        self::assertSame(6, $fresh->user_id);
        self::assertSame(1.5, $fresh->score);

        $fresh->delete();
        self::assertNull(AttributionLog::find(10));

        AttributionLog::create(['id' => 11, 'user_id' => 1]);
        AttributionLog::create(['id' => 12, 'user_id' => 1]);
        self::assertSame(2, AttributionLog::destroy([11, 12]));
        self::assertSame(0, AttributionLog::count());
    }

    public function testQueryBuilderReads(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['id' => $i, 'user_id' => $i % 3, 'link' => "l{$i}", 'score' => $i / 2, 'created_at' => '2025-06-15 12:00:00'];
        }
        self::assertTrue($this->db->table($this->table)->insert($rows));

        $query = fn () => $this->db->table($this->table);
        self::assertSame(25, $query()->count());
        self::assertSame(25, $query()->max('id'));
        self::assertEquals(162.5, $query()->sum('score'));
        self::assertTrue($query()->where('link', 'l7')->exists());
        self::assertFalse($query()->where('link', 'nope')->exists());
        self::assertSame(['l1', 'l2', 'l3'], $query()->whereIn('id', [1, 2, 3])->orderBy('id')->pluck('link')->all());
        self::assertSame([21, 22], $query()->orderBy('id')->offset(20)->limit(2)->pluck('id')->all());
        self::assertSame(9, $query()->where('user_id', 1)->where(fn ($q) => $q->where('id', '<', 100)->orWhereNull('link'))->count());

        $page = $query()->orderBy('id')->paginate(10, ['*'], 'page', 3);
        self::assertSame(25, $page->total());
        self::assertSame([21, 22, 23, 24, 25], array_column($page->items(), 'id'));

        $ids = [];
        foreach ($query()->where('id', '<=', 5)->orderBy('id')->cursor() as $row) {
            $ids[] = $row->id;
        }
        self::assertSame([1, 2, 3, 4, 5], $ids);

        $chunked = [];
        $query()->chunkById(10, function ($chunk) use (&$chunked): void {
            $chunked[] = count($chunk);
        });
        self::assertSame([10, 10, 5], $chunked);

        $grouped = $query()->select('user_id', $this->db->raw('count(*) as c'))->groupBy('user_id')->orderBy('user_id')->get();
        self::assertSame([8, 9, 8], $grouped->pluck('c')->all());
    }

    public function testDateBasedWheres(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 1, 'created_at' => '2025-06-15 12:34:56']);
        AttributionLog::create(['id' => 2, 'user_id' => 1, 'created_at' => '2024-01-02 03:04:05']);

        self::assertSame([1], AttributionLog::whereDate('created_at', '2025-06-15')->pluck('id')->all());
        self::assertSame([1], AttributionLog::whereYear('created_at', 2025)->pluck('id')->all());
        self::assertSame([2], AttributionLog::whereMonth('created_at', 1)->pluck('id')->all());
        self::assertSame([2], AttributionLog::whereDay('created_at', 2)->pluck('id')->all());
        self::assertSame([1], AttributionLog::whereTime('created_at', '>', '12:00:00')->pluck('id')->all());
        self::assertSame([1], AttributionLog::where('created_at', '>=', Carbon::parse('2025-01-01'))->pluck('id')->all());
    }

    public function testBooleanColumn(): void
    {
        AttributionLog::create(['id' => 1, 'user_id' => 1, 'flag' => true]);
        AttributionLog::create(['id' => 2, 'user_id' => 1, 'flag' => false]);

        self::assertSame([1], AttributionLog::where('flag', true)->pluck('id')->all());
        self::assertTrue(AttributionLog::find(1)->flag);
    }

    public function testTransactions(): void
    {
        $this->db->transaction(function (): void {
            AttributionLog::create(['id' => 1, 'user_id' => 1]);
            $this->db->transaction(fn () => AttributionLog::create(['id' => 2, 'user_id' => 1]));
        });
        self::assertSame(2, AttributionLog::count());

        try {
            $this->db->transaction(function (): void {
                AttributionLog::create(['id' => 3, 'user_id' => 1]);
                throw new \DomainException('rollback');
            });
        } catch (\DomainException) {
        }
        self::assertSame(2, AttributionLog::count());
        self::assertSame(0, $this->db->transactionLevel());
    }

    public function testUpsertUsesMerge(): void
    {
        $this->db->table($this->table)->insert([
            ['id' => 1, 'user_id' => 10, 'link' => 'old'],
            ['id' => 2, 'user_id' => 20, 'link' => 'keep'],
        ]);

        $affected = $this->db->table($this->table)->upsert(
            [
                ['id' => 1, 'user_id' => 99, 'link' => 'new'],
                ['id' => 3, 'user_id' => 30, 'link' => 'inserted'],
            ],
            ['id'],
            ['link'],
        );

        self::assertSame(2, $affected);
        $rows = $this->db->table($this->table)->orderBy('id')->get(['id', 'user_id', 'link'])->map(fn ($r) => (array) $r)->all();
        self::assertSame([
            ['id' => 1, 'user_id' => 10, 'link' => 'new'],
            ['id' => 2, 'user_id' => 20, 'link' => 'keep'],
            ['id' => 3, 'user_id' => 30, 'link' => 'inserted'],
        ], $rows);
    }

    public function testInsertGetIdFromSequence(): void
    {
        $this->db->statement("CREATE SEQUENCE {$this->sequence}");

        $first = AttributionLog::create(['user_id' => 1]);
        $second = AttributionLog::create(['user_id' => 2]);

        self::assertIsInt($first->id);
        self::assertGreaterThan($first->id, $second->id);
        self::assertSame(2, AttributionLog::find($second->id)->user_id);
    }

    public function testInsertGetIdWithoutKeyFailsBeforeWriting(): void
    {
        $this->expectException(LakeException::class);

        try {
            WithoutSequenceLog::create(['user_id' => 1]);
        } finally {
            self::assertSame(0, AttributionLog::count());
        }
    }

    public function testRawStatementsWithNamedBindings(): void
    {
        $this->db->insert("INSERT INTO {$this->table} (id, user_id, link) VALUES (:id, :user, :link)", [
            'id' => 1,
            'user' => 5,
            'link' => "it's",
        ]);

        $row = $this->db->selectOne("SELECT id, link FROM {$this->table} WHERE id = :id", ['id' => 1]);
        self::assertSame("it's", $row->link);
        self::assertSame(1, $this->db->update("UPDATE {$this->table} SET link = ? WHERE id = ?", ['z', 1]));
        self::assertSame(1, $this->db->delete("DELETE FROM {$this->table} WHERE id = ?", [1]));
    }
}

class AttributionLog extends Model
{
    public static string $tableName = '';

    protected $guarded = [];

    public function getTable()
    {
        return static::$tableName;
    }
}

/**
 * Same table under a name that has no sequence mapping.
 */
class WithoutSequenceLog extends AttributionLog
{
    public function getTable()
    {
        return parent::getTable() . '_no_sequence';
    }
}

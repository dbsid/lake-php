<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Laravel;

use Carbon\Carbon;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Exception\LakeException;
use TiDBCloud\Lake\Laravel\LakeConnection;
use TiDBCloud\Lake\Laravel\LakeQueryBuilder;
use TiDBCloud\Lake\Laravel\LakeQueryGrammar;
use TiDBCloud\Lake\Tests\Laravel\Fixtures\UserLinkAttributionLog;
use TiDBCloud\Lake\Tests\Support\FakeLake;

final class LakeConnectionTest extends TestCase
{
    private const LOG_COLUMNS = [
        'id' => 'Int64',
        'user_id' => 'Int64',
        'link' => 'String',
        'created_at' => 'Timestamp',
        'updated_at' => 'Timestamp',
    ];

    private FakeLake $lake;
    private LakeConnection $connection;

    protected function setUp(): void
    {
        $this->lake = new FakeLake();
        $this->connection = $this->connect();
        Carbon::setTestNow('2026-01-02 03:04:05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Model::unsetConnectionResolver();
    }

    private function connect(array $config = []): LakeConnection
    {
        $connection = new LakeConnection($this->lake->pdo(), 'db', '', $config + ['driver' => 'lake', 'name' => 'lake']);

        $resolver = new ConnectionResolver(['lake' => $connection]);
        $resolver->setDefaultConnection('lake');
        Model::setConnectionResolver($resolver);

        return $connection;
    }

    public function testWiring(): void
    {
        self::assertSame('lake', $this->connection->getDriverName());
        self::assertInstanceOf(LakeQueryGrammar::class, $this->connection->getQueryGrammar());
        self::assertInstanceOf(LakeQueryBuilder::class, $this->connection->table('t'));
        self::assertSame('lake', $this->connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testCustomerEloquentQuery(): void
    {
        $this->lake->result(self::LOG_COLUMNS, [
            ['1', '42', 'https://example.com/a', '2025-12-31 10:00:00.000000', '2025-12-31 10:00:00.000000'],
        ]);

        $log = UserLinkAttributionLog::query()
            ->whereKey(1)
            ->where('created_at', '<', '2026-01-01')
            ->first();

        self::assertSame(
            ["select * from `user_link_attribution_logs` where `user_link_attribution_logs`.`id` = 1 and `created_at` < '2026-01-01' limit 1"],
            $this->lake->sqls(),
        );
        self::assertInstanceOf(UserLinkAttributionLog::class, $log);
        self::assertTrue($log->exists);
        self::assertSame(1, $log->id);
        self::assertSame(42, $log->user_id);
        self::assertSame('2025-12-31 10:00:00', $log->getRawOriginal('created_at'));
        self::assertInstanceOf(Carbon::class, $log->created_at);
        self::assertSame('2025-12-31 10:00:00', $log->created_at->format('Y-m-d H:i:s'));
    }

    public function testQueryBuilderReads(): void
    {
        $this->lake->result(['aggregate' => 'UInt64'], [['3']]);
        $this->lake->result(['link' => 'String'], [['a'], ['b']]);
        $this->lake->result(['exists' => 'Boolean'], [['true']]);

        self::assertSame(3, $this->connection->table('user_link_attribution_logs')->where('user_id', 42)->count());
        self::assertSame(
            ['a', 'b'],
            $this->connection->table('user_link_attribution_logs')->whereIn('id', [1, 2])->orderBy('id')->pluck('link')->all(),
        );
        self::assertTrue($this->connection->table('user_link_attribution_logs')->where('link', 'like', 'https://%')->exists());

        self::assertSame([
            'select count(*) as aggregate from `user_link_attribution_logs` where `user_id` = 42',
            'select `link` from `user_link_attribution_logs` where `id` in (1, 2) order by `id` asc',
            "select exists(select * from `user_link_attribution_logs` where `link` like 'https://%') as `exists`",
        ], $this->lake->sqls());
    }

    public function testCursorAndRawNamedBindings(): void
    {
        $this->lake->result(['id' => 'Int64'], [['1'], ['2']]);
        $this->lake->result(['id' => 'Int64'], [['5']]);

        $ids = [];
        foreach ($this->connection->cursor('select id from t') as $row) {
            $ids[] = $row->id;
        }
        self::assertSame([1, 2], $ids);

        self::assertSame(5, $this->connection->selectOne('select id from t where id = :id', ['id' => 5])->id);
        self::assertSame(['select id from t', 'select id from t where id = 5'], $this->lake->sqls());
    }

    public function testEloquentWritesWithExplicitKey(): void
    {
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->dml(['number of rows updated' => 1]);
        $this->lake->dml(['number of rows deleted' => 1]);

        $log = UserLinkAttributionLog::create(['id' => 7, 'user_id' => 42, 'link' => 'a']);
        self::assertSame(7, $log->id);

        $log->update(['link' => 'b']);
        $log->delete();

        self::assertSame([
            "insert into `user_link_attribution_logs` (`id`, `user_id`, `link`, `updated_at`, `created_at`) values (7, 42, 'a', '2026-01-02 03:04:05', '2026-01-02 03:04:05')",
            "update `user_link_attribution_logs` set `link` = 'b', `user_link_attribution_logs`.`updated_at` = '2026-01-02 03:04:05' where `id` = 7",
            'delete from `user_link_attribution_logs` where `id` = 7',
        ], $this->lake->sqls());
    }

    public function testBuilderWritesReturnAffectedRows(): void
    {
        $this->lake->dml(['number of rows inserted' => 2]);
        $this->lake->dml(['number of rows updated' => 3]);
        $this->lake->dml(['number of rows deleted' => 2]);

        $table = $this->connection->table('t');
        self::assertTrue($table->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]));
        self::assertSame(3, $this->connection->table('t')->where('id', '>', 0)->update(['name' => 'c']));
        self::assertSame(2, $this->connection->table('t')->where('id', '>', 5)->delete());

        self::assertSame([
            "insert into `t` (`id`, `name`) values (1, 'a'), (2, 'b')",
            "update `t` set `name` = 'c' where `id` > 0",
            'delete from `t` where `id` > 5',
        ], $this->lake->sqls());
    }

    public function testInsertGetIdUsesConfiguredSequence(): void
    {
        $this->connection = $this->connect(['sequences' => ['user_link_attribution_logs' => 'log_id_seq']]);
        $this->lake->result(['id' => 'UInt64'], [['100']]);
        $this->lake->dml(['number of rows inserted' => 1]);

        $log = UserLinkAttributionLog::create(['user_id' => 42, 'link' => 'a']);

        self::assertSame(100, $log->id);
        self::assertSame([
            'select nextval(log_id_seq) as id',
            "insert into `user_link_attribution_logs` (`user_id`, `link`, `updated_at`, `created_at`, `id`) values (42, 'a', '2026-01-02 03:04:05', '2026-01-02 03:04:05', 100)",
        ], $this->lake->sqls());
    }

    public function testInsertGetIdWithoutKeyOrSequenceWritesNothing(): void
    {
        try {
            UserLinkAttributionLog::create(['user_id' => 42]);
            self::fail('expected LakeException');
        } catch (LakeException $e) {
            self::assertStringContainsString('user_link_attribution_logs', $e->getMessage());
            self::assertStringContainsString('sequences', $e->getMessage());
        }

        self::assertSame([], $this->lake->http->requests);
    }

    public function testInvalidSequenceNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->connection->nextSequenceValue('seq; drop table t');
    }

    public function testUpsertCompilesToMerge(): void
    {
        $this->lake->dml(['number of rows inserted' => 1, 'number of rows updated' => 1]);

        $affected = $this->connection->table('t')->upsert(
            [['id' => 1, 'name' => 'a', 'n' => 1], ['id' => 2, 'name' => 'b', 'n' => 2]],
            ['id'],
            ['name'],
        );

        self::assertSame(2, $affected);
        self::assertSame([
            'merge into `t` using (select 1 as `id`, 1 as `n`, \'a\' as `name` union all select 2, 2, \'b\') as `laravel_upsert_source`'
            . ' on `t`.`id` = `laravel_upsert_source`.`id`'
            . ' when matched then update set `name` = `laravel_upsert_source`.`name`'
            . ' when not matched then insert (`id`, `n`, `name`)'
            . ' values (`laravel_upsert_source`.`id`, `laravel_upsert_source`.`n`, `laravel_upsert_source`.`name`)',
        ], $this->lake->sqls());
    }

    public function testUpsertWithLiteralUpdateValues(): void
    {
        $this->lake->dml(['number of rows inserted' => 0, 'number of rows updated' => 1]);

        $this->connection->table('t')->upsert([['id' => 1, 'n' => 1]], 'id', ['n' => 9]);

        self::assertStringContainsString(
            'when matched then update set `n` = 9 when not matched',
            $this->lake->sqls()[0],
        );
    }

    public function testDateBasedWheres(): void
    {
        $query = $this->connection->table('t')
            ->whereDate('created_at', '2025-06-15')
            ->whereTime('created_at', '>', '12:00:00');

        self::assertSame(
            "select * from `t` where date(`created_at`) = ? and date_format(`created_at`, '%H:%M:%S') > ?",
            $query->toSql(),
        );
    }

    public function testInsertOrIgnoreIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->connection->table('t')->insertOrIgnore(['id' => 1]);
    }

    public function testNestedTransactionsUseNoSavepoints(): void
    {
        $this->lake->ok();
        $this->lake->dml(['number of rows inserted' => 1]);
        $this->lake->ok();

        $this->connection->transaction(function (LakeConnection $connection): void {
            $connection->transaction(function (LakeConnection $connection): void {
                $connection->insert('insert into t values (?)', [1]);
            });
        });

        self::assertSame(['BEGIN', 'insert into t values (1)', 'COMMIT'], $this->lake->sqls());
    }

    public function testTransactionRollsBackOnException(): void
    {
        $this->lake->ok();
        $this->lake->ok();

        try {
            $this->connection->transaction(function (): void {
                throw new \DomainException('boom');
            });
            self::fail('expected DomainException');
        } catch (\DomainException) {
        }

        self::assertSame(['BEGIN', 'ROLLBACK'], $this->lake->sqls());
        self::assertSame(0, $this->connection->transactionLevel());
    }

    public function testQueryErrorsBecomeLaravelQueryExceptions(): void
    {
        $this->lake->error(1025, 'Unknown table');

        try {
            $this->connection->table('missing')->get();
            self::fail('expected QueryException');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame(1025, $e->getCode());
            self::assertStringContainsString('Unknown table', $e->getMessage());
            self::assertStringContainsString('select * from `missing`', $e->getMessage());
        }
    }

    public function testSchemaBuilderIsNotSupported(): void
    {
        $this->expectException(\LogicException::class);
        $this->connection->getSchemaBuilder();
    }
}

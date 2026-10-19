<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Tests\Pdo;

use PHPUnit\Framework\TestCase;
use TiDBCloud\Lake\Client;
use TiDBCloud\Lake\Exception\QueryException;
use TiDBCloud\Lake\Pdo\LakePdo;
use TiDBCloud\Lake\Pdo\LakePdoStatement;
use TiDBCloud\Lake\Tests\Support\FakeLake;

final class LakePdoTest extends TestCase
{
    private FakeLake $lake;

    protected function setUp(): void
    {
        $this->lake = new FakeLake();
    }

    public function testIsAPdo(): void
    {
        $pdo = $this->lake->pdo();

        self::assertInstanceOf(\PDO::class, $pdo);
        self::assertInstanceOf(\PDOStatement::class, $pdo->prepare('SELECT 1'));
        self::assertSame('lake', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        self::assertSame(Client::VERSION, $pdo->getAttribute(\PDO::ATTR_CLIENT_VERSION));
        self::assertSame(\PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(\PDO::ATTR_ERRMODE));
    }

    public function testBoundValuesAreInterpolatedAsTypedLiterals(): void
    {
        $this->lake->result(['id' => 'Int64']);
        $pdo = $this->lake->pdo();

        // The way Laravel's Connection::bindValues() binds.
        $statement = $pdo->prepare('select * from t where id = ? and name = ? and price > ? and note = ?');
        $statement->bindValue(1, 5, \PDO::PARAM_INT);
        $statement->bindValue(2, "it's", \PDO::PARAM_STR);
        $statement->bindValue(3, 1.5, \PDO::PARAM_STR);
        $statement->bindValue(4, null, \PDO::PARAM_STR);
        self::assertTrue($statement->execute());

        self::assertSame(
            ["select * from t where id = 5 and name = 'it\\'s' and price > 1.5 and note = NULL"],
            $this->lake->sqls(),
        );
    }

    public function testNamedParamsAndExecuteArguments(): void
    {
        $this->lake->result(['a' => 'Int64']);
        $this->lake->result(['a' => 'String']);
        $pdo = $this->lake->pdo();

        $statement = $pdo->prepare('select :a as a');
        $statement->bindValue(':a', '7', \PDO::PARAM_INT);
        $statement->execute();

        $pdo->prepare('select ? as a')->execute(['x']);

        $value = 'ignored';
        $byRef = $pdo->prepare('select ? as a');
        $byRef->bindParam(1, $value);
        $value = 'late';
        $this->lake->result(['a' => 'String']);
        $byRef->execute();

        self::assertSame(['select 7 as a', "select 'x' as a", "select 'late' as a"], $this->lake->sqls());
    }

    public function testMixingNamedAndPositionalParamsFails(): void
    {
        $statement = $this->lake->pdo()->prepare('select ?, :b');
        $statement->bindValue(1, 1);
        $statement->bindValue('b', 2);

        $this->expectException(\PDOException::class);
        $statement->execute();
    }

    public function testFetchModesAndPdoStyleValues(): void
    {
        $columns = ['id' => 'Int64', 'name' => 'String', 'd' => 'Date', 'ts' => 'Timestamp', 'n' => 'Nullable(Int32)'];
        $rows = [
            ['1', 'alice', '2024-05-01', '2024-05-01 10:20:30.000000', null],
            ['2', 'bob', '2024-05-02', '2024-05-02 08:00:00.123456', '3'],
        ];
        $pdo = $this->lake->pdo();

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1');
        self::assertSame(2, $statement->rowCount());
        self::assertSame(5, $statement->columnCount());
        self::assertSame('Timestamp', $statement->getColumnMeta(3)['native_type']);
        self::assertSame(
            ['id' => 1, 0 => 1, 'name' => 'alice', 1 => 'alice', 'd' => '2024-05-01', 2 => '2024-05-01',
                'ts' => '2024-05-01 10:20:30', 3 => '2024-05-01 10:20:30', 'n' => null, 4 => null],
            $statement->fetch(),
        );
        self::assertSame([2, 'bob', '2024-05-02', '2024-05-02 08:00:00.123456', 3], $statement->fetch(\PDO::FETCH_NUM));
        self::assertFalse($statement->fetch());

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1', \PDO::FETCH_OBJ);
        $all = $statement->fetchAll();
        self::assertEquals((object) ['id' => 1, 'name' => 'alice', 'd' => '2024-05-01', 'ts' => '2024-05-01 10:20:30', 'n' => null], $all[0]);
        self::assertCount(2, $all);

        $this->lake->result($columns, $rows);
        self::assertSame(['alice', 'bob'], $pdo->query('select 1')->fetchAll(\PDO::FETCH_COLUMN, 1));

        $this->lake->result($columns, $rows);
        $statement = $pdo->query('select 1');
        self::assertSame(1, $statement->fetchColumn());
        self::assertSame('bob', $statement->fetchObject()->name);

        $this->lake->result($columns, $rows);
        $names = [];
        foreach ($pdo->query('select 1', \PDO::FETCH_ASSOC) as $row) {
            $names[] = $row['name'];
        }
        self::assertSame(['alice', 'bob'], $names);
    }

    public function testStringifyFetches(): void
    {
        $this->lake->result(['id' => 'Int64', 'ok' => 'Boolean', 'f' => 'Float64'], [['1', 'true', '1.5']]);
        $pdo = $this->lake->pdo([\PDO::ATTR_STRINGIFY_FETCHES => true]);

        self::assertSame(['1', '1', '1.5'], $pdo->query('select 1')->fetch(\PDO::FETCH_NUM));
    }

    public function testUnsupportedFetchModeFails(): void
    {
        $this->expectException(\PDOException::class);
        $this->lake->pdo()->prepare('select 1')->setFetchMode(\PDO::FETCH_KEY_PAIR);
    }

    public function testDmlReportsAffectedRowsWithoutResultSet(): void
    {
        $this->lake->dml(['number of rows updated' => 3]);
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        $statement = $pdo->prepare('update t set a = ?');
        $statement->execute([1]);
        self::assertSame(3, $statement->rowCount());
        self::assertSame(0, $statement->columnCount());
        self::assertSame([], $statement->fetchAll());

        self::assertSame(0, $pdo->exec('create table t (a int)'));
        self::assertSame(['update t set a = 1', 'create table t (a int)'], $this->lake->sqls());
    }

    public function testTransactions(): void
    {
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $this->lake->ok();
        $pdo = $this->lake->pdo();

        self::assertFalse($pdo->inTransaction());
        self::assertTrue($pdo->beginTransaction());
        self::assertTrue($pdo->inTransaction());
        try {
            $pdo->beginTransaction();
            self::fail('expected PDOException');
        } catch (\PDOException) {
        }
        self::assertTrue($pdo->commit());
        self::assertFalse($pdo->inTransaction());

        $pdo->beginTransaction();
        $pdo->rollBack();
        self::assertFalse($pdo->inTransaction());

        self::assertSame(['BEGIN', 'COMMIT', 'BEGIN', 'ROLLBACK'], $this->lake->sqls());

        $this->expectException(\PDOException::class);
        $pdo->commit();
    }

    public function testQuoteAndServerVersion(): void
    {
        $this->lake->result(['version' => 'String'], [['v1.2.3']]);
        $pdo = $this->lake->pdo();

        self::assertSame("'it\\'s'", $pdo->quote("it's"));
        self::assertSame('v1.2.3', $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
        self::assertSame('v1.2.3', $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION));
        self::assertCount(1, $this->lake->sqls());
    }

    public function testErrorsAreThrownAsPdoException(): void
    {
        $this->lake->error(1025, 'Unknown table');

        try {
            $this->lake->pdo()->prepare('select * from missing')->execute();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame(1025, $e->getCode());
            self::assertSame('HY000', $e->errorInfo[0]);
            self::assertInstanceOf(QueryException::class, $e->getPrevious());
            self::assertStringContainsString('Unknown table', $e->getMessage());
        }
    }

    public function testLastInsertIdIsNotSupported(): void
    {
        try {
            $this->lake->pdo()->lastInsertId();
            self::fail('expected PDOException');
        } catch (\PDOException $e) {
            self::assertSame('IM001', $e->errorInfo[0]);
        }
    }

    public function testStatementKeepsItsSql(): void
    {
        $statement = $this->lake->pdo()->prepare('select 42');

        self::assertInstanceOf(LakePdoStatement::class, $statement);
        self::assertSame('select 42', $statement->sql());
    }

    public function testFromDsnWrapsConnectionErrors(): void
    {
        $this->expectException(\PDOException::class);
        LakePdo::fromDsn('not a dsn');
    }
}

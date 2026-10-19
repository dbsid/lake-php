<?php

declare(strict_types=1);

namespace TiDBCloud\Lake\Laravel;

use Illuminate\Database\Connection;
use TiDBCloud\Lake\Pdo\LakePdo;

/**
 * Laravel database connection for TiDB Cloud Lake (driver name "lake").
 *
 * Queries run through {@see LakePdo}, so Laravel's own select / cursor /
 * statement / transaction code paths are used unchanged. The query grammar
 * is MySQL's (Lake accepts backtick identifiers) with Lake-specific
 * overrides, see {@see LakeQueryGrammar}.
 *
 * Supported on Laravel 9 through 12.
 */
class LakeConnection extends Connection
{
    public function getDriverTitle()
    {
        return 'TiDB Cloud Lake';
    }

    /**
     * @return LakeQueryBuilder
     */
    public function query()
    {
        return new LakeQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    /**
     * The schema builder (migrations, Schema facade) is not supported:
     * Lake tables are managed with plain SQL.
     */
    public function getSchemaBuilder()
    {
        throw new \LogicException(
            'The lake database driver does not support the schema builder; manage Lake tables with SQL statements.',
        );
    }

    /**
     * Returns the next value of a Lake sequence (used by insertGetId()).
     */
    public function nextSequenceValue(string $sequence): int|string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $sequence) !== 1) {
            throw new \InvalidArgumentException('Invalid Lake sequence name: ' . $sequence);
        }

        $row = (array) $this->selectOne("select nextval({$sequence}) as id", [], false);

        return $row['id'];
    }

    /**
     * Logs the Lake session(s) out (best-effort) before dropping them.
     */
    public function disconnect()
    {
        if ($this->pdo instanceof LakePdo) {
            $this->pdo->close();
        }
        if ($this->readPdo instanceof LakePdo && $this->readPdo !== $this->pdo) {
            $this->readPdo->close();
        }

        parent::disconnect();
    }

    protected function getDefaultQueryGrammar()
    {
        // Laravel 12 grammars take the connection in their constructor;
        // 9-11 grammars have none and are wired up afterwards instead.
        $grammar = new LakeQueryGrammar($this);

        if (method_exists($this, 'withTablePrefix')) {
            if (method_exists($grammar, 'setConnection')) {
                $grammar->setConnection($this);
            }
            $grammar = $this->withTablePrefix($grammar);
        }

        return $grammar;
    }
}

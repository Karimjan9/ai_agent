<?php

namespace App\Services;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Read-only, one-inspection reuse of already verified immutable policy facts. */
final class AgentMethodVerificationScope
{
    private const MAX_ATTEMPTS = 200;
    private const MAX_BYTES = 16777216;

    private array $policies = [];
    private array $active = [];
    private int $attempts = 0;
    private int $bytes = 0;
    private bool $closed = false;
    private ?string $failure = null;
    private readonly int $transactionLevel;
    private readonly object $pdo;
    private readonly ?object $rootTransaction;

    public function __construct(private readonly Connection $connection)
    {
        $this->transactionLevel = $connection->transactionLevel();
        $this->pdo = $connection->getPdo();
        $this->rootTransaction = $this->currentRootTransaction();
    }

    public function policy(string $hash, callable $verify): array
    {
        $this->assertCurrent();
        if (isset($this->active[$hash])) $this->refuse('METHOD_POLICY_VERIFICATION_CYCLE_DETECTED');
        if (isset($this->policies[$hash])) return $this->policies[$hash];
        if (++$this->attempts > self::MAX_ATTEMPTS) $this->refuse('METHOD_POLICY_VERIFICATION_BUDGET_EXCEEDED');
        $this->active[$hash] = true;
        try {
            $result = $verify();
            $this->assertCurrent();
            // A failed/missing proof never becomes a reusable result. Arrays
            // return by value, so a consumer cannot alter the retained facts.
            if (($result['status'] ?? null) !== 'blocked' && ($result['policy_hash'] ?? null) === $hash) {
                $size = strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                if ($this->bytes + $size > self::MAX_BYTES) $this->refuse('METHOD_POLICY_VERIFICATION_MEMORY_EXCEEDED');
                $this->bytes += $size;
                $this->policies[$hash] = $result;
            }

            return $result;
        } finally {
            unset($this->active[$hash]);
        }
    }

    public function assertCurrent(): void
    {
        if ($this->failure !== null) throw new LogicException($this->failure);
        if ($this->closed || DB::connection() !== $this->connection
            || $this->connection->getPdo() !== $this->pdo
            || $this->connection->transactionLevel() !== $this->transactionLevel
            || $this->currentRootTransaction() !== $this->rootTransaction) {
            $this->refuse('METHOD_POLICY_VERIFICATION_SNAPSHOT_CHANGED');
        }
    }

    public function dispose(): void
    {
        $this->closed = true;
        $this->policies = $this->active = [];
        $this->bytes = 0;
    }

    private function currentRootTransaction(): ?object
    {
        // A root commit followed by a new begin can have the same PDO/depth.
        // Retain the manager's original root record, rather than treating that
        // new transaction as the old read context. Nested savepoints preserve
        // their parent's root and require no hooks or transaction changes.
        return app('db.transactions')->getPendingTransactions()->first(
            fn ($transaction): bool => $transaction->connection === $this->connection->getName()
                && $transaction->level === 1);
    }

    private function refuse(string $reason): never
    {
        $this->failure ??= $reason;
        throw new LogicException($this->failure);
    }
}

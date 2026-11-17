<?php

namespace App\Models;

use App\Support\Time;
use CodeIgniter\Model;

class EventQueueModel extends Model
{
    protected $table      = 'event_queue';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'event_id', 'target_id', 'status', 'attempts', 'deferrals', 'available_at', 'available_at_ms',
        'locked_at', 'locked_by', 'delivered_at', 'last_error', 'last_status_code',
        'latency_ms', 'created_at', 'updated_at',
    ];

    /**
     * Atomically claim the next available job.
     *
     * The conditional UPDATE guarantees that two workers cannot process the
     * same row: only the worker whose UPDATE affected a row owns it.
     *
     * @return array<string, mixed>|null
     */
    public function claimNext(string $workerId): ?array
    {
        $now   = Time::dbNow();
        $nowMs = Time::nowMs();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $row = $this->db->table($this->table)
                ->where('status', 'pending')
                ->where('available_at <=', $now)
                ->groupStart()
                    ->where('available_at_ms', null)
                    ->orWhere('available_at_ms <=', $nowMs)
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->limit(1)
                ->get()
                ->getRowArray();

            if ($row === null) {
                return null;
            }

            $this->db->table($this->table)
                ->where('id', $row['id'])
                ->where('status', 'pending')
                ->update([
                    'status'     => 'processing',
                    'locked_by'  => $workerId,
                    'locked_at'  => $now,
                    'updated_at' => $now,
                ]);

            if ($this->db->affectedRows() > 0) {
                $row['status']    = 'processing';
                $row['locked_by'] = $workerId;
                $row['locked_at'] = $now;

                return $row;
            }
        }

        return null;
    }

    /**
     * Update a job only while this worker still owns the lease. Prevents a slow
     * worker that lost its visibility timeout from overwriting a newer claim.
     *
     * @param array<string, mixed> $data
     */
    public function updateOwned(int $id, string $workerId, array $data): bool
    {
        $data['updated_at'] ??= Time::dbNow();

        $this->db->table($this->table)
            ->where('id', $id)
            ->where('status', 'processing')
            ->where('locked_by', $workerId)
            ->update($data);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Extend the visibility lease for all jobs currently owned by a worker.
     * Called on a heartbeat so a long webhook call is not reaped mid-flight.
     */
    public function renewLease(string $workerId, int $visibilityTimeoutSec): int
    {
        $this->db->table($this->table)
            ->where('status', 'processing')
            ->where('locked_by', $workerId)
            ->update([
                'locked_at'  => Time::dbNow(),
                'updated_at' => Time::dbNow(),
            ]);

        return $this->db->affectedRows();
    }

    public function enqueue(string $eventId, int $targetId): bool
    {
        $exists = $this->where('event_id', $eventId)->where('target_id', $targetId)->first();
        if ($exists !== null) {
            return false;
        }

        $now = Time::dbNow();

        return (bool) $this->insert([
            'event_id'       => $eventId,
            'target_id'      => $targetId,
            'status'         => 'pending',
            'attempts'       => 0,
            'deferrals'      => 0,
            'available_at'   => $now,
            'available_at_ms' => Time::nowMs(),
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
    }

    public function resetForReplay(string $eventId, array $targetIds = []): int
    {
        $now = Time::dbNow();

        $builder = $this->db->table($this->table)
            ->where('event_id', $eventId)
            // A job currently in flight must never be replayed underneath its
            // worker; it would be delivered twice.
            ->where('status !=', 'processing');
        if ($targetIds !== []) {
            $builder->whereIn('target_id', array_map('intval', $targetIds));
        }

        $builder->update([
                'status'           => 'pending',
                'attempts'         => 0,
                'deferrals'        => 0,
                'available_at'     => $now,
                'available_at_ms'  => Time::nowMs(),
                'locked_at'        => null,
                'locked_by'        => null,
                'delivered_at'     => null,
                'last_error'       => null,
                'last_status_code' => null,
                'updated_at'       => $now,
            ]);

        return $this->db->affectedRows();
    }

    /**
     * Requeue a dead job from the DLQ. Only jobs actually in `dead` are touched,
     * so a concurrent worker cannot lose a fresh claim.
     */
    public function resetDeadForRetry(int $id): bool
    {
        $now = Time::dbNow();

        $this->db->table($this->table)
            ->where('id', $id)
            ->where('status', 'dead')
            ->update([
                'status'           => 'pending',
                'attempts'         => 0,
                'deferrals'        => 0,
                'available_at'     => $now,
                'available_at_ms'  => Time::nowMs(),
                'locked_at'        => null,
                'locked_by'        => null,
                'delivered_at'     => null,
                'last_error'       => null,
                'last_status_code' => null,
                'latency_ms'       => null,
                'updated_at'       => $now,
            ]);

        return $this->db->affectedRows() > 0;
    }

    public function resetStale(int $visibilityTimeoutSec): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $visibilityTimeoutSec));
        $now    = gmdate('Y-m-d H:i:s');

        $this->db->table($this->table)
            ->where('status', 'processing')
            ->where('locked_at <', $cutoff)
            ->update([
                'status'     => 'pending',
                'locked_at'  => null,
                'locked_by'  => null,
                'last_error' => 'Visibility timeout: worker did not finish the job in time.',
                'updated_at' => $now,
            ]);

        return $this->db->affectedRows();
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = $this->db->table($this->table)
            ->select('status, COUNT(*) AS total')
            ->groupBy('status')
            ->get()
            ->getResultArray();

        $counts = ['pending' => 0, 'processing' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function countPending(): int
    {
        return $this->where('status', 'pending')->countAllResults();
    }

    public function findByEvent(string $eventId): array
    {
        return $this->where('event_id', $eventId)->orderBy('id', 'ASC')->findAll();
    }

    public function deleteForEvent(string $eventId): void
    {
        $this->where('event_id', $eventId)->delete();
    }
}

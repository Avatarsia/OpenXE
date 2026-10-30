<?php

namespace Xentral\Widgets\Chart;

use DateTimeImmutable;

/** Converts recorded start/child events into individual runs without time buckets. */
class CronjobRuntimeData
{
    public static function fromLogs(array $logs): array
    {
        $runs = [];
        foreach ($logs as $log) {
            if (!in_array($log['status'], ['start', 'gestartet'], true)) {
                continue;
            }
            $start = self::timestamp($log['change_time']);
            if ($start === null) {
                continue;
            }
            $runs[(int)$log['id']] = [
                'id' => (int)$log['id'],
                'jobId' => (int)$log['cronjob_id'],
                'job' => $log['bezeichnung'],
                'x' => $start * 1000,
                'start' => $log['change_time'],
                'end' => null,
                'duration' => null,
                'state' => 'unknown',
                'message' => '',
            ];
        }
        foreach ($logs as $log) {
            $parent = (int)$log['parent_id'];
            if (!isset($runs[$parent]) || $runs[$parent]['jobId'] !== (int)$log['cronjob_id']) {
                continue;
            }
            $end = self::timestamp($log['change_time']);
            $start = $runs[$parent]['x'] / 1000;
            if ($end === null || $end < $start) {
                continue;
            }
            $status = $log['status'];
            $runs[$parent]['message'] = $status;
            if (!in_array($status, ['abgeschlossen', 'error', 'teilfehler'], true)) {
                continue;
            }
            // An error must not turn green when the starter subsequently logs completion.
            if ($status === 'error') {
                $runs[$parent]['state'] = 'error';
            } elseif ($runs[$parent]['state'] !== 'error') {
                if ($status === 'teilfehler' || $runs[$parent]['state'] !== 'partial') {
                    $runs[$parent]['state'] = $status === 'teilfehler' ? 'partial' : 'completed';
                }
            }
            $duration = $end - (int)$start;
            if ($runs[$parent]['duration'] === null || $duration >= $runs[$parent]['duration']) {
                $runs[$parent]['duration'] = $duration;
                $runs[$parent]['end'] = $log['change_time'];
            }
        }
        $runs = array_values($runs);
        usort($runs, static function ($a, $b) {
            return [$a['x'], $a['id']] <=> [$b['x'], $b['id']];
        });

        return $runs;
    }

    private static function timestamp(string $value): ?int
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date->getTimestamp() : null;
    }
}

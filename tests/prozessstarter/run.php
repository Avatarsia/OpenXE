<?php

require_once __DIR__.'/../../classes/Widgets/Chart/CronjobRuntimeData.php';

use Xentral\Widgets\Chart\CronjobRuntimeData;

$passed = 0;
$failed = 0;
function check($name, $actual, $expected) {
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failed++;
        echo 'FAIL '.$name.': '.var_export($actual, true).' expected '.var_export($expected, true).PHP_EOL;
    }
}
function row($id, $parent, $status, $time, $job = 1) {
    return ['id' => $id, 'parent_id' => $parent, 'status' => $status,
        'change_time' => $time, 'cronjob_id' => $job, 'bezeichnung' => 'Job '.$job];
}

$rows = [
    row(1, 0, 'start', '2026-10-29 23:59:58'),
    row(2, 0, 'start', '2026-10-30 00:00:00', 2),
    row(3, 1, 'Daten holen', '2026-10-30 00:00:02'),
    row(4, 1, 'abgeschlossen', '2026-10-30 00:00:07'),
    row(5, 2, 'abgeschlossen', '2026-10-30 00:00:00', 2),
    row(6, 0, 'start', '2026-10-30 01:00:00'),
    row(7, 6, 'mutex1', '2026-10-30 01:15:00'),
    row(8, 0, 'start', '2026-10-30 02:00:00'),
    row(9, 8, 'error', '2026-10-30 02:00:12'),
    row(10, 8, 'abgeschlossen', '2026-10-30 02:00:15'),
    row(11, 0, 'mutex2', '2026-10-30 02:15:00'),
    row(12, 0, 'gestartet', '2026-10-30 03:00:00'),
    row(13, 12, 'abgeschlossen', '2026-10-30 03:00:08'),
    row(14, 0, 'start', 'invalid'),
    row(15, 12, 'error', '2026-10-30 04:00:00', 2),
];
$runs = CronjobRuntimeData::fromLogs($rows);
check('one record per actual start', count($runs), 5);
check('midnight exact duration', $runs[0]['duration'], 9);
check('independent interleaved run', $runs[1]['duration'], 0);
check('no completion has no invented duration', $runs[2]['duration'], null);
check('mutex does not close a running import', $runs[2]['state'], 'unknown');
check('error cannot be hidden by later completion', $runs[3]['state'], 'error');
check('duration includes final completion after error', $runs[3]['duration'], 15);
check('legacy started status', $runs[4]['duration'], 8);
check('wrong-job child ignored', $runs[4]['state'], 'completed');
check('raw intermediate status retained', $runs[2]['message'], 'mutex1');
check('empty logs', CronjobRuntimeData::fromLogs([]), []);
check('invalid completion', CronjobRuntimeData::fromLogs([
    row(1, 0, 'start', '2026-10-30 10:00:00'), row(2, 1, 'abgeschlossen', 'bad')
])[0]['duration'], null);
check('negative duration rejected', CronjobRuntimeData::fromLogs([
    row(1, 0, 'start', '2026-10-30 10:00:00'), row(2, 1, 'abgeschlossen', '2026-10-30 09:00:00')
])[0]['state'], 'unknown');

require_once __DIR__.'/../../www/pages/prozessstarter.php';
class RuntimeChartProbe extends Prozessstarter {
    public function __construct($rows) {
        $this->app = (object)['DB' => new class($rows) {
            public $sql;
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function SelectArr($sql) {
                $this->sql = $sql;
                if (strpos($sql, "cl.status IN ('start', 'gestartet')") !== false) {
                    preg_match("~cl.change_time >= '([^']+)' AND cl.change_time <= '([^']+)'~", $sql, $dates);
                    return array_values(array_filter($this->rows, static function ($row) use ($dates) {
                        return in_array($row['status'], ['start', 'gestartet'], true)
                            && $row['change_time'] >= $dates[1] && $row['change_time'] <= $dates[2];
                    }));
                }
                preg_match('~cl.parent_id IN \(([^)]+)\)~', $sql, $ids);
                return array_values(array_filter($this->rows, static function ($row) use ($ids) {
                    return in_array((string)$row['parent_id'], explode(',', $ids[1]), true);
                }));
            }
        }];
    }
    public function chart($limit = 0, $from = '2026-10-29 00:00:00', $to = '2026-10-31 00:00:00') {
        return $this->getChart($from, $to, 1, $limit, true);
    }
}
$unsafe = '</script><img src=x onerror=alert(1)>';
$htmlRows = [row(1, 0, 'start', '2026-10-30 10:00:00'), row(2, 1, 'abgeschlossen', '2026-10-30 10:00:08')];
$htmlRows[0]['bezeichnung'] = $unsafe;
$probe = new RuntimeChartProbe($htmlRows);
$html = $probe->chart();
preg_match('~<script type="application/json">(.*?)</script>~s', $html, $match);
$payload = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
check('rendered payload retains exact duration', $payload['runs'][0]['duration'], 8);
check('job label survives safe JSON encoding', $payload['runs'][0]['job'], $unsafe);
check('embedded labels cannot close JSON script', strpos($html, $unsafe), false);
check('SQL does not truncate timestamps', strpos($probe->app->DB->sql, 'DATE_FORMAT'), false);
check('SQL filters selected job', strpos($probe->app->DB->sql, 'p.id = 1') !== false, true);
check('mini chart offers day ranges', substr_count($html, 'data-runtime-days='), 3);
check('only actual runs rendered', count($payload['runs']), 1);
$boundary = (new RuntimeChartProbe([
    row(1, 0, 'start', '2026-10-30 23:59:58'), row(2, 1, 'abgeschlossen', '2026-10-31 00:00:07')
]))->chart();
preg_match('~<script type="application/json">(.*?)</script>~s', $boundary, $match);
check('completion after selected start window retains full duration', json_decode($match[1], true)['runs'][0]['duration'], 9);

if (isset($argv[1]) && $argv[1] === '--fixture') {
    $fixtureRows = [];
    $end = new DateTimeImmutable('2026-10-30 19:45:00');
    for ($i = 0; $i < 2880; $i++) {
        $start = $end->modify('-'.($i * 15).' minutes');
        $seconds = $i % 137 === 0 ? 180 : 8 + $i % 9;
        $fixtureRows[] = row($i * 2 + 1, 0, 'start', $start->format('Y-m-d H:i:s'));
        $fixtureRows[] = row($i * 2 + 2, $i * 2 + 1, $i % 137 === 0 ? 'error' : 'abgeschlossen', $start->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s'));
    }
    $fixture = (new RuntimeChartProbe($fixtureRows))->chart(0, '2026-09-30 19:45:00', '2026-10-30 19:45:00');
    // Embed the actual shipped assets for an offline browser smoke test.
    $fixture = preg_replace('~<link[^>]+>~', '<style>'.file_get_contents(__DIR__.'/../../www/css/prozessstarter-runtime.css').'</style>', $fixture);
    $fixture = preg_replace('~<script src="./js/prozessstarter-runtime.js[^>]*></script>~', '', $fixture);
    $fixture = '<!doctype html><html><head><meta charset="utf-8"><style>body{font:12px Arial;background:#1e202d;color:#d5d9e2}.chart-title{font-weight:bold}button{background:#272936;color:inherit;border:1px solid #555}</style></head><body>'
        .'<script>'.file_get_contents(__DIR__.'/../../www/js/chart.min.js').'</script>'
        .'<script>'.file_get_contents(__DIR__.'/../../www/js/chart-plugins.js').'</script>'
        .$fixture.'<script>'.file_get_contents(__DIR__.'/../../www/js/prozessstarter-runtime.js').'</script></body></html>';
    file_put_contents($argv[2], $fixture);
}

echo $passed.' passed, '.$failed.' failed'.PHP_EOL;
exit($failed ? 1 : 0);

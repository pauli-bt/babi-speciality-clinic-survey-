<?php
declare(strict_types=1);
// Child for tests/phase8.php. Modes: "csv" (encoding unit cases), "build <type> <from> <to>" (report tables as JSON).
putenv('APP_ENV=testing');
require __DIR__ . '/../app/bootstrap.php';

use App\Helpers\Clock;
use App\Services\{AnalyticsRange, CsvWriter, ReportBuilder};

if ($argv[1] === 'csv') {
    $cases = ['plain', 'a,b', 'say "hi"', "line1\nline2", '=1+1', '+SUM(A1)', '-2+3', '@cmd', "\t=x", "\r=x", ' =x', "'=already", '', 'አማርኛ', '=HYPERLINK("http://e.example","x"),with "q"', 'trail ', "a\r\nb", 0, -5, -20.5, 100.0, 66.7, 0.0, null];
    echo json_encode(array_map([CsvWriter::class, 'cell'], $cases)), PHP_EOL;
    echo json_encode(CsvWriter::row(['a', 'b,c', 3, null])), PHP_EOL;
    exit;
}
$r = AnalyticsRange::fromQuery(['range' => 'custom', 'from' => $argv[3], 'to' => $argv[4]], new DateTimeImmutable('2030-01-01', Clock::zone()));
$b = ReportBuilder::build($argv[2], $r);
echo json_encode(['tz' => date_default_timezone_get(), 'sections' => $b['sections']]), PHP_EOL;

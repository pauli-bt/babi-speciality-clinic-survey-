<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;

/** Executes a .sql file, honouring DELIMITER lines (needed for triggers). */
final class SqlRunner
{
    public static function runFile(PDO $pdo, string $file): int
    {
        $delimiter = ';';
        $buffer = '';
        $count = 0;
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '--')) {
                continue;
            }
            if (preg_match('/^DELIMITER\s+(\S+)$/i', $trim, $m)) {
                $delimiter = $m[1];
                continue;
            }
            $line = preg_replace('/\s--\s.*$/', '', rtrim($line)) ?? $line;
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $sql = trim(substr(rtrim($buffer), 0, -strlen($delimiter)));
                if ($sql !== '') {
                    $pdo->exec($sql);
                    $count++;
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $pdo->exec(trim($buffer));
            $count++;
        }
        return $count;
    }
}

```php
<?php
declare(strict_types=1);

namespace App\Helpers;

use PDO;

final class Db
{
    private static array $conn = [];

    public static function pdo(bool $migrate = false): PDO
    {
        $key = $migrate ? 'migrate' : 'app';

        if (isset(self::$conn[$key])) {
            return self::$conn[$key];
        }

        $user = $migrate
            ? (Env::get('DB_MIGRATE_USER') ?? Env::get('DB_USER'))
            : Env::get('DB_USER');

        $pass = $migrate
            ? (Env::get('DB_MIGRATE_PASS') ?? Env::get('DB_PASS'))
            : Env::get('DB_PASS');

        $host = Env::get('DB_HOST', 'localhost');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'babi_dental');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND =>
                "SET time_zone = '" . Clock::mysqlOffset() . "'",
        ];

        // Enable TLS for hosted databases such as Aiven.
        if (Env::get('DB_SSL', 'false') === 'true') {
            $options[PDO::MYSQL_ATTR_SSL_CA] =
                Env::get('DB_SSL_CA', '');

            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return self::$conn[$key] = new PDO(
            $dsn,
            (string) $user,
            (string) $pass,
            $options
        );
    }
}
```

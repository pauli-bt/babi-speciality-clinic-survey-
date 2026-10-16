<?php
declare(strict_types=1);

namespace App\Helpers;

final class View
{
    public static function render(string $view, array $data = [], string $layout = 'app'): void
    {
        $content = self::capture($view, $data);
        echo self::capture('layouts/' . $layout, $data + ['content' => $content]);
    }

    private static function capture(string $view, array $data): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require BASE_PATH . '/resources/views/' . $view . '.php';
        return (string) ob_get_clean();
    }
}

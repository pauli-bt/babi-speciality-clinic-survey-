<?php
declare(strict_types=1);

/** Escape for HTML output. Every dynamic value in a view goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

<?php
declare(strict_types=1);

/** Escape for HTML output. Every dynamic value in a view goes through this. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Chart.js canvas driven by public/assets/js/analytics.js (no inline script, so it works under the CSP).
 * $cfg: type (bar|line|doughnut), labels[], datasets[[label, data[], color?, colors?[]]], stacked?, horizontal?.
 * Renders an empty-state message when every value is zero.
 */
function chart_canvas(array $cfg, string $ariaLabel, int $height = 280): string
{
    $any = false;
    foreach ($cfg['datasets'] as $d) {
        foreach ($d['data'] as $v) {
            if ((float) $v > 0) { $any = true; break 2; }
        }
    }
    if (!$any) {
        return '<div class="text-secondary small text-center py-4">No data in this period.</div>';
    }
    return '<div class="chart-box chart-h' . (in_array($height, [220, 280, 340], true) ? $height : 280) . '"><canvas role="img" aria-label="' . e($ariaLabel) . '" data-chart="' . e(json_encode($cfg, JSON_UNESCAPED_UNICODE)) . '"></canvas></div>';
}

function pct(?float $v): string
{
    return $v === null ? 'n/a' : number_format($v, 1) . '%';
}

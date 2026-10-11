<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Db;
use App\Helpers\View;

final class AuditController
{
    public function index(): void
    {
        $pdo = Db::pdo();
        $action = is_string($_GET['action'] ?? null) ? substr(trim($_GET['action']), 0, 60) : '';
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 50;
        $where = $action !== '' ? 'WHERE a.action LIKE ?' : '';
        $args = $action !== '' ? [str_replace(['%', '_'], ['\\%', '\\_'], $action) . '%'] : [];

        $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a $where");
        $st->execute($args);
        $total = (int) $st->fetchColumn();

        $st = $pdo->prepare('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ' . $where . ' ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
        $st->execute($args);
        View::render('admin/audit/index', ['title' => 'Audit log', 'rows' => $st->fetchAll(), 'action' => $action, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'total' => $total]);
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Db;
use App\Helpers\Http;
use App\Helpers\View;
use App\Models\User;
use App\Services\Auth;

final class DashboardController
{
    private const HOME = [
        'admin' => '/admin',
        'receptionist' => '/reception',
        'call_operator' => '/call-operator',
        'social_tracker' => '/social-media',
        'marketing' => '/marketing',
    ];

    public function home(): void
    {
        Http::redirect(self::HOME[Auth::user()['role_code']] ?? '/login');
    }

    public function admin(): void
    {
        $pdo = Db::pdo();
        View::render('dashboard/admin', [
            'title' => 'Administrator dashboard',
            'roleCounts' => User::countByRole(),
            'auditToday' => (int) $pdo->query('SELECT COUNT(*) FROM audit_logs WHERE created_at > NOW() - INTERVAL 1 DAY')->fetchColumn(),
            'customers' => (int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'interactions' => (int) $pdo->query('SELECT COUNT(*) FROM interactions')->fetchColumn(),
            'apptOpen' => (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE status IN ('requested','confirmed')")->fetchColumn(),
            'apptDue' => (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE status = 'confirmed' AND appointment_at < NOW()")->fetchColumn(),
            'campaignsActive' => (int) $pdo->query("SELECT COUNT(*) FROM campaigns WHERE status = 'active'")->fetchColumn(),
            'servicesActive' => (int) $pdo->query('SELECT COUNT(*) FROM services WHERE is_active = 1')->fetchColumn(),
        ]);
    }

    public function reception(): void
    {
        $this->entry('Reception', 'Records walk-in visits. Primary outcome: did the customer use the service?');
    }

    public function callOperator(): void
    {
        $this->entry('Telephone', 'Records phone enquiries. Primary outcome: did the caller book an appointment?');
    }

    public function socialMedia(): void
    {
        $this->entry('Social media DM', 'Records direct-message enquiries. Primary outcome: did the customer book an appointment?');
    }

    private function entry(string $channel, string $purpose): void
    {
        View::render('dashboard/entry', ['title' => $channel . ' dashboard', 'channel' => $channel, 'purpose' => $purpose]);
    }

    public function marketing(): void
    {
        (new AnalyticsController())->overview('/marketing', 'Marketing dashboard');
    }
}

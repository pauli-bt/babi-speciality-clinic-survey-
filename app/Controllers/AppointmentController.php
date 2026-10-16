<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Http, View};
use App\Models\Reference;
use App\Services\{AppointmentManager, Auth};

final class AppointmentController
{
    public function index(): void
    {
        $res = AppointmentManager::search($_GET, Auth::can('customers.view_contact'));
        View::render('appointments/index', ['title' => 'Appointments'] + $res + ['services' => Reference::services(), 'canNames' => Auth::can('customers.view_contact')]);
    }

    private function showView(int $id, array $errors = [], int $code = 200): void
    {
        $a = AppointmentManager::find($id) ?? Http::abort(404);
        http_response_code($code);
        View::render('appointments/show', [
            'title' => 'Appointment #' . $id, 'a' => $a, 'errors' => $errors,
            'history' => AppointmentManager::history($id), 'reschedules' => AppointmentManager::reschedules($id), 'links' => AppointmentManager::links($id),
            'canNames' => Auth::can('customers.view_contact'),
        ]);
    }

    public function show(int $id): void
    {
        $this->showView($id);
    }

    public function status(int $id): void
    {
        $res = AppointmentManager::changeStatus(Auth::user(), $id, (string) ($_POST['status'] ?? ''), (string) ($_POST['appointment_at'] ?? ''));
        if (!$res['ok']) {
            $this->showView($id, $res['errors'], 422);
            return;
        }
        Http::flash('success', 'Appointment status updated.');
        Http::redirect('/appointments/' . $id);
    }

    public function reschedule(int $id): void
    {
        $res = AppointmentManager::reschedule(Auth::user(), $id, (string) ($_POST['appointment_at'] ?? ''));
        if (!$res['ok']) {
            $this->showView($id, $res['errors'], 422);
            return;
        }
        Http::flash('success', 'Appointment rescheduled.');
        Http::redirect('/appointments/' . $id);
    }
}

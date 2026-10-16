<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Http, View};
use App\Services\{Auth, ServiceManager};

final class ServiceController
{
    public function index(): void
    {
        View::render('services/index', ['title' => 'Services', 'services' => ServiceManager::all()]);
    }

    public function create(): void
    {
        View::render('services/form', ['title' => 'New service', 'service' => null, 'errors' => [], 'old' => []]);
    }

    public function store(): void
    {
        $res = ServiceManager::create(Auth::user(), $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('services/form', ['title' => 'New service', 'service' => null, 'errors' => $res['errors'], 'old' => $_POST]);
            return;
        }
        Http::flash('success', 'Service created.');
        Http::redirect('/admin/services');
    }

    public function edit(int $id): void
    {
        $s = ServiceManager::find($id) ?? Http::abort(404);
        View::render('services/form', ['title' => 'Edit service', 'service' => $s, 'errors' => [], 'old' => []]);
    }

    public function update(int $id): void
    {
        $s = ServiceManager::find($id) ?? Http::abort(404);
        $res = ServiceManager::update(Auth::user(), $s, $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('services/form', ['title' => 'Edit service', 'service' => $s, 'errors' => $res['errors'], 'old' => $_POST]);
            return;
        }
        Http::flash('success', 'Service updated.');
        Http::redirect('/admin/services');
    }

    public function toggle(int $id): void
    {
        $s = ServiceManager::find($id) ?? Http::abort(404);
        $activate = (int) $s['is_active'] !== 1;
        ServiceManager::setActive(Auth::user(), $s, $activate);
        Http::flash('success', $activate ? 'Service activated.' : 'Service deactivated. Existing records keep it.');
        Http::redirect('/admin/services');
    }
}

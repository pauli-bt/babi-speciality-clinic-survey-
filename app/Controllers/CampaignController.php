<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Http, View};
use App\Models\Reference;
use App\Services\{Auth, CampaignManager};

final class CampaignController
{
    private function form(string $title, ?array $c, array $errors, array $old): void
    {
        View::render('campaigns/form', ['title' => $title, 'campaign' => $c, 'platforms' => Reference::platforms(), 'errors' => $errors, 'old' => $old]);
    }

    public function index(): void
    {
        $status = (string) ($_GET['status'] ?? '');
        View::render('campaigns/index', ['title' => 'Campaigns', 'campaigns' => CampaignManager::all($status !== '' ? $status : null), 'status' => $status, 'canManage' => Auth::can('campaigns.manage')]);
    }

    public function create(): void
    {
        $this->form('New campaign', null, [], []);
    }

    public function store(): void
    {
        $res = CampaignManager::create(Auth::user(), $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            $this->form('New campaign', null, $res['errors'], $_POST);
            return;
        }
        Http::flash('success', 'Campaign created.');
        Http::redirect('/campaigns');
    }

    public function edit(int $id): void
    {
        $c = CampaignManager::find($id) ?? Http::abort(404);
        $this->form('Edit campaign', $c, [], []);
    }

    public function update(int $id): void
    {
        $c = CampaignManager::find($id) ?? Http::abort(404);
        $res = CampaignManager::update(Auth::user(), $c, $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            $this->form('Edit campaign', $c, $res['errors'], $_POST);
            return;
        }
        Http::flash('success', 'Campaign updated.');
        Http::redirect('/campaigns');
    }
}

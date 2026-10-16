<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\{Channel, Http, View};
use App\Models\{Appointment, Interaction, Reference};
use App\Services\{Audit, Auth, CustomerSelection, CustomerService, InteractionService};

final class InteractionController
{
    // One thin entry point per channel; each route is bound to exactly one role in config/routes.php.
    public function receptionForm(): void { $this->form('reception'); }
    public function callForm(): void { $this->form('phone'); }
    public function socialForm(): void { $this->form('social_dm'); }
    public function receptionStore(): void { $this->store('reception'); }
    public function callStore(): void { $this->store('phone'); }
    public function socialStore(): void { $this->store('social_dm'); }

    private function viewData(string $channel, array $old, array $errors, array $duplicates, string $token): array
    {
        $sel = CustomerSelection::current();
        $customerId = (int) ($old['customer_id'] ?? ($sel['id'] ?? 0));
        $customer = $customerId > 0 ? CustomerService::selectable($customerId) : null;
        $old += ['customer_mode' => $customer ? 'existing' : 'new', 'interaction_at' => date('Y-m-d\TH:i')];
        if ($customer) {
            $old['customer_id'] = (string) $customer['id'];
        } else {
            unset($old['customer_id']);
        }
        return [
            'title' => Channel::LABELS[$channel] . ': record an interaction', 'channel' => $channel, 'channelLabel' => Channel::LABELS[$channel],
            'action' => Channel::PATHS[$channel], 'old' => $old, 'errors' => $errors, 'duplicates' => $duplicates, 'formToken' => $token,
            'customer' => $customer, 'appointments' => $customer ? Appointment::listFor((int) $customer['id']) : [],
            'sources' => Reference::sources(), 'platforms' => Reference::platforms(), 'campaigns' => Reference::campaigns(), 'services' => Reference::services(),
            'reasonsNC' => Reference::reasons($channel === 'reception' ? 'service_usage' : 'booking'),
            'channelFixed' => $channel, 'channels' => Channel::LABELS,
        ];
    }

    private function form(string $channel): void
    {
        View::render('interactions/form', $this->viewData($channel, [], [], [], bin2hex(random_bytes(16))));
    }

    private function store(string $channel): void
    {
        $user = Auth::user();
        if (Channel::forRole($user['role_code']) !== $channel) {
            Http::abort(403);
        }
        $res = InteractionService::create($user, $_POST);
        if ($res['ok']) {
            Http::flash('success', empty($res['repeat'])
                ? 'Interaction recorded for ' . $res['customer_code'] . (!empty($res['appointment_id']) ? ' and the appointment was created.' : '.')
                : 'This interaction was already recorded from the same form submission.');
            Http::redirect('/interactions/' . $res['id']);
        }
        if (isset($res['id'])) { // identical token belonging to someone else
            Http::abort(403);
        }
        http_response_code($res['errors'] ? 422 : 200);
        $token = preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['form_token'] ?? '')) ? $_POST['form_token'] : bin2hex(random_bytes(16));
        View::render('interactions/form', $this->viewData($channel, $_POST, $res['errors'], $res['duplicates'], $token));
    }

    /** Staff only ever reach their own channel's interactions; unreachable ones look like they do not exist. */
    private function scoped(int $id): array
    {
        $i = Interaction::find($id);
        if (!$i || (!Auth::can('interactions.view_all') && Channel::forRole(Auth::user()['role_code']) !== $i['channel'])) {
            Http::abort(404);
        }
        return $i;
    }

    public function index(): void
    {
        $user = Auth::user();
        $own = Channel::forRole($user['role_code']);
        $filter = (!$own && is_string($_GET['channel'] ?? null) && isset(Channel::LABELS[$_GET['channel']])) ? $_GET['channel'] : null;
        $scope = Auth::can('interactions.view_all') ? $filter : $own;
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 20;
        $first = Interaction::list($scope, 1, 1);
        $pages = max(1, (int) ceil($first['total'] / $per));
        $page = min($page, $pages);
        $res = Interaction::list($scope, $page, $per);
        View::render('interactions/index', ['title' => 'Interactions', 'rows' => $res['rows'], 'total' => $res['total'], 'page' => $page, 'pages' => $pages, 'filter' => $filter, 'own' => $own]);
    }

    public function show(int $id): void
    {
        $i = $this->scoped($id);
        View::render('interactions/show', ['title' => 'Interaction #' . $id, 'i' => $i, 'appointments' => Interaction::linkedAppointments($id), 'edit' => InteractionService::canEdit(Auth::user(), $i)]);
    }

    private function editData(array $i, array $old, array $errors): array
    {
        return ['title' => 'Edit interaction #' . $i['id'], 'i' => $i, 'old' => $old, 'errors' => $errors, 'campaigns' => Reference::campaigns(), 'services' => Reference::services(),
            'platforms' => Reference::platforms(), 'reasonsNC' => Reference::reasons($i['channel'] === 'reception' ? 'service_usage' : 'booking')];
    }

    private function editable(int $id): array
    {
        $i = $this->scoped($id);
        $can = InteractionService::canEdit(Auth::user(), $i);
        if (!$can['allowed']) {
            Audit::log((int) Auth::user()['id'], 'interaction.edit_denied', 'interaction', $id, null, ['reason' => str_contains((string) $can['message'], 'window') ? 'edit_window_passed' : 'not_owner']);
            Http::abort(403, (string) $can['message']);
        }
        return [$i, $can];
    }

    public function edit(int $id): void
    {
        [$i] = $this->editable($id);
        View::render('interactions/edit', $this->editData($i, [], []));
    }

    public function update(int $id): void
    {
        [$i, $can] = $this->editable($id);
        $res = InteractionService::update(Auth::user(), $i, $_POST, $can['admin']);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('interactions/edit', $this->editData($i, $_POST, $res['errors']));
            return;
        }
        Http::flash('success', 'Interaction updated.');
        Http::redirect('/interactions/' . $id);
    }

    /** Minimal appointment list for the follow-up selector (active customers only). */
    public function appointments(int $id): void
    {
        if (!CustomerService::selectable($id)) {
            Http::abort(404);
        }
        $out = [];
        foreach (Appointment::listFor($id) as $a) {
            $out[] = ['id' => (int) $a['id'], 'service' => $a['service_name'], 'status' => $a['status'], 'at' => $a['appointment_at'] ? substr((string) $a['appointment_at'], 0, 16) : null];
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['appointments' => $out], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}

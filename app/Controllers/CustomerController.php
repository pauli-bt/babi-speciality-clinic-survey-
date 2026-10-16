<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Channel;
use App\Helpers\Http;
use App\Helpers\Phone;
use App\Helpers\View;
use App\Models\Customer;
use App\Models\Reference;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\CustomerSelection;
use App\Services\CustomerService;

final class CustomerController
{
    private function formData(array $extra = []): array
    {
        $user = Auth::user();
        return $extra + [
            'sources' => Reference::sources(), 'platforms' => Reference::platforms(), 'campaigns' => Reference::campaigns(),
            'channelFixed' => Channel::forRole($user['role_code']), 'channels' => Channel::LABELS,
        ];
    }

    /** Staff see active customers only; archived records are an administrator concern. */
    private function visible(int $id): array
    {
        $c = Customer::find($id);
        if (!$c || ($c['status'] !== 'active' && !Auth::can('customers.manage'))) {
            Http::abort(404);
        }
        return $c;
    }

    public function search(): void
    {
        $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $archived = Auth::can('customers.manage') && ($_GET['archived'] ?? '') === '1';
        $res = CustomerService::search($q, $archived, (int) ($_GET['page'] ?? 1));
        View::render('customers/search', ['title' => 'Find a customer', 'q' => $q, 'archived' => $archived, 'res' => $res]);
    }

    /** JSON for the reusable picker: minimal fields, masked phone, at most 8 rows. */
    public function lookup(): void
    {
        $q = is_string($_GET['q'] ?? null) ? $_GET['q'] : '';
        $parsed = CustomerService::parseQuery($q);
        $out = ['results' => [], 'message' => $parsed['error'], 'more' => false];
        if (!in_array($parsed['kind'], ['none', 'invalid'], true)) {
            $res = CustomerService::search($q, false, 1);
            foreach (array_slice($res['rows'], 0, 8) as $r) {
                $out['results'][] = [
                    'id' => (int) $r['id'], 'code' => $r['customer_code'], 'name' => $r['name'], 'phone' => Phone::mask($r['phone_normalized']),
                    'source' => $r['source_name'], 'registered' => substr((string) $r['first_acquisition_at'], 0, 10), 'shared_phone' => (int) $r['shared_phone_count'],
                ];
            }
            $out['more'] = $res['total'] > 8;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    public function create(): void
    {
        View::render('customers/new', $this->formData(['title' => 'Register customer', 'errors' => [], 'old' => [], 'duplicates' => [], 'formToken' => bin2hex(random_bytes(16))]));
    }

    public function store(): void
    {
        $res = CustomerService::register(Auth::user(), $_POST);
        if ($res['ok']) {
            Http::flash('success', empty($res['repeat']) ? 'Customer registered.' : 'This customer was already registered from the same form submission.');
            Http::redirect('/customers/' . $res['id']);
        }
        if (isset($res['id'])) { // identical token but created by someone else: refuse
            Http::abort(403);
        }
        http_response_code($res['errors'] ? 422 : 200);
        $token = preg_match('/^[a-f0-9]{32}$/', (string) ($_POST['form_token'] ?? '')) ? $_POST['form_token'] : bin2hex(random_bytes(16));
        View::render('customers/new', $this->formData([
            'title' => 'Register customer', 'errors' => $res['errors'], 'old' => $_POST, 'duplicates' => $res['duplicates'], 'formToken' => $token,
        ]));
    }

    public function show(int $id): void
    {
        $c = $this->visible($id);
        $user = Auth::user();
        $channel = Channel::forRole($user['role_code']); // entry staff only see interactions of their own channel
        View::render('customers/show', [
            'title' => $c['customer_code'], 'c' => $c, 'interactions' => Customer::interactions($id, $channel), 'ownChannel' => $channel,
            'canManage' => Auth::can('customers.manage'), 'canCorrect' => Auth::can('customers.correct_source'),
            'corrections' => Auth::can('customers.correct_source') ? $this->corrections($id) : [],
        ]);
    }

    private function corrections(int $id): array
    {
        $st = \App\Helpers\Db::pdo()->prepare('SELECT k.*, a.name AS old_name, b.name AS new_name, u.username FROM customer_source_corrections k
            JOIN acquisition_sources a ON a.id = k.old_source_id JOIN acquisition_sources b ON b.id = k.new_source_id JOIN users u ON u.id = k.corrected_by_user_id
            WHERE k.customer_id = ? ORDER BY k.id DESC');
        $st->execute([$id]);
        return $st->fetchAll();
    }

    public function select(int $id): void
    {
        $c = CustomerService::selectable($id);
        if (!$c) {
            Http::abort(404);
        }
        CustomerSelection::select($id);
        Http::flash('success', 'Selected ' . ($c['name'] ?? 'customer') . ' (' . $c['customer_code'] . '). New interactions will be recorded against this customer.');
        $back = (string) ($_POST['back'] ?? '');
        Http::redirect(in_array($back, array_map(static fn ($c) => Channel::formPath($c), array_keys(Channel::LABELS)), true) ? $back : '/customers/' . $id);
    }

    public function clearSelection(): void
    {
        CustomerSelection::clear();
        Http::flash('info', 'Selection cleared.');
        Http::redirect('/');
    }

    public function edit(int $id): void
    {
        View::render('customers/edit', ['title' => 'Correct customer details', 'c' => $this->visible($id), 'errors' => [], 'old' => []]);
    }

    public function update(int $id): void
    {
        $c = $this->visible($id);
        $res = CustomerService::updateContact(Auth::user(), $id, $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('customers/edit', ['title' => 'Correct customer details', 'c' => $c, 'errors' => $res['errors'], 'old' => $_POST]);
            return;
        }
        Http::flash('success', 'Customer details updated.');
        Http::redirect('/customers/' . $id);
    }

    public function archive(int $id): void
    {
        $this->visible($id);
        $new = CustomerService::toggleArchive(Auth::user(), $id);
        if ($new === null) {
            Http::abort(404);
        }
        if ($new === 'archived' && ($_SESSION['selected_customer_id'] ?? null) === $id) {
            CustomerSelection::clear();
        }
        Http::flash('success', $new === 'archived' ? 'Customer archived. Staff can no longer find or select this record.' : 'Customer restored.');
        Http::redirect('/customers/' . $id);
    }

    public function sourceForm(int $id): void
    {
        View::render('customers/source', $this->formData(['title' => 'Correct acquisition source', 'c' => $this->visible($id), 'errors' => [], 'old' => []]));
    }

    public function sourceCorrect(int $id): void
    {
        $c = $this->visible($id);
        $res = CustomerService::correctSource(Auth::user(), $id, $_POST);
        if (!$res['ok']) {
            http_response_code(422);
            View::render('customers/source', $this->formData(['title' => 'Correct acquisition source', 'c' => $c, 'errors' => $res['errors'], 'old' => $_POST]));
            return;
        }
        Http::flash('success', 'Original acquisition source corrected and recorded in the audit trail.');
        Http::redirect('/customers/' . $id);
    }
}

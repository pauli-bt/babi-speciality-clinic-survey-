<?php
declare(strict_types=1);

namespace App\Services;

/**
 * "Currently selected existing customer" for the signed-in staff member. Phase 4 interaction forms read it
 * (or take a posted customer_id and validate it with CustomerService::selectable()). Lives in the session,
 * so it ends with logout/expiry and is never shared between users.
 */
final class CustomerSelection
{
    public static function select(int $customerId): void
    {
        $_SESSION['selected_customer_id'] = $customerId;
    }

    public static function clear(): void
    {
        unset($_SESSION['selected_customer_id']);
    }

    public static function current(): ?array
    {
        $id = $_SESSION['selected_customer_id'] ?? null;
        if (!is_int($id)) {
            return null;
        }
        $c = CustomerService::selectable($id);
        if (!$c) {
            self::clear();
        }
        return $c;
    }
}

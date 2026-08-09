<?php

namespace Modules\Identity\Domain\Contracts;

use Modules\Identity\Domain\DTOs\AddressSnapshotDTO;
use Modules\Identity\Domain\DTOs\UserSummaryDTO;

interface IdentityManagerInterface
{
    /**
     * Determine whether the given user holds the administrator role.
     * Called cross-module — never leak the User model across this boundary.
     */
    public function isAdmin(int $userId): bool;

    /**
     * Determine whether the given user is authorised to carry deliveries — the
     * smallest primitive Shipment needs before assigning a shipment to someone.
     * Shipment asks this question instead of reading roles or the User model.
     */
    public function isDeliveryUser(int $userId): bool;

    /**
     * A frozen copy of one address, but only when it belongs to the given user.
     * Returns null when the address does not exist or is owned by somebody else,
     * so ownership can never be bypassed by guessing an id. The caller decides
     * how to report that (Shipment turns it into a 422 on `address_id`).
     */
    public function getOwnedAddressSnapshot(int $userId, int $addressId): ?AddressSnapshotDTO;

    /**
     * Return the identity fields another module needs to snapshot (e.g. Order's
     * immutable customer_snapshot). Never leak the User model across this boundary.
     */
    public function getUserSummary(int $userId): UserSummaryDTO;

    /**
     * Ids of every user holding the administrator role — the audience for
     * operational, admin-facing notifications. Ids only; no User model crosses
     * this boundary.
     *
     * @return list<int>
     */
    public function getAdminUserIds(): array;

    /**
     * The same audience as getAdminUserIds(), but with the fields a caller needs
     * to *show* an admin (name, phone) rather than only notify them — resolved in
     * one query instead of one per id. Still DTOs; no User model crosses here.
     *
     * @return list<UserSummaryDTO>
     */
    public function getAdminUserSummaries(): array;

    /**
     * Ids of every user who may carry deliveries. The sibling of getAdminUserIds()
     * — ids only, no User model crosses this boundary.
     *
     * @return list<int>
     */
    public function getDeliveryUserIds(): array;
}

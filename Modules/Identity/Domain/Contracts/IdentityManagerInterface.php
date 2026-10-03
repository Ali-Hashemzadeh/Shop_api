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
     * Classify the shipping distance between two provinces using the database-driven
     * province adjacency Identity owns. Returns one of the three canonical primitive
     * values (a shared vocabulary with the Shipment tariff engine):
     *
     *   - 'same_province'  origin and destination are the same province
     *   - 'neighbor'       destination borders the origin (either direction)
     *   - 'non_neighbor'   anything else, or when either id is null/unknown
     *
     * Identity classifies because it owns the provinces/adjacency tables; the Shipment
     * module asks this question instead of joining across the module wall.
     */
    public function getProvinceDistanceType(?int $originProvinceId, ?int $destinationProvinceId): string;

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

    /**
     * Whether the given user holds the customer-support role. The Ticket module
     * asks this instead of reading roles or the User model — e.g. before letting
     * a ticket be assigned to someone.
     */
    public function isSupportUser(int $userId): bool;

    /**
     * Ids of every support agent — the audience for "a new ticket arrived"
     * notifications. Ids only; no User model crosses this boundary.
     *
     * @return list<int>
     */
    public function getSupportUserIds(): array;

    /**
     * The support agents with the fields needed to *show* them (name, phone) so a
     * frontend can render an "assign to" picker — resolved in one query. DTOs
     * only; no User model crosses here.
     *
     * @return list<UserSummaryDTO>
     */
    public function getSupportUserSummaries(): array;

    /**
     * Grant the support role to an existing account, additively (never syncs, so
     * every other role is kept). Idempotent. Returns the updated summary.
     * Throws ModelNotFoundException when the user does not exist.
     */
    public function grantSupportRole(int $userId): UserSummaryDTO;

    /**
     * Remove the support role from an account, leaving its other roles intact.
     * Idempotent. Returns the updated summary. Throws ModelNotFoundException when
     * the user does not exist.
     */
    public function revokeSupportRole(int $userId): UserSummaryDTO;
}

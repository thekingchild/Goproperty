<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Policies;

use App\Enums\OrganizationPermission;
use App\Models\User;
use App\Plans\Feature;
use App\Plans\Features;
use App\Tenancy\BlockedWhileImpersonating;
use App\Tenancy\OrganizationContext;
use Illuminate\Auth\Access\Response;
use Modules\BuildingGroup\Groups\GroupMembership;
use Modules\BuildingGroup\Groups\GroupSettings;
use Modules\BuildingGroup\Models\BuildingGroup;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;
use Modules\Properties\Contracts\BuildingAccess;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Building Groups in the current organization (ARCHITECTURE.md § 5,
 * § 6.8; P07-02, docs/plans/P07-subtasks.md defaults 10–24). Registered
 * for BuildingGroup, GroupPost and GroupComment. Who, never what: the
 * rules on a post's words, images and replies are Posts\GroupPosts'.
 *
 * Every ability needs the plan switch `building_group` (Growth and up;
 * ARCHITECTURE.md § 6.10) on for the organization: off, the group is not
 * there for anyone (the posts stay, for the day it is on again).
 *
 * Members (the workspace): a member who sees buildings (`properties.view`:
 * owners, administrators, agents; kept while suspended) sees the groups of
 * the buildings they see (an assigned agent: their buildings only, any
 * other group 404) and their posts, closed groups too; a member holding
 * `building_groups.participate` (the same three roles) posts, comments and
 * reacts in an open group. Moderation (`building_groups.moderate`) is
 * P07-04's.
 *
 * Tenants (the portal; they hold no workspace role, so their membership is
 * the permission, as for tickets): a tenant sees a group while one of their
 * own tenant records here is a current member (Groups\GroupMembership: an
 * active lease in the building, P07-01) and the group is open; anyone else
 * gets 404. A member posts unless the group keeps posting to staff
 * (GroupSettings::$membersPost), comments and reacts; a muted member
 * (P07-04) only reads.
 *
 * Authors remove their own posts and comments (`deletePost`,
 * `deleteComment`). Images: adding them follows posting and the group's
 * image setting; adding and seeing them are refused while GoProperty staff
 * impersonate (people's photos, PLATFORM_CONSOLE.md § 6), the words are
 * not. The central guards apply on top: a suspended organization's group
 * is read-only (OrganizationWriteGuard: every ability here but view… is a
 * write), and a `delete…` ability is refused while staff impersonate.
 */
final class BuildingGroupPolicy
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly BuildingAccess $access,
        private readonly GroupMembership $membership,
        private readonly Features $features,
    ) {}

    /** The workspace's list of groups. */
    public function viewAny(User $user): bool
    {
        return $this->planAllows() && $this->holds($user, OrganizationPermission::ViewProperties);
    }

    /** Export the list of groups (Lists\GroupList, already narrowed). */
    public function export(User $user): bool
    {
        return $this->viewAny($user);
    }

    /** A group and its posts, from the workspace. */
    public function view(User $user, BuildingGroup $group): Response|bool
    {
        return $this->reachable($user, $group) ?? $this->viewAny($user);
    }

    /** Start a post, from the workspace. */
    public function post(User $user, BuildingGroup $group): Response|bool
    {
        return $this->staffWrites($user, $group);
    }

    /** Comment or reply, from the workspace. */
    public function comment(User $user, BuildingGroup $group): Response|bool
    {
        return $this->staffWrites($user, $group);
    }

    /** React to a post or comment, from the workspace. */
    public function react(User $user, BuildingGroup $group): Response|bool
    {
        return $this->staffWrites($user, $group);
    }

    /** Add images to a post, from the workspace. */
    #[BlockedWhileImpersonating]
    public function attachImages(User $user, BuildingGroup $group): Response|bool
    {
        return $this->withImages($group, $this->post($user, $group));
    }

    /** See the posts' images, from the workspace. */
    #[BlockedWhileImpersonating]
    public function viewImages(User $user, BuildingGroup $group): Response|bool
    {
        return $this->view($user, $group);
    }

    /** A group the tenant lives in, from the portal. */
    public function viewAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        return $this->tenantSees($user, $group) ? true : Response::denyAsNotFound();
    }

    /** Start a post, from the portal (unless the group keeps posting to staff). */
    public function postAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        $writes = $this->tenantWrites($user, $group);

        if ($writes !== true) {
            return $writes;
        }

        return GroupSettings::of($group)->membersPost
            ? true
            : Response::deny(__('In this group only the agency starts posts. You can comment and react.'));
    }

    /** Comment or reply, from the portal. */
    public function commentAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        return $this->tenantWrites($user, $group);
    }

    /** React to a post or comment, from the portal. */
    public function reactAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        return $this->tenantWrites($user, $group);
    }

    /** Add images to a post, from the portal. */
    #[BlockedWhileImpersonating]
    public function attachImagesAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        return $this->withImages($group, $this->postAsTenant($user, $group));
    }

    /** See the posts' images, from the portal. */
    #[BlockedWhileImpersonating]
    public function viewImagesAsTenant(User $user, BuildingGroup $group): Response|bool
    {
        return $this->viewAsTenant($user, $group);
    }

    /** Remove one's own post (it vanishes for everyone; its images are deleted for good). */
    public function deletePost(User $user, GroupPost $post): Response|bool
    {
        return $this->authored($user, $post->organization_id, $post->author_user_id);
    }

    /** Remove one's own comment (and the replies under it). */
    public function deleteComment(User $user, GroupComment $comment): Response|bool
    {
        return $this->authored($user, $comment->organization_id, $comment->author_user_id);
    }

    /** Never: posts and comments are removed with a soft delete only (AGENT.md § 3 rule 7). */
    public function forceDelete(User $user, GroupPost|GroupComment $item): bool
    {
        return false;
    }

    private function staffWrites(User $user, BuildingGroup $group): Response|bool
    {
        $reachable = $this->reachable($user, $group);

        if ($reachable !== null) {
            return $reachable;
        }

        if (! $this->planAllows() || ! $this->holds($user, OrganizationPermission::ParticipateInBuildingGroups)) {
            return false;
        }

        return $group->is_active ? true : Response::deny(__('This group is closed: its building is no longer managed here.'));
    }

    private function tenantWrites(User $user, BuildingGroup $group): Response|bool
    {
        if (! $this->tenantSees($user, $group)) {
            return Response::denyAsNotFound();
        }

        $member = $this->membership->rowFor($user, $group);

        return $member !== null && ! $this->membership->muted($member)
            ? true
            : Response::deny(__('You cannot write in this group for now.'));
    }

    private function withImages(BuildingGroup $group, Response|bool $writes): Response|bool
    {
        if ($writes !== true && ! ($writes instanceof Response && $writes->allowed())) {
            return $writes;
        }

        return GroupSettings::of($group)->images ? true : Response::deny(__('This group does not take photos.'));
    }

    private function tenantSees(User $user, BuildingGroup $group): bool
    {
        return $this->context->has()
            && $group->organization_id === $this->context->id()
            && $group->is_active
            && $this->planAllows()
            && $this->membership->rowFor($user, $group) !== null;
    }

    /**
     * Null when the group is this organization's and in the member's
     * buildings (the ability's own rule decides); false for another
     * organization's group; 404 outside a narrowed agent's buildings.
     */
    private function reachable(User $user, BuildingGroup $group): Response|false|null
    {
        if (! $this->context->has() || $group->organization_id !== $this->context->id()) {
            return false;
        }

        if (! $this->access->allows($user, $group->property_id)) {
            return Response::denyAsNotFound();
        }

        return null;
    }

    private function authored(User $user, int $organizationId, ?int $authorUserId): Response|bool
    {
        return $this->context->has()
            && $organizationId === $this->context->id()
            && $authorUserId === $user->id
            && $this->planAllows()
            ? true
            : Response::denyAsNotFound();
    }

    private function planAllows(): bool
    {
        return $this->context->has() && $this->features->enabled(Feature::BuildingGroup, $this->context->get());
    }

    private function holds(User $user, OrganizationPermission $permission): bool
    {
        if (! $this->context->has() || ! in_array($this->context->id(), $user->workspaceOrganizationIds(), true)) {
            return false;
        }

        try {
            return $user->hasPermissionTo($permission->value, OrganizationPermission::GUARD);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }
}

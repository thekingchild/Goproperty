<?php

declare(strict_types=1);

namespace Modules\BuildingGroup\Feed;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Modules\BuildingGroup\Models\GroupComment;
use Modules\BuildingGroup\Models\GroupPost;

/**
 * Whether the viewer may remove their own posts and comments now: asked of
 * the policy once per kind (the answer is the same for all of their own
 * items in the organization), so a long feed does not ask it per item.
 *
 * @internal
 */
final class DeletePermission
{
    private ?bool $posts = null;

    private ?bool $comments = null;

    public function __construct(private readonly User $viewer) {}

    public function post(GroupPost $post): bool
    {
        return $this->posts ??= Gate::forUser($this->viewer)->allows('deletePost', $post);
    }

    public function comment(GroupComment $comment): bool
    {
        return $this->comments ??= Gate::forUser($this->viewer)->allows('deleteComment', $comment);
    }
}

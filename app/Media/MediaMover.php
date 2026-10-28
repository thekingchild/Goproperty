<?php

declare(strict_types=1);

namespace App\Media;

use App\Tenancy\Moves\BuildingMove;
use App\Tenancy\Moves\MovesBuildingRecords;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;

/**
 * The media library's part of a building move (BILLING_PAYMENTS.md § 7.4
 * "Media files move to the receiving organization's folder"): runs last,
 * and moves the files of every record the modules moved (a building's
 * photos, a meter reading's photo), evidence included, since their
 * records moved. Files live at `{organization id}/{uuid}/` on their disk
 * (MediaPathGenerator), so each file's folder is copied to the new
 * organization's folder before its row moves; the old folder is removed
 * once the move commits, and the new copy if the move fails. Files of
 * records that stay (issued documents, payment proofs, receipts) never
 * move.
 */
final class MediaMover implements MovesBuildingRecords
{
    public function order(): int
    {
        return 90;
    }

    public function move(BuildingMove $move): void
    {
        foreach ($move->moved() as $model => $ids) {
            if ($model === Media::class || ! is_subclass_of($model, HasMedia::class) || $ids === []) {
                continue;
            }

            $media = Media::query()
                ->where('model_type', Relation::getMorphAlias($model))
                ->whereIn('model_id', $ids)
                ->orderBy('id')
                ->get();

            foreach ($media as $file) {
                $this->copyFolder($move, $file);
            }

            $move->reassign(Media::class, $media->modelKeys());
            $move->count('files_moved', $media->count());
        }
    }

    private function copyFolder(BuildingMove $move, Media $file): void
    {
        foreach (array_unique(array_filter([$file->disk, $file->conversions_disk])) as $disk) {
            $storage = Storage::disk($disk);
            $old = $move->from->id.'/'.$file->uuid;
            $new = $move->to->id.'/'.$file->uuid;

            $move->onFailure(static fn () => $storage->deleteDirectory($new));

            foreach ($storage->allFiles($old) as $path) {
                $storage->copy($path, $new.substr($path, strlen($old)));
            }

            $move->afterCommit(static fn () => $storage->deleteDirectory($old));
        }
    }
}

<?php

declare(strict_types=1);

use App\Hosting\Queue\QueueTick;
use App\Hosting\Queue\TickStopReason;
use App\Media\Media;
use App\Media\PerformMediaConversions;
use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Conversions\FileManipulator;

require_once __DIR__.'/../../Support/media.php';

/*
 * P02-05: thumbnails and WebP versions are made by queued jobs on the
 * `media` queue, which the shared-hosting queue tick runs in its bulk lane
 * (config/hosting.php), each job well within the 50 seconds a job gets
 * (AGENT.md § 4). GD only, no optimizer binaries.
 */

beforeEach(function () {
    Storage::fake('media');
    $this->acme = Organization::factory()->create(['slug' => 'acme-homes']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

function mediaFresh(Organization $organization, Media $media): Media
{
    return inOrganization($organization, fn () => Media::query()->findOrFail($media->id));
}

it('queues the conversions on the media queue instead of making them during the upload', function () {
    Queue::fake();

    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));

    Queue::assertPushedOn('media', PerformMediaConversions::class);
    Queue::assertPushed(PerformMediaConversions::class, 1);
    expect($photo->hasGeneratedConversion('thumb'))->toBeFalse()
        ->and(Storage::disk('media')->allFiles())->toHaveCount(1);
});

it('makes no conversions for a PDF or for branding images', function () {
    Queue::fake();

    uploadMedia($this->acme, mediaPdf(), 'documents');
    uploadMedia($this->acme, mediaImage('logo.png', 400, 200, 'png'), 'branding');

    Queue::assertNothingPushed();
});

it('converts a 12-megapixel phone photo in the shared profile\'s queue tick, well within the 50-second job budget', function () {
    config(['queue.default' => 'database']);
    $photo = uploadMedia($this->acme, mediaImage('phone.jpg', 4000, 3000));

    $job = DB::table('jobs')->sole();
    expect($job->queue)->toBe('media')
        ->and(json_decode($job->payload, true)['displayName'])->toBe(PerformMediaConversions::class)
        ->and(json_decode($job->payload, true)['timeout'])->toBe(50)
        ->and(config('hosting.queue_tick.job_timeout'))->toBeGreaterThanOrEqual(50)
        ->and($photo->hasGeneratedConversion('thumb'))->toBeFalse();

    $started = hrtime(true);
    $result = app(QueueTick::class)->run();
    $seconds = (hrtime(true) - $started) / 1e9;

    $photo = mediaFresh($this->acme, $photo);
    expect($result->stoppedBy)->toBe(TickStopReason::Drained)
        ->and($result->processed['bulk'])->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and($photo->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and($photo->hasGeneratedConversion('large'))->toBeTrue()
        // "Well within": under half the budget on a developer machine.
        ->and($seconds)->toBeLessThan(25.0);

    $thumb = getimagesizefromstring((string) Storage::disk('media')->get($photo->getPathRelativeToRoot('thumb')));
    $large = getimagesizefromstring((string) Storage::disk('media')->get($photo->getPathRelativeToRoot('large')));

    expect($thumb['mime'])->toBe('image/webp')
        ->and([$thumb[0], $thumb[1]])->toBe([400, 300])
        ->and($large['mime'])->toBe('image/webp')
        ->and([$large[0], $large[1]])->toBe([1600, 1200])
        ->and($photo->getPathRelativeToRoot('thumb'))->toStartWith($this->acme->id.'/'.$photo->uuid.'/conversions/');
});

it('converts the largest photo allowed within the budget and the tick\'s memory', function () {
    config(['queue.default' => 'database']);
    $side = (int) floor(sqrt(config('media.max_megapixels') * 1_000_000));
    $photo = uploadMedia($this->acme, mediaImage('huge.jpg', $side, $side));

    gc_collect_cycles();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $started = hrtime(true);
    $result = app(QueueTick::class)->run();
    $seconds = (hrtime(true) - $started) / 1e9;
    $extraMb = (memory_get_peak_usage() - $before) / 1024 / 1024;

    expect($result->processed['bulk'])->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(mediaFresh($this->acme, $photo)->hasGeneratedConversion('large'))->toBeTrue()
        ->and($seconds)->toBeLessThan(25.0)
        // The tick stops at QUEUE_TICK_MEMORY_MB (192 MB by default) for the whole process.
        ->and($extraMb)->toBeLessThan(160.0);
});

it('runs the conversion inside the file\'s organization, with no context of its own', function () {
    config(['queue.default' => 'database']);
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));
    $other = Organization::factory()->create(['slug' => 'other-realty']);
    $theirs = uploadMedia($other, mediaImage('theirs.jpg'));

    expect(app(OrganizationContext::class)->has())->toBeFalse();

    $result = app(QueueTick::class)->run();

    expect($result->processed['bulk'])->toBe(2)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(mediaFresh($this->acme, $photo)->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and(mediaFresh($other, $theirs)->hasGeneratedConversion('thumb'))->toBeTrue()
        ->and(app(OrganizationContext::class)->has())->toBeFalse();
});

it('can run again without harm', function () {
    Queue::fake();
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));
    $job = Queue::pushed(PerformMediaConversions::class)->first();

    $job->handle(app(FileManipulator::class));
    $first = Storage::disk('media')->allFiles();
    $job->handle(app(FileManipulator::class));

    expect(Storage::disk('media')->allFiles())->toBe($first)
        ->and($first)->toHaveCount(3)
        ->and(mediaFresh($this->acme, $photo)->hasGeneratedConversion('large'))->toBeTrue();
});

it('does nothing when the organization is gone', function () {
    Queue::fake();
    $photo = uploadMedia($this->acme, mediaImage('lobby.jpg'));
    $job = Queue::pushed(PerformMediaConversions::class)->first();
    $this->acme->delete();

    expect($job->handle(app(FileManipulator::class)))->toBeTrue()
        ->and(Storage::disk('media')->allFiles())->toBe([$photo->getPathRelativeToRoot()]);
});

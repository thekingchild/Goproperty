<?php

declare(strict_types=1);

use App\Media\CollectionRules;
use App\Media\GenerateMediaResponsiveImages;
use App\Media\Media;
use App\Media\MediaPathGenerator;
use App\Media\PerformMediaConversions;
use App\Media\SignedMediaUrlGenerator;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Image;
use Spatie\MediaLibrary\Conversions\ImageGenerators\Webp;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Observers\MediaObserver;
use Spatie\MediaLibrary\ResponsiveImages\TinyPlaceholderGenerator\Blurred;
use Spatie\MediaLibrary\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;
use Spatie\MediaLibrary\Support\FileNamer\DefaultFileNamer;
use Spatie\MediaLibrary\Support\FileRemover\DefaultFileRemover;

/*
|--------------------------------------------------------------------------
| Media library (spatie/laravel-medialibrary), set up for GoProperty
|--------------------------------------------------------------------------
|
| ARCHITECTURE.md § 9–10. Files go through App\Media\MediaUploads (which
| checks them with App\Media\UploadValidator) onto the private `media`
| disk, are recorded as organization-scoped App\Media\Media rows, and are
| served only through the signed route /media/{organization}/{media}
| (App\Http\Controllers\MediaController). What each collection accepts
| and the conversions are in config/media.php.
|
| Shared hosting (ARCHITECTURE.md § 12.1): conversions are GD only, no
| optimizer binaries (jpegoptim, pngquant, cwebp …: hosts rarely have
| them and they need proc_open), no PDF or video thumbnails, and they run
| as queued jobs on the `media` queue (the in-process queue tick's bulk
| lane), each well within the 50-second job budget.
|
*/

return [

    // The private `media` disk (local) or `media-s3` (config/filesystems.php).
    // Defaults to `media-s3` when the site's files live in S3
    // (FILESYSTEM_DISK=s3, docs/runbooks/shared-hosting.md).
    'disk_name' => env('MEDIA_DISK', env('FILESYSTEM_DISK') === 's3' ? 'media-s3' : 'media'),

    // Conversions sit next to their original.
    'conversions_disk_name' => null,

    // A backstop only: each collection has its own, smaller cap (config/media.php).
    'max_file_size' => 1024 * 1024 * 20,

    // File names are generated from the sniffed type (UploadValidator), so
    // these only matter if code adds a file some other way. The storable
    // extensions are exactly App\Media\CollectionRules::EXTENSIONS.
    'disallowed_extensions' => FileAdder::$defaultDisallowedExtensions,
    'allowed_extensions' => [...array_values(CollectionRules::EXTENSIONS), 'jpeg'],

    // null = the default connection (`database` on shared hosting).
    'queue_connection_name' => env('MEDIA_QUEUE_CONNECTION'),

    // Its own queue, in the queue tick's bulk lane (config/hosting.php).
    'queue_name' => 'media',

    'queue_conversions_by_default' => true,
    'queue_conversions_after_database_commit' => true,

    'media_model' => Media::class,
    'media_observer' => MediaObserver::class,
    'use_default_collection_serialization' => false,

    // Media Library Pro is not installed (null turns its temporary uploads off).
    'temporary_upload_model' => null,
    'enable_temporary_uploads_session_affinity' => true,
    'generate_thumbnails_for_temporary_uploads' => false,

    'file_namer' => DefaultFileNamer::class,

    // {organization id}/{media uuid}/…: unguessable, and one organization's
    // files stay under one prefix.
    'path_generator' => MediaPathGenerator::class,
    'custom_path_generators' => [],

    'file_remover_class' => DefaultFileRemover::class,

    // getUrl() and getTemporaryUrl() give the signed /media route, never a
    // disk or bucket URL.
    'url_generator' => SignedMediaUrlGenerator::class,

    'moves_media_on_update' => false,
    'version_urls' => false,

    // None: the binaries are not on shared hosts. Conversions are also
    // marked nonOptimized() (App\Media\HasOrganizationMedia).
    'image_optimizers' => [],

    // JPEG, PNG, WebP (and GIF) through GD. No SVG, PDF or video.
    'image_generators' => [
        Image::class,
        Webp::class,
    ],

    // null = storage_path('media-library/temp').
    'temporary_directory_path' => null,

    // GD is on every host GoProperty supports; `imagick` where available.
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'gd'),

    'ffmpeg_path' => env('FFMPEG_PATH', '/usr/bin/ffmpeg'),
    'ffprobe_path' => env('FFPROBE_PATH', '/usr/bin/ffprobe'),
    'ffmpeg_timeout' => 900,
    'ffmpeg_threads' => 0,

    // Both run inside the media's organization (OrganizationContext), so
    // the scoped models load in the queue tick too.
    'jobs' => [
        'perform_conversions' => PerformMediaConversions::class,
        'generate_responsive_images' => GenerateMediaResponsiveImages::class,
    ],

    'media_downloader' => DefaultDownloader::class,
    'media_downloader_ssl' => true,

    // Minutes; App\Media\MediaUrls uses config/media.php's url_lifetime_minutes.
    'temporary_url_default_lifetime' => (int) env('MEDIA_URL_LIFETIME', 15),

    'remote' => [
        // Private objects (the media-s3 disk has visibility private); the
        // app streams them, so browsers never cache a bucket URL.
        'extra_headers' => [
            'CacheControl' => 'private, max-age=0',
        ],
    ],

    'responsive_images' => [
        'width_calculator' => FileSizeOptimizedWidthCalculator::class,
        'use_tiny_placeholders' => false,
        'tiny_placeholder_generator' => Blurred::class,
    ],

    'enable_vapor_uploads' => false,
    'default_loading_attribute_value' => 'lazy',
    'prefix' => '',
    'force_lazy_loading' => true,
];

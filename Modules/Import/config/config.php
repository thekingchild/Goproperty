<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Imports (DATA_IMPORT.md § 2, § 4)
|--------------------------------------------------------------------------
|
| Read as config('import.*'). Uploaded workbooks are media of their import
| batch (collection `imports`, config/media.php).
|
*/

return [

    // DATA_IMPORT.md § 4: larger sheets are split by the agency.
    'max_rows_per_sheet' => 10_000,

    // DATA_IMPORT.md § 2 "Undo": a committed batch can be rolled back for
    // this many days, if none of its records has been used since.
    'rollback_days' => 7,

    // DATA_IMPORT.md § 4: uploaded files are kept privately for support,
    // then deleted by the daily purge (the batch and its report stay).
    'keep_uploads_days' => 30,

    // A commit job stops taking new rows after this many seconds and queues
    // the rest of its sheet as a new job, well inside the 50 s a shared
    // host's queue tick gives a job (AGENT.md § 4, config/hosting.php).
    'job_seconds' => 30,

    // Rows written per database transaction during a commit; the batch's
    // progress and resume point are saved in the same transaction.
    'chunk_rows' => 100,

    // What the dry-run report keeps for the screen; the downloadable error
    // workbook always lists every error.
    'preview_rows' => 5,
    'report_issues' => 100,

];

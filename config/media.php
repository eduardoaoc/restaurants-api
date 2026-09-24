<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product Media Disk
    |--------------------------------------------------------------------------
    |
    | The filesystems.php disk product photos/videos are stored on. Kept
    | separate from FILESYSTEM_DISK (the framework's own default) so this
    | can move to S3/a CDN later without touching anything else that
    | happens to use the default disk.
    |
    */

    'product_disk' => env('PRODUCT_MEDIA_DISK', 'public'),

];

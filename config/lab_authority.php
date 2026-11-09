<?php

return [
    // An operator provisions an existing human account at the separate DB cutover.
    // Neither the generic Harness operator nor a caller-supplied label is a fallback.
    'owner_user_id' => env('LAB_OWNER_USER_ID'),
];

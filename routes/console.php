<?php

use Illuminate\Support\Facades\Schedule;

// Publish pending transactional-outbox rows every minute so department processing
// is recovered even if the application crashed after a payment commit.
Schedule::command('outbox:publish')->everyMinute();

// Weekly database maintenance: archive old acknowledged outbox rows and old audit logs.
// Keeps append-heavy tables lean at billion-row scale.
// Adjust --audit-days and --outbox-days to match your data-retention policy.
Schedule::command('db:maintenance --audit-days=365 --outbox-days=90')->weekly();

<?php

declare(strict_types=1);

use App\Kernel;

// Run from the project root, as bin/console does. Web SAPIs chdir to this file's directory, and
// symfonycasts/sass-bundle 0.10.0 names its output after `realpath('<root file basename>')`, which is
// cwd-relative: for `public.scss` the CLI (cwd = project root) hashes `/…/public` (the directory) while
// FPM (cwd = public/) hashes `public`, so a dev page asked for a file `sass:build` never wrote.
// Prod is unaffected (it serves the compiled manifest), but keep this until the bundle fixes it.
chdir(dirname(__DIR__));

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};

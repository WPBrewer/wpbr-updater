<?php

declare(strict_types=1);

/**
 * Strauss entrypoint for this checkout.
 *
 * vendor/bin/strauss loads getcwd()/vendor/autoload.php before its own
 * autoload, then exits when that autoload is the plugin being prefixed.
 * Load the tool autoload first, then start Strauss in the plugin directory.
 *
 * @license GPL-3.0-or-later
 */

require __DIR__ . '/vendor/autoload.php';

$app = new BrianHenryIE\Strauss\Console\Application('0.31.1');
exit($app->run());

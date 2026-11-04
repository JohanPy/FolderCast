<?php

declare(strict_types=1);

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require __DIR__ . '/../vendor/autoload.php';
if ($loader instanceof \Composer\Autoload\ClassLoader) {
    $loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP');
}

// Stubs for internal NC classes/interfaces referenced by OCP contracts
if (!interface_exists('OC\Hooks\Emitter')) {
    eval('namespace OC\Hooks { interface Emitter {} }');
}
if (!class_exists('OC\User\NoUserException')) {
    eval('namespace OC\User { class NoUserException extends \Exception {} }');
}
if (!class_exists('OC')) {
    eval('
        class OC {
            public static $server;
        }
    ');
    \OC::$server = new class {
        public function get(string $class): mixed {
            return new class {
                public function getId(): string { return "test-request-id"; }
                public function getUser(): mixed { return null; }
            };
        }
    };
}

// If the app is executed inside a full Nextcloud checkout, also load the core
// test bootstrap. This keeps standalone CI runs working in this repository.
$nextcloudBootstrap = __DIR__ . '/../../../tests/bootstrap.php';
if (is_file($nextcloudBootstrap)) {
	require_once $nextcloudBootstrap;
}

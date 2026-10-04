<?php

declare(strict_types=1);
use CodeIgniter\Config\DotEnv;

/**
 * paratest 워커별 격리 부트스트랩.
 *
 * TEST_TOKEN 미설정(기존 단일 프로세스 vendor/bin/phpunit)이면 원본 CI4
 * 부트스트랩만 그대로 태워 기존 동작과 완전히 동일하게 둔다.
 *
 * TEST_TOKEN 설정 시(paratest 워커): WRITEPATH를 워커 전용 디렉터리로
 * 앞서 정의하고, 테스트 DB도 워커 전용 스키마로 선점한다. CI4 테스트
 * 부트스트랩(vendor/codeigniter4/framework/system/Test/bootstrap.php)이
 * `defined('WRITEPATH') || define(...)` 가드를 쓰고, CI4 DotEnv::setVariable()도
 * 이미 채워진 $_ENV 값은 덮어쓰지 않는 것에 기대 — 이 두 지점을 우리가
 * 먼저 선점하면 WRITEPATH를 참조하는 모든 코드(플러그인 캐시·업로드·PDF
 * 캐시 등)가 파일 하나 안 고치고 워커별로 격리된다.
 */
$testToken = getenv('TEST_TOKEN');

if ($testToken === false || $testToken === '') {
    require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

    return;
}

$workerRoot = __DIR__ . '/../../writable/paratest/worker_' . $testToken;

foreach (['cache', 'session', 'logs', 'debugbar', 'uploads'] as $subdir) {
    if (! is_dir("{$workerRoot}/{$subdir}")) {
        mkdir("{$workerRoot}/{$subdir}", 0775, true);
    }
}

define('WRITEPATH', realpath($workerRoot) . DIRECTORY_SEPARATOR);

// CI4 부트스트랩이 .env를 로드하기 전에, 이 워커 전용 테스트 DB 이름을
// 먼저 $_ENV에 심어둔다. DotEnv::setVariable()은 $_ENV에 이미 값이 있으면
// .env 값으로 덮어쓰지 않으므로(system/Config/DotEnv.php:95-108) 이후 CI4의
// 정상 .env 로드는 이 값을 그대로 둔다.
require __DIR__ . '/../../vendor/codeigniter4/framework/system/Config/DotEnv.php';
(new DotEnv(__DIR__ . '/../../'))->load();

$baseDatabase   = $_ENV['database.tests.database'] ?? getenv('database.tests.database') ?: 'aicassa_test';
$workerDatabase = $baseDatabase . '_' . $testToken;

$mysqli = new mysqli(
    $_ENV['database.tests.hostname'] ?? getenv('database.tests.hostname') ?: 'localhost',
    $_ENV['database.tests.username'] ?? getenv('database.tests.username') ?: 'root',
    $_ENV['database.tests.password'] ?? getenv('database.tests.password') ?: '',
    '',
    (int) ($_ENV['database.tests.port'] ?? getenv('database.tests.port') ?: 3306),
);
$mysqli->query('CREATE DATABASE IF NOT EXISTS `' . $mysqli->real_escape_string($workerDatabase) . '`');
$mysqli->close();

putenv("database.tests.database={$workerDatabase}");
$_ENV['database.tests.database']    = $workerDatabase;
$_SERVER['database.tests.database'] = $workerDatabase;

require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

<?php

use CodeIgniter\CodingStandard\CodeIgniter4;
use Nexus\CsConfig\Factory;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->files()
    ->in([
        __DIR__ . '/app',
        __DIR__ . '/tests',
    ])
    // 뷰(템플릿)와 Shield 가 발행한 설정은 정규화 대상에서 제외
    ->exclude(['Views'])
    ->notName('Auth.php')
    ->notName('AuthGroups.php')
    ->notName('AuthToken.php');

return Factory::create(new CodeIgniter4())
    ->forProjects()
    ->setFinder($finder)
    ->setUsingCache(true);

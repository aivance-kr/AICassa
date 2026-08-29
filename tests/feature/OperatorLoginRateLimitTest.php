<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class OperatorLoginRateLimitTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->clean();
        $_ENV['operator.id']       = $_SERVER['operator.id'] = 'operator-test';
        $_ENV['operator.password'] = $_SERVER['operator.password'] = password_hash('correct-password', PASSWORD_BCRYPT);
        Factories::reset('config');
    }

    protected function tearDown(): void
    {
        unset($_ENV['operator.id'], $_SERVER['operator.id'], $_ENV['operator.password'], $_SERVER['operator.password']);
        cache()->clean();
        parent::tearDown();
    }

    public function testBlocksRepeatedFailedLoginAttempts(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/operator/login', [
                'operator_id'       => 'operator-test',
                'operator_password' => 'wrong-password',
            ])->assertRedirect();
        }

        $this->post('/operator/login', [
            'operator_id'       => 'operator-test',
            'operator_password' => 'wrong-password',
        ])->assertRedirect();

        $this->assertSame('로그인 시도가 너무 많습니다. 잠시 후 다시 시도하세요.', session()->getFlashdata('error'));
    }
}

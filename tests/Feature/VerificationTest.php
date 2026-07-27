<?php

namespace Tests\Feature;

use App\Services\EmailLookup;
use Tests\TestCase;

class VerificationTest extends TestCase
{
    public function test_form_is_minimal_and_has_no_university_logo(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Vérification d’email');
        $response->assertSee('Redis Bloom');
        $response->assertSee('MySQL');
        $response->assertDontSee('university-logo');
    }

    public function test_mysql_result_is_presented_as_exact(): void
    {
        $this->app->instance(EmailLookup::class, $this->lookup(true));

        $response = $this->post('/verify', [
            'engine' => 'mysql',
            'email' => 'student0000001@example.test',
        ]);

        $response->assertOk();
        $response->assertSee('Email trouvé');
        $response->assertSee('Réponse exacte');
    }

    public function test_redis_positive_is_presented_as_probable(): void
    {
        $this->app->instance(EmailLookup::class, $this->lookup(true));

        $response = $this->post('/verify', [
            'engine' => 'redis',
            'email' => 'student0000001@example.test',
        ]);

        $response->assertOk();
        $response->assertSee('Email probablement présent');
        $response->assertSee('faux positif');
    }

    public function test_invalid_email_is_rejected(): void
    {
        $response = $this->from('/')->post('/verify', [
            'engine' => 'redis',
            'email' => 'not-an-email',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHasErrors('email');
    }

    private function lookup(bool $found): EmailLookup
    {
        return new class($found) extends EmailLookup
        {
            public function __construct(private bool $found) {}

            public function prepare(string $engine): void {}

            public function exists(string $engine, string $email): bool
            {
                return $this->found;
            }
        };
    }
}

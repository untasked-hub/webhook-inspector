<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class Milestone7Test extends TestCase
{
    use RefreshDatabase;

    public function test_managed_cloud_detection_and_explicit_oss_override(): void
    {
        $environment = \Illuminate\Support\Env::getRepository();
        $keys = ['BILLING_ENABLED', 'UNTASKED_STORAGE_URL', 'MANAGED_HOSTING'];
        $original = array_combine($keys, array_map(fn ($key) => $environment->get($key), $keys));
        try {
            foreach ([
                [null, null, null, false],
                [null, 'https://storage.example.invalid', null, true],
                ['true', null, null, true],
                ['false', 'https://storage.example.invalid', 'true', false],
                [null, null, 'true', true],
            ] as [$billing, $storage, $legacy, $expected]) {
                foreach (array_combine($keys, [$billing, $storage, $legacy]) as $key => $value) {
                    $value === null ? $environment->clear($key) : $environment->set($key, $value);
                }
                $settings = require config_path('billing.php');
                $this->assertSame($expected, $settings['managed']);
            }
        } finally {
            foreach ($original as $key => $value) {
                $value === null ? $environment->clear($key) : $environment->set($key, $value);
            }
        }
    }

    public function test_dashboard_is_not_indexed(): void
    {
        $this->actingAs(User::create(['name' => 'Synthetic', 'email' => 'seo@example.invalid', 'password' => bcrypt('long-test-password')]))->get('/dashboard')
            ->assertOk()->assertSee('noindex, nofollow', false);
    }

    public function test_auth_redirects_remain_same_origin_behind_https_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'preview.example.test'])
            ->post('/register', ['name' => 'Synthetic', 'email' => 'synthetic@example.invalid', 'password' => 'long-test-password', 'password_confirmation' => 'long-test-password'])
            ->assertHeader('Location', '/dashboard');
        $this->post('/logout')->assertHeader('Location', '/');
        $this->post('/login', ['email' => 'synthetic@example.invalid', 'password' => 'wrong'])
            ->assertHeader('Location', '/login')->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'synthetic@example.invalid', 'password' => 'long-test-password'])
            ->assertHeader('Location', '/dashboard');
    }

    public function test_public_metadata_is_rendered_without_javascript(): void
    {
        foreach (['/', '/pricing', '/docs', '/self-hosting', '/privacy', '/terms'] as $path) {
            $response = $this->get($path);
            $response->assertOk()->assertSee('application/ld+json', false)
                ->assertSee('SoftwareApplication', false)->assertSee('property="og:title"', false)
                ->assertSee('rel="canonical"', false)->assertSee('name="description"', false);
        }
    }

    public function test_auth_pages_are_not_indexed(): void
    {
        foreach (['/login', '/register'] as $path) {
            $this->get($path)->assertOk()->assertSee('noindex, nofollow', false);
        }
    }
}

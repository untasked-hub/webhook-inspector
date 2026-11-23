<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

$publicPaths = ['/', '/pricing', '/docs', '/self-hosting', '/privacy', '/terms', '/security'];

test('shares null auth user for guests across public routes', function () use ($publicPaths) {
    foreach ($publicPaths as $path) {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
    }
});

test('shares minimal auth identity for signed-in users without exposing email or tokens and sets private no-store cache headers', function () use ($publicPaths) {
    $user = User::create([
        'name' => 'Header Nav User',
        'email' => 'header-nav@example.invalid',
        'password' => 'correct-horse-battery',
    ]);

    foreach ($publicPaths as $path) {
        $response = $this->actingAs($user)
            ->get($path)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.name', 'Header Nav User')
                ->missing('auth.user.email')
                ->missing('auth.user.password')
                ->missing('auth.user.remember_token')
            );

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control', ''));
        expect($cacheControl)->toContain('no-store');
        expect($cacheControl)->toContain('private');
        expect($cacheControl)->not->toContain('public');
    }
});

test('redirects signed-in users away from guest auth routes to dashboard', function () {
    $user = User::create([
        'name' => 'Guest Redirect User',
        'email' => 'guest-redirect@example.invalid',
        'password' => 'correct-horse-battery',
    ]);

    $this->actingAs($user)->get('/login')->assertRedirect('/dashboard');
    $this->actingAs($user)->get('/register')->assertRedirect('/dashboard');
});

test('clears shared auth user on public routes after logout', function () {
    $user = User::create([
        'name' => 'Logout Nav User',
        'email' => 'logout-nav@example.invalid',
        'password' => 'correct-horse-battery',
    ]);

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('auth.user', null));
});

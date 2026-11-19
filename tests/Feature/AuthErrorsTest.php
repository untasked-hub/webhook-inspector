<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('invalid login redirects to /login with errors even without a referer', function () {
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});

test('invalid registration redirects to /register with errors even without a referer', function () {
    $this->post('/register', ['name' => '', 'email' => 'bad', 'password' => 'short', 'password_confirmation' => 'x'])
        ->assertRedirect('/register')
        ->assertSessionHasErrors(['name', 'email', 'password']);
});

test('register then logout then login works', function () {
    $this->post('/register', ['name' => 'A', 'email' => 'a@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertRedirect('/dashboard');
    $this->post('/logout')->assertRedirect('/');
    $this->post('/login', ['email' => 'a@example.com', 'password' => 'correct-horse-battery'])->assertRedirect('/dashboard');
});

test('referrer policy keeps same-origin referer', function () {
    $this->get('/login')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

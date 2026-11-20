<?php

use Inertia\Testing\AssertableInertia as Assert;

it('places the agent quickstart second in the docs with accurate api snippets', function () {
    $this->get('/docs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Info', false)
        ->where('page.sections.1.h', 'Agent & CLI quickstart')
        ->where('page.sections.0.h', 'Quick start')
        ->where('page.sections.1.code', fn ($code) => str_contains($code, '/api/v1/endpoints/$WI_ENDPOINT_ID/requests')
            && str_contains($code, '/api/v1/requests/$REQUEST_ID')
            && str_contains($code, '.payload.body_base64')
            && str_contains($code, 'base64 -d')
            && str_contains($code, 'Authorization: Bearer $WI_API_TOKEN')
            && !preg_match('/wi_[A-Za-z0-9]{64}/', $code)
            && !preg_match('#/c/[A-Za-z0-9]{16,}#', $code))
        ->where('page.sections.1.p', fn ($p) => str_contains(json_encode($p), 'requires read+replay scope')
            && str_contains(json_encode($p), 'requires an authenticated dashboard session')));
});

it('documents only routes that exist with the documented scopes', function () {
    $routes = collect(app('router')->getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri());
    expect($routes->contains(fn ($r) => str_contains($r, 'GET') && str_ends_with($r, 'api/v1/endpoints/{endpoint}/requests')))->toBeTrue();
    expect($routes->contains(fn ($r) => str_contains($r, 'GET') && str_ends_with($r, 'api/v1/requests/{capture}')))->toBeTrue();
    expect($routes->contains(fn ($r) => str_contains($r, 'POST') && str_ends_with($r, 'api/v1/requests/{capture}/replay')))->toBeTrue();
});

it('links the agent quickstart from the landing page source', function () {
    expect(file_get_contents(resource_path('js/Pages/Welcome.vue')))->toContain('href="/docs#section-2"');
});

<?php

namespace Tests\Feature;

use Tests\TestCase;

class ForwardedHostTest extends TestCase
{
    public function test_spoofed_forwarded_host_is_ignored(): void
    {
        $res = $this->withHeaders(['X-Forwarded-Host' => 'evil.example'])->get('/login');
        $res->assertOk();
        $this->assertStringNotContainsString('evil.example', $res->getContent());
    }
}

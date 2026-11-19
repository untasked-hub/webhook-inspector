<?php
namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Models\User;
use App\Support\WebhookSignature;
use Illuminate\Http\Request;

class ReceiverSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_response_and_signature_are_captured_without_exposing_secret(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'receiver@example.invalid', 'password' => bcrypt('long-test-password')]);
        $this->actingAs($user);
        $project = $this->postJson('/api/projects', ['name' => 'Test'])->json('id');
        $id = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Receiver'])->json('id');
        $signingKey = bin2hex(random_bytes(24));
        $settings = ['response_status' => 429, 'response_content_type' => 'text/plain', 'response_body' => 'retry later', 'signature_provider' => 'github', 'signing_secret' => $signingKey];
        $this->putJson("/api/endpoints/$id/receiver-settings", $settings)->assertNoContent();
        $endpoint = DB::table('endpoints')->find($id);
        $this->assertNotSame($signingKey, $endpoint->signing_secret);
        $this->getJson('/api/projects')->assertDontSee('signing_secret');
        $this->getJson("/api/endpoints/$id/receiver-settings")->assertJsonPath('has_signing_secret', true)->assertDontSee($signingKey);
        $this->call('POST', '/c/'.$endpoint->token, [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', 'hello', $signingKey)], 'hello')->assertStatus(429)->assertSee('retry later');
        $capture = DB::table('captures')->first();
        $payload = json_decode(Crypt::decryptString($capture->payload), true);
        $this->assertSame('verified', $payload['signature']['status']);
        $this->assertSame(['[redacted]'], $payload['headers']['x-hub-signature-256']);
        $this->putJson("/api/endpoints/$id/receiver-settings", array_replace($settings, ['response_status' => 302]))->assertUnprocessable();
        $this->actingAs(User::create(['name' => 'Other', 'email' => 'other@example.invalid', 'password' => bcrypt('long-test-password')]));
        $this->getJson("/api/endpoints/$id/receiver-settings")->assertNotFound();
        $this->putJson("/api/endpoints/$id/receiver-settings", $settings)->assertNotFound();
    }

    public function test_no_content_alias_byte_limit_and_zero_secret(): void
    {
        $user = User::create(['name' => 'Synthetic', 'email' => 'boundaries@example.invalid', 'password' => bcrypt('long-test-password')]);
        $this->actingAs($user);
        $project = $this->postJson('/api/projects', ['name' => 'Boundaries'])->json('id');
        $id = $this->postJson('/api/endpoints', ['project_id' => $project, 'name' => 'Receiver'])->json('id');
        $settings = ['response_status' => 204, 'response_content_type' => 'application/json', 'response_body' => 'ignored', 'signature_provider' => 'auto', 'signing_secret' => '0'];
        $this->putJson("/api/endpoints/$id/receiver-settings", $settings)->assertNoContent();
        $endpoint = DB::table('endpoints')->find($id);
        $this->call('POST', '/w/'.$endpoint->token, [], [], [], ['HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', 'body', '0')], 'body')->assertNoContent();
        $payload = json_decode(Crypt::decryptString(DB::table('captures')->value('payload')), true);
        $this->assertSame('verified', $payload['signature']['status']);
        $this->putJson("/api/endpoints/$id/receiver-settings", array_replace($settings, ['response_body' => str_repeat('é', 10000)]))->assertUnprocessable();
        $this->putJson("/api/endpoints/$id/receiver-settings", array_replace($settings, ['signing_secret' => null]))->assertNoContent();
        $this->getJson("/api/endpoints/$id/receiver-settings")->assertJsonPath('has_signing_secret', false);
        $this->assertNull(DB::table('endpoints')->find($id)->signing_secret);
    }

    public function test_signature_modes_and_timestamp_rejection(): void
    {
        $body = '{"ok":true}'; $secret = bin2hex(random_bytes(24)); $now = (string) time();
        $cases = [
            ['stripe', 'Stripe-Signature', 't='.$now.',v1=wrong,v1='.hash_hmac('sha256', $now.'.'.$body, $secret)],
            ['github', 'X-Hub-Signature-256', 'sha256='.hash_hmac('sha256', $body, $secret)],
            ['slack', 'X-Slack-Signature', 'v0='.hash_hmac('sha256', 'v0:'.$now.':'.$body, $secret)],
            ['hmac-sha256', 'X-Webhook-Signature', hash_hmac('sha256', $body, $secret)],
        ];
        foreach ($cases as [$provider, $header, $signature]) {
            $r = Request::create('/'); $r->headers->set($header, $signature); $r->headers->set('X-Slack-Request-Timestamp', $now);
            $this->assertSame('verified', WebhookSignature::verify($r, $body, $secret, $provider)['status']);
            $this->assertSame('verified', WebhookSignature::verify($r, $body, $secret, 'auto')['status']);
            $this->assertSame('invalid', WebhookSignature::verify($r, $body.'changed', $secret, $provider)['status']);
            $this->assertSame('not_configured', WebhookSignature::verify($r, $body, null, $provider)['status']);
            $r->headers->remove($header);
            $this->assertSame('missing', WebhookSignature::verify($r, $body, $secret, $provider)['status']);
        }
        $r = Request::create('/'); $r->headers->set('Stripe-Signature', 't=1,v1='.hash_hmac('sha256', '1.'.$body, $secret));
        $this->assertSame('invalid', WebhookSignature::verify($r, $body, $secret, 'stripe')['status']);
    }
}

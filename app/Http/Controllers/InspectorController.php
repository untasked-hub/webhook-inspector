<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Jobs\DeliverWebhook;
use App\Support\SafeHttp;
use App\Support\ForwardingFilter;
use App\Support\Billing;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Inertia\Inertia;

class InspectorController extends Controller
{
    private function paidPlanIntent(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['developer', 'pro', 'team'], true) ? $value : null;
    }

    private function authRedirectPath(string $base, ?string $plan): string
    {
        return $plan ? $base.'?plan='.rawurlencode($plan) : $base;
    }

    private function postAuthDestination(?string $plan): string
    {
        return $plan && Billing::enabled() ? '/billing/upgrade?plan='.rawurlencode($plan) : '/dashboard';
    }

    public function authPage(Request $request)
    {
        $plan = $this->paidPlanIntent($request->query('plan'));

        return Inertia::render('Auth', [
            'mode' => $request->is('register') ? 'register' : 'login',
            'selected_plan' => $plan,
            'selected_plan_name' => $plan ? config('billing.plans.'.$plan.'.name') : null,
            'billing_enabled' => Billing::enabled(),
            'billing_mode' => Billing::enabled() ? config('billing.stripe_mode') : null,
        ]);
    }

    public function register(Request $request)
    {
        $plan = $this->paidPlanIntent($request->input('plan'));
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), ['name' => 'required|string|max:80', 'email' => 'required|email|max:254|unique:users', 'password' => 'required|string|min:12|max:200|confirmed']);
        if ($validator->fails()) {
            return redirect()->away($this->authRedirectPath('/register', $plan))->withErrors($validator)->withInput($request->except('password', 'password_confirmation'));
        }
        $data = $validator->validated();
        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->away($this->postAuthDestination($plan));
    }

    public function login(Request $request)
    {
        $plan = $this->paidPlanIntent($request->input('plan'));
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), ['email' => 'required|email', 'password' => 'required|string|max:200']);
        if ($validator->fails()) {
            return redirect()->away($this->authRedirectPath('/login', $plan))->withErrors($validator);
        }
        $data = $validator->validated();
        if (! Auth::attempt($data)) {
            return redirect()->away($this->authRedirectPath('/login', $plan))->withErrors(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();
        return redirect()->away($this->postAuthDestination($plan));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->away('/');
    }

    public function dashboard(Request $request)
    {
        return Inertia::render('Dashboard', ['account' => $request->user()->only('name')]);
    }

    public function projects(Request $request)
    {
        $projects = DB::table('projects')->where(function ($q) use ($request) {
            $q->where('user_id', $request->user()->id)->orWhereIn('id', DB::table('project_memberships')->where('user_id', $request->user()->id)->select('project_id'));
        })->orderBy('id')->get();
        $roles = DB::table('project_memberships')->where('user_id', $request->user()->id)->pluck('role', 'project_id');
        $projects->each(function ($project) use ($request, $roles) {
            $project->role = $project->user_id === $request->user()->id ? 'owner' : $roles[$project->id];
            $project->retention_limit = Billing::limits($project->user_id)['retention'];
            unset($project->user_id);
        });
        $endpoints = DB::table('endpoints')->whereIn('project_id', $projects->pluck('id'))->orderBy('id')->get();
        $endpoints->each(function ($endpoint) { unset($endpoint->signing_secret, $endpoint->response_body); });
        return response()->json(['projects' => $projects, 'endpoints' => $endpoints]);
    }

    public function createProject(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:80']);
        abort_if(DB::table('projects')->where('user_id', $request->user()->id)->count() >= 20, 422, 'Project limit reached.');
        $id = DB::table('projects')->insertGetId(['user_id' => $request->user()->id, 'name' => $data['name'], 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id], 201);
    }

    private function ownedProject(Request $request, int $id)
    {
        $project = DB::table('projects')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($project, 404);
        return $project;
    }

    public function renameProject(Request $request, int $id)
    {
        $this->ownedProject($request, $id);
        $data = $request->validate(['name' => 'required|string|max:80']);
        DB::table('projects')->where('id', $id)->update(['name' => $data['name'], 'updated_at' => now()]);
        return response()->json(['id' => $id, 'name' => $data['name']]);
    }

    public function deleteProject(Request $request, int $id)
    {
        $this->ownedProject($request, $id);
        // Endpoints, captures, forward targets and deliveries cascade via foreign keys.
        DB::table('projects')->where('id', $id)->delete();
        return response()->noContent();
    }

    public function analytics(Request $request)
    {
        $data = $request->validate([
            'days' => 'nullable|integer|min:1|max:90',
            'project_id' => 'nullable|integer|min:1',
        ]);
        $days = (int) ($data['days'] ?? 14);
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        $userId = $request->user()->id;
        if ($projectId !== null) {
            \App\Support\ProjectAccess::authorize($request, $projectId);
        }
        $since = now()->startOfDay()->subDays($days - 1);
        $memberRoles = DB::table('project_memberships')->where('user_id', $userId)->pluck('role', 'project_id');
        $endpointsQuery = DB::table('endpoints')
            ->join('projects', 'projects.id', '=', 'endpoints.project_id')
            ->where(function ($q) use ($userId, $memberRoles) {
                $q->where('projects.user_id', $userId);
                if ($memberRoles->isNotEmpty()) {
                    $q->orWhereIn('projects.id', $memberRoles->keys()->all());
                }
            });
        if ($projectId !== null) {
            $endpointsQuery->where('projects.id', $projectId);
        }
        $endpoints = $endpointsQuery
            ->select('endpoints.id', 'endpoints.name', 'endpoints.retention_days', 'projects.id as project_id', 'projects.name as project', 'projects.user_id as owner_id')
            ->orderBy('endpoints.id')
            ->get();
        $ids = $endpoints->pluck('id');
        $rows = $ids->isEmpty()
            ? collect()
            : DB::table('captures')->whereIn('endpoint_id', $ids)->where('received_at', '>=', $since)->get(['endpoint_id', 'received_at']);
        $daily = $rows->groupBy(fn ($r) => substr((string) $r->received_at, 0, 10))->map->count();
        $perEndpoint = $rows->groupBy('endpoint_id')->map->count();
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $since->copy()->addDays($i)->format('Y-m-d');
            $series[] = ['day' => $d, 'requests' => (int) ($daily[$d] ?? 0)];
        }
        $deliveries = $ids->isEmpty()
            ? collect()
            : DB::table('deliveries')->whereIn('endpoint_id', $ids)->where('created_at', '>=', $since)
                ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v);
        $period = now()->format('Y-m');
        $limits = Billing::limits($userId);
        $ownerRetention = [];
        foreach ($endpoints->pluck('owner_id')->unique() as $ownerId) {
            $ownerRetention[$ownerId] = Billing::limits((int) $ownerId)['retention'];
        }
        return response()->json([
            'days' => $days,
            'project_id' => $projectId,
            'daily' => $series,
            'endpoints' => $endpoints->map(fn ($e) => [
                'id' => $e->id,
                'project_id' => $e->project_id,
                'name' => $e->name,
                'project' => $e->project,
                'role' => $e->owner_id === $userId ? 'owner' : ($memberRoles[$e->project_id] ?? 'viewer'),
                'retention_days' => $e->retention_days,
                'retention_limit' => $ownerRetention[$e->owner_id] ?? $limits['retention'],
                'requests' => (int) ($perEndpoint[$e->id] ?? 0),
            ])->values(),
            'deliveries' => (object) $deliveries->all(),
            'usage' => ['period' => $period, 'requests' => (int) DB::table('usage_counters')->where('user_id', $userId)->where('period', $period)->value('requests_count'), 'limit' => $limits['requests'], 'managed' => (bool) config('billing.managed')],
            'note' => 'Daily and per-endpoint counts cover retained captures across your owned and shared projects; monthly usage counts accepted requests billed to your account.',
        ])->header('Cache-Control', 'no-store');
    }

    public function createEndpoint(Request $request)
    {
        $data = $request->validate(['project_id' => 'required|integer', 'name' => 'required|string|max:80']);
        $project = \App\Support\ProjectAccess::authorize($request, $data['project_id'], true);
        $maxRetention = Billing::limits((int) $project->user_id)['retention'];
        $retentionData = $request->validate(['retention_days' => 'sometimes|integer|min:1|max:'.$maxRetention]);
        abort_if(DB::table('endpoints')->where('project_id', $project->id)->count() >= 20, 422, 'Endpoint limit reached.');
        $defaultRetention = min(7, $maxRetention);
        $id = DB::table('endpoints')->insertGetId(['project_id' => $project->id, 'name' => $data['name'], 'token' => Str::random(48), 'retention_days' => $retentionData['retention_days'] ?? $defaultRetention, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['id' => $id], 201);
    }

    private function ownedEndpoint(Request $request, int $id)
    {
        $endpoint = DB::table('endpoints')->join('projects', 'projects.id', '=', 'endpoints.project_id')
            ->where('endpoints.id', $id)
            ->select('endpoints.*')->first();
        abort_unless($endpoint, 404);
        \App\Support\ProjectAccess::authorize($request, $endpoint->project_id, !$request->isMethod('GET'));
        return $endpoint;
    }

    public function receiverSettings(Request $request, int $id)
    {
        $endpoint = $this->ownedEndpoint($request, $id);
        if ($request->isMethod('PUT')) {
            $data = $request->validate([
                'response_status' => 'required|integer|in:200,201,202,204,400,401,403,404,409,422,429,500,502,503',
                'response_content_type' => 'required|in:application/json,text/plain',
                'response_body' => 'nullable|string|max:16384',
                'signature_provider' => 'required|in:auto,hmac-sha256,stripe,github,slack',
                'signing_secret' => 'sometimes|nullable|string|max:4096',
            ]);
            if (strlen($data['response_body'] ?? '') > 16384) throw ValidationException::withMessages(['response_body' => 'Response body must not exceed 16 KiB.']);
            if (array_key_exists('signing_secret', $data)) $data['signing_secret'] = $data['signing_secret'] !== null && $data['signing_secret'] !== '' ? Crypt::encryptString($data['signing_secret']) : null;
            // Bodies are sensitive configuration as well as captured data.
            $data['response_body'] = Crypt::encryptString($data['response_body'] ?? '');
            DB::table('endpoints')->where('id', $id)->update($data + ['updated_at' => now()]);
            return response()->noContent();
        }
        return response()->json([
            'response_status' => $endpoint->response_status,
            'response_content_type' => $endpoint->response_content_type,
            'response_body' => $endpoint->response_body === null ? '{"received":true}' : Crypt::decryptString($endpoint->response_body),
            'signature_provider' => $endpoint->signature_provider,
            'has_signing_secret' => $endpoint->signing_secret !== null,
        ])->header('Cache-Control', 'no-store');
    }

    public function deleteEndpoint(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        DB::table('endpoints')->where('id', $id)->delete();
        return response()->noContent();
    }

    public function retention(Request $request, int $id)
    {
        $endpoint = $this->ownedEndpoint($request, $id);
        $owner = DB::table('projects')->where('id', $endpoint->project_id)->value('user_id');
        $data = $request->validate(['retention_days' => 'required|integer|min:1|max:'.Billing::limits($owner)['retention']]);
        DB::transaction(function () use ($id, $data) {
            DB::table('endpoints')->where('id', $id)->update(['retention_days' => $data['retention_days'], 'updated_at' => now()]);
            // Reducing retention applies to existing records, not only future captures.
            $rows = DB::table('captures')->where('endpoint_id', $id)->get(['id', 'received_at', 'expires_at']);
            foreach ($rows as $row) {
                $expiry = \Illuminate\Support\Carbon::parse($row->received_at)->addDays($data['retention_days']);
                if ($expiry->lessThan(\Illuminate\Support\Carbon::parse($row->expires_at))) {
                    DB::table('captures')->where('id', $row->id)->update(['expires_at' => $expiry]);
                }
            }
        });
        return response()->noContent();
    }

    public function forwardTarget(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        $target = DB::table('forward_targets')->where('endpoint_id', $id)->first();
        if ($target) {
            $latest = DB::table('deliveries')->where('endpoint_id', $id)->where('kind', 'forward')
                ->where('target_url', $target->url)->where('attempt_count', '>', 0)
                ->orderByDesc('id')->first(['status']);
            $target->health = match (true) {
                ! $target->enabled => 'off',
                $target->disabled_until && \Illuminate\Support\Carbon::parse($target->disabled_until)->isFuture() => 'paused',
                $target->consecutive_failures > 0 || in_array($latest?->status, ['blocked', 'failed', 'retrying'], true) => 'degraded',
                $latest?->status === 'succeeded' => 'healthy',
                default => 'unverified',
            };
            $target->url = SafeHttp::displayUrl($target->url);
            $target->filter = $target->filter ? json_decode(Crypt::decryptString($target->filter), true, 8, JSON_THROW_ON_ERROR) : null;
        }
        return response()->json(['target' => $target])->header('Cache-Control', 'no-store');
    }

    public function saveForwardTarget(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        $data = $request->validate([
            'url' => 'nullable|string|max:2048', 'enabled' => 'required|boolean',
            'filter' => 'sometimes|nullable|array:method,header,header_value,json_path,json_value',
            'filter.method' => 'nullable|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS',
            'filter.header' => ['required_with:filter.header_value', 'nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9_-]+\z/', 'not_regex:/authorization|cookie|token|secret|api[-_]key|signature/i'],
            'filter.header_value' => 'required_with:filter.header|nullable|string|max:512',
            'filter.json_path' => ['required_with:filter.json_value', 'nullable', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]+)*\z/'],
            'filter.json_value' => 'required_with:filter.json_path|nullable|string|max:512',
        ]);
        $filter = array_filter($data['filter'] ?? [], fn ($value) => $value !== null);
        $url = $data['url'] ?? null;
        if ($url !== null) $this->validateDestination($url);
        DB::transaction(function () use ($id, $data, $filter, $url) {
            DB::table('endpoints')->where('id', $id)->lockForUpdate()->first();
            $existing = DB::table('forward_targets')->where('endpoint_id', $id)->lockForUpdate()->first();
            if (! $existing && $url === null) {
                throw ValidationException::withMessages(['url' => 'A destination URL is required.']);
            }
            $sameDestination = $existing && ($url === null || SafeHttp::destination($existing->url) === $url);
            $attributes = [
                'url' => $sameDestination ? $existing->url : Crypt::encryptString($url),
                'enabled' => $data['enabled'],
                'filter' => ! array_key_exists('filter', $data)
                    ? $existing?->filter
                    : ($filter ? Crypt::encryptString(json_encode($filter, JSON_THROW_ON_ERROR)) : null),
                'updated_at' => now(),
            ];
            if (! $sameDestination) {
                $attributes += ['consecutive_failures' => 0, 'disabled_until' => null];
            }
            if ($existing) {
                DB::table('forward_targets')->where('id', $existing->id)->update($attributes);
            } else {
                DB::table('forward_targets')->insert($attributes + ['endpoint_id' => $id, 'created_at' => now()]);
            }
        });
        return response()->noContent();
    }

    private function validateDestination(string $url): void
    {
        try {
            SafeHttp::validateTarget($url);
            if (parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) throw new \RuntimeException('Configured destinations must not include query strings or fragments. Use a dedicated public receiver path.');
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['url' => SafeHttp::sanitizeErrorMessage($e->getMessage())]);
        }
    }

    public function replay(Request $request, int $id, int $capture)
    {
        $this->ownedEndpoint($request, $id);
        $data = $request->validate(['url' => 'required|string|max:2048', 'confirm' => 'required|accepted']);
        $this->validateDestination($data['url']);
        abort_unless(DB::table('captures')->where('endpoint_id', $id)->where('id', $capture)->where('expires_at', '>', now())->exists(), 404);
        $deliveryId = DB::table('deliveries')->insertGetId([
            'endpoint_id' => $id, 'capture_id' => $capture, 'kind' => 'replay', 'target_url' => Crypt::encryptString($data['url']),
            'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DeliverWebhook::dispatch($deliveryId)->afterCommit();
        return response()->json(['id' => $deliveryId], 202);
    }

    public function bulkReplay(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        $data = $request->validate(['url' => 'required|string|max:2048', 'confirm' => 'required|accepted', 'ids' => 'sometimes|required|array|min:1|max:20', 'ids.*' => 'required|integer|min:1|distinct']);
        $this->validateDestination($data['url']);
        if (isset($data['ids'])) {
            $ids = DB::table('captures')->where('endpoint_id', $id)->where('expires_at', '>', now())->whereIn('id', $data['ids'])->orderBy('id')->pluck('id');
            abort_unless($ids->count() === count($data['ids']), 422, 'Selection contains unavailable requests. Nothing was queued.');
        } else {
            // Use exactly the same bounded filters and encrypted search semantics as history.
            $filtered = clone $request;
            $filtered->query->remove('format');
            $filtered->request->remove('format');
            $rows = $this->history($filtered, $id)->getData()->requests;
            $ids = collect($rows)->pluck('id');
            abort_if($ids->isEmpty() || $ids->count() > 20, 422, 'Filtered replay requires 1–20 matches. Narrow filters or select requests. Nothing was queued.');
        }
        $deliveries = DB::transaction(function () use ($id, $ids, $data) {
            return $ids->map(function ($capture) use ($id, $data) {
                $delivery = DB::table('deliveries')->insertGetId(['endpoint_id' => $id, 'capture_id' => $capture, 'kind' => 'replay', 'target_url' => Crypt::encryptString($data['url']), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                DeliverWebhook::dispatch($delivery)->afterCommit();
                return $delivery;
            });
        });
        return response()->json(['ids' => $deliveries, 'count' => $deliveries->count()], 202)->header('Cache-Control', 'no-store');
    }

    public function retryDelivery(Request $request, int $id, int $delivery)
    {
        $this->ownedEndpoint($request, $id);
        $request->validate(['confirm' => 'required|accepted']);
        $source = DB::table('deliveries')->where('endpoint_id', $id)->where('id', $delivery)->first();
        abort_unless($source, 404);
        abort_unless($source->status === 'failed', 409, 'Only failed deliveries can be retried.');
        abort_unless(DB::table('captures')->where('endpoint_id', $id)->where('id', $source->capture_id)->where('expires_at', '>', now())->exists(), 410, 'Captured request expired or was deleted.');
        $this->validateDestination(SafeHttp::destination($source->target_url));
        // A new delivery preserves the original attempt log and gives this explicit retry its own bounded budget.
        $retryId = DB::table('deliveries')->insertGetId([
            'endpoint_id' => $id, 'capture_id' => $source->capture_id, 'kind' => $source->kind,
            'target_url' => $source->target_url, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DeliverWebhook::dispatch($retryId)->afterCommit();
        return response()->json(['id' => $retryId], 202);
    }

    public function deliveries(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        $deliveries = DB::table('deliveries')->where('endpoint_id', $id)->orderByDesc('id')->limit(100)->get();
        foreach ($deliveries as $delivery) $delivery->target_url = SafeHttp::displayUrl($delivery->target_url);
        $attempts = DB::table('delivery_attempts')->whereIn('delivery_id', $deliveries->pluck('id'))->orderBy('attempt_number')->get()->groupBy('delivery_id');
        return response()->json(['deliveries' => $deliveries, 'attempts' => $attempts])->header('Cache-Control', 'no-store');
    }

    public function capture(Request $request, string $token)
    {
        $endpoint = DB::table('endpoints')->where('token', $token)->first();
        abort_unless($endpoint, 404);
        abort_if((int) $request->header('content-length', 0) > 1048576, 413, 'Payload limit is 1 MiB.');
        $stream = fopen('php://input', 'rb');
        // Tests supply content through Symfony rather than php://input.
        $body = app()->runningUnitTests() ? $request->getContent() : stream_get_contents($stream, 1048577);
        fclose($stream);
        abort_if(strlen($body) > 1048576, 413, 'Payload limit is 1 MiB.');
        $key = 'capture:'.$endpoint->id;
        abort_if(RateLimiter::tooManyAttempts($key, 120), 429, 'Endpoint rate limit reached.');
        RateLimiter::hit($key, 60);
        $signature = \App\Support\WebhookSignature::verify($request, $body, $endpoint->signing_secret ? Crypt::decryptString($endpoint->signing_secret) : null, $endpoint->signature_provider);
        $headers = $request->headers->all();
        foreach (array_keys($headers) as $name) {
            if (preg_match('/authorization|cookie|token|secret|api[-_]key|signature/i', $name)) {
                $headers[$name] = ['[redacted]'];
            }
        }
        $query = SafeHttp::sanitizeQuery($request->query());
        abort_if(strlen(json_encode([$headers, $query])) > 65536, 413, 'Metadata limit reached.');
        $userId = DB::table('projects')->where('id', $endpoint->project_id)->value('user_id');
        $stored = DB::transaction(function () use ($endpoint, $request, $body, $headers, $query, $userId, $signature) {
            DB::table('users')->where('id', $userId)->lockForUpdate()->first();
            DB::table('endpoints')->where('id', $endpoint->id)->lockForUpdate()->first();
            if (!Billing::meter($userId)) return false;
            DB::table('captures')->where('endpoint_id', $endpoint->id)->where('expires_at', '<=', now())->delete();
            // History is retention-based; deleting records must not reset monthly usage.
            $captureId = DB::table('captures')->insertGetId(['endpoint_id' => $endpoint->id, 'method' => $request->method(), 'content_type' => Str::limit($request->header('content-type', 'application/octet-stream'), 200, ''), 'size' => strlen($body), 'payload' => Crypt::encryptString(json_encode(['body_base64' => base64_encode($body), 'headers' => $headers, 'query' => $query, 'signature' => $signature], JSON_INVALID_UTF8_SUBSTITUTE)), 'received_at' => now(), 'expires_at' => now()->addDays(min($endpoint->retention_days, Billing::limits($userId)['retention']))]);
            $target = DB::table('forward_targets')->where('endpoint_id', $endpoint->id)->where('enabled', true)->first();
            if ($target && (! $target->disabled_until || \Illuminate\Support\Carbon::parse($target->disabled_until)->isPast())
                && (! $target->filter || ForwardingFilter::matches(json_decode(Crypt::decryptString($target->filter), true, 8, JSON_THROW_ON_ERROR), $request->method(), $headers, $body))) {
                $deliveryId = DB::table('deliveries')->insertGetId([
                    'endpoint_id' => $endpoint->id, 'capture_id' => $captureId, 'kind' => 'forward',
                    'target_url' => $target->url, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
                ]);
                DeliverWebhook::dispatch($deliveryId)->afterCommit();
            }
            return true;
        });
        abort_unless($stored, 429, 'Monthly request limit reached. Upgrade your plan or wait for the next UTC month.');
        return response($endpoint->response_status == 204 ? '' : ($endpoint->response_body === null ? '{"received":true}' : Crypt::decryptString($endpoint->response_body)), $endpoint->response_status)
            ->header('Content-Type', $endpoint->response_content_type)->header('X-Content-Type-Options', 'nosniff')->header('Cache-Control', 'no-store');
    }

    public function history(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        $data = $request->validate(['method' => 'nullable|in:GET,POST,PUT,PATCH,DELETE,HEAD,OPTIONS', 'before' => 'nullable|integer|min:1', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'content_type' => 'nullable|string|max:200', 'min_size' => 'nullable|integer|min:0|max:1048576', 'max_size' => 'nullable|integer|min:0|max:1048576', 'format' => 'nullable|in:json,ndjson,curl,csv', 'q' => 'nullable|string|max:200', 'status' => 'nullable|in:none,queued,retrying,succeeded,failed,blocked']);
        $query = DB::table('captures')->where('endpoint_id', $id)->where('expires_at', '>', now());
        if (!empty($data['method'])) $query->where('method', $data['method']);
        if (!empty($data['before'])) $query->where('id', '<', $data['before']);
        foreach (['from' => ['received_at', '>='], 'to' => ['received_at', '<='], 'min_size' => ['size', '>='], 'max_size' => ['size', '<='], 'content_type' => ['content_type', '=']] as $key => [$column, $op]) {
            if (isset($data[$key])) $query->where($column, $op, $data[$key]);
        }
        if (!empty($data['status'])) {
            // Filter by delivery outcome of a capture ("none" = never forwarded or replayed).
            if ($data['status'] === 'none') {
                $query->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('deliveries')->whereColumn('deliveries.capture_id', 'captures.id'));
            } else {
                $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('deliveries')->whereColumn('deliveries.capture_id', 'captures.id')->where('deliveries.status', $data['status']));
            }
        }
        if (!empty($data['q'])) {
            // Payloads are encrypted at rest, so text search decrypts a bounded window (newest 1,000 filtered rows).
            $needle = mb_strtolower($data['q']);
            $rows = $query->orderByDesc('id')->limit(1000)->get()->filter(function ($row) use ($needle) {
                $payload = json_decode(Crypt::decryptString($row->payload), true) ?: [];
                $haystack = mb_strtolower(base64_decode($payload['body_base64'] ?? '').' '.json_encode($payload['headers'] ?? []).' '.json_encode($payload['query'] ?? []));
                return str_contains($haystack, $needle);
            })->take(100)->values();
        } else {
            $rows = $query->orderByDesc('id')->limit(100)->get();
        }
        if (($data['format'] ?? null) === 'csv') {
            $out = fopen('php://temp', 'r+');
            fputcsv($out, ['id', 'method', 'content_type', 'size', 'received_at', 'query', 'headers', 'body_base64']);
            foreach ($rows as $row) {
                $payload = json_decode(Crypt::decryptString($row->payload), true) ?: [];
                $cells = [$row->id, $row->method, $row->content_type, $row->size, $row->received_at, json_encode(SafeHttp::sanitizeQuery($payload['query'] ?? [])), json_encode($payload['headers'] ?? []), $payload['body_base64'] ?? ''];
                // Neutralise spreadsheet formula injection.
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $cells));
            }
            rewind($out);
            $csv = stream_get_contents($out);
            fclose($out);
            return response($csv)->header('Content-Type', 'text/csv; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="webhooks.csv"')->header('X-Export-Limit', '100')->header('Cache-Control', 'no-store');
        }
        if (isset($data['format'])) {
            $records = $rows->map(function ($row) {
                $payload = json_decode(Crypt::decryptString($row->payload), true);
                $payload['query'] = SafeHttp::sanitizeQuery($payload['query'] ?? []);
                unset($row->payload);
                return ['request' => $row, 'payload' => $payload];
            });
            $format = $data['format'];
            $body = $format === 'json' ? json_encode($records, JSON_INVALID_UTF8_SUBSTITUTE) : $records->map(function ($record) use ($format) {
                if ($format === 'ndjson') return json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE);
                // Deliberately use a placeholder destination. Export never contains bearer receive URLs.
                $quote = fn ($v) => "'".str_replace("'", "'\\''", $v)."'";
                $url = SafeHttp::replayUrl('https://example.invalid/webhook', $record['payload']['query'] ?? []);
                return 'printf %s '.$quote($record['payload']['body_base64'] ?? '').' | base64 -d | curl --request '.$quote($record['request']->method).' --data-binary @- '.$quote($url);
            })->implode("\n");
            return response($body)->header('Content-Type', $format === 'json' ? 'application/json' : 'text/plain; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="webhooks.'.$format.'"')->header('X-Export-Limit', '100')->header('X-Next-Before', $rows->count() === 100 ? (string) $rows->last()->id : '')->header('Cache-Control', 'no-store');
        }
        foreach ($rows as $row) unset($row->payload);
        return response()->json(['requests' => $rows, 'next_before' => $rows->count() === 100 ? $rows->last()->id : null])->header('Cache-Control', 'no-store');
    }

    public function detail(Request $request, int $id, int $capture)
    {
        $this->ownedEndpoint($request, $id);
        $row = DB::table('captures')->where('endpoint_id', $id)->where('id', $capture)->where('expires_at', '>', now())->first();
        abort_unless($row, 404);
        $payload = json_decode(Crypt::decryptString($row->payload), true);
        unset($row->payload);
        return response()->json(['request' => $row, 'payload' => $payload])->header('Cache-Control', 'no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function clear(Request $request, int $id)
    {
        $this->ownedEndpoint($request, $id);
        DB::table('captures')->where('endpoint_id', $id)->delete();
        return response()->noContent();
    }
}

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @php
            $metadata = [
                '/' => ['Webhook Inspector — Inspect, debug and replay webhooks', 'Inspect, debug and replay webhooks in seconds. Open source, self-hostable, or fully managed.'],
                'pricing' => ['Pricing — Webhook Inspector', 'Compare managed webhook hosting plans or self-host the open-source edition.'],
                'docs' => ['Documentation — Webhook Inspector', 'Learn to capture, inspect, forward and replay HTTP webhooks.'],
                'self-hosting' => ['Self-hosting — Webhook Inspector', 'Deploy the open-source webhook inspector with Docker and PostgreSQL.'],
                'privacy' => ['Privacy policy — Webhook Inspector', 'How Webhook Inspector handles sensitive webhook data. Draft pending legal review.'],
                'terms' => ['Terms of service — Webhook Inspector', 'Terms for using Webhook Inspector. Draft pending legal review.'],
            ];
            $seo = $metadata[request()->path()] ?? ['Webhook Inspector', 'Your private webhook workspace.'];
            $publicPage = isset($metadata[request()->path()]);
            $canonical = request()->url();
        @endphp
        <title inertia>{{ $seo[0] }}</title>
        <meta name="description" content="{{ $seo[1] }}">
        @if($publicPage)
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:title" content="{{ $seo[0] }}">
        <meta property="og:description" content="{{ $seo[1] }}">
        <meta property="og:type" content="website">
        <meta property="og:url" content="{{ $canonical }}">
        <script type="application/ld+json">{!! json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Webhook Inspector', 'applicationCategory' => 'DeveloperApplication', 'operatingSystem' => 'Web, Docker', 'url' => $canonical, 'description' => $seo[1]], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
        @else
        <meta name="robots" content="noindex, nofollow">
        @endif
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

<?php

use App\Filesystem\UntaskedStorageAdapter;
use Illuminate\Support\Facades\Http;

test('put stores an object', function () {
    Http::fake(['https://storage.test/objects/*' => Http::response('ok', 200)]);
    $adapter = new UntaskedStorageAdapter('https://storage.test', 'token');

    $adapter->put('notes/a.txt', 'hello');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/objects/notes/a.txt'));
});

test('get reads an object', function () {
    Http::fake([
        'https://storage.test/objects/notes/a.txt' => Http::response('hello', 200),
    ]);

    $adapter = new UntaskedStorageAdapter('https://storage.test', 'token');

    expect($adapter->get('notes/a.txt'))->toBe('hello');
});

test('delete removes an object', function () {
    Http::fake(['https://storage.test/objects/*' => Http::response('ok', 200)]);
    $adapter = new UntaskedStorageAdapter('https://storage.test', 'token');

    $adapter->delete('notes/a.txt');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/objects/notes/a.txt'));
});

test('put throws when storage rejects the write', function () {
    Http::fake([
        'https://storage.test/objects/*' => Http::response('nope', 500),
    ]);

    $adapter = new UntaskedStorageAdapter('https://storage.test', 'token');

    expect(fn () => $adapter->put('x', 'y'))->toThrow(RuntimeException::class);
});

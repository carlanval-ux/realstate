<?php

use Livewire\Mechanisms\HandleRequests\EndpointResolver;

beforeEach(fn () => config(['app.staff_only' => true]));

it('sends guests to the panel login', function () {
    $this->get('/properties')->assertRedirect('/admin/login');
});

it('has a single login screen (Fortify /login goes to the panel login)', function () {
    $this->get('/login')->assertRedirect('/admin/login');
});

// Livewire 4 serves its endpoint under a hashed prefix (/livewire-xxxx/update,
// route "default-livewire.update"). Blocking it made the login form bounce.
it('lets guests reach the Livewire endpoint so the login form can submit', function () {
    $response = $this->postJson(EndpointResolver::updatePath(), []);

    expect($response->headers->get('Location'))->not->toContain('/admin/login');
    expect($response->status())->not->toBe(401);
});

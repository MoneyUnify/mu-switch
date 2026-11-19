<?php

test('the health check answers in html for hosts and browsers', function () {
    $this->get('/up')
        ->assertOk()
        ->assertSee('Application up');
});

test('the health check answers in json for monitors', function () {
    $this->getJson('/up')
        ->assertOk()
        ->assertExactJson(['status' => 'up']);
});

<?php

it('reports up when the database and redis respond', function () {
    $this->getJson('/up')->assertOk()->assertJson(['status' => 'up']);
});

it('reports down when redis is unreachable', function () {
    config()->set('app.debug', false);
    config()->set('database.redis.default.host', '127.0.0.1');
    config()->set('database.redis.default.port', 1);
    app('redis')->purge('default');

    $this->getJson('/up')->assertStatus(500)->assertJson(['status' => 'down']);
});

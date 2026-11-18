<?php

// /office and /pos send the browser to /office/ and /pos/ (the web server serves the apps there). Laravel's redirect()
// drops a final "/", which sent /office back to /office: "redirected you too many times" on a real server.

it('sends /office and /pos to their address with the final slash', function () {
    expect($this->get('/office')->assertRedirect()->headers->get('Location'))->toEndWith('/office/')
        ->and($this->get('/pos')->assertRedirect()->headers->get('Location'))->toEndWith('/pos/');
});

it('signs in and comes back to /office/, not /office', function () {
    [, $owner] = installCompany();
    $this->actingAs(withMfa($owner), 'web');
    expect($this->get('/office-signin')->assertRedirect()->headers->get('Location'))->toEndWith('/office/')
        ->and($this->get('/office-signin?to=/office/customers')->headers->get('Location'))->toEndWith('/office/customers');
});

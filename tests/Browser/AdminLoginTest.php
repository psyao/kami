<?php

declare(strict_types=1);

it('renders the admin login page without JavaScript errors', function () {
    visit('/admin/login')
        ->assertSee('Sign in')
        ->assertNoJavaScriptErrors();
});

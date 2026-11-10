<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psyao\Kami\Forms\Submission;

uses(RefreshDatabase::class);

it('reads the Submissions, their list and one of them, in light and dark', function () {
    $submission = Submission::query()->create(['site' => 'default', 'locale' => 'fr', 'form' => 'contact', 'data' => ['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'message' => "Bonjour,\nUn projet ?"]]);
    DB::table('kami_form_deliveries')->insert(['id' => (string) Str::ulid(), 'submission_id' => $submission->id, 'channel' => 'mail', 'attempts' => 5, 'last_error' => 'Connection refused', 'created_at' => now(), 'updated_at' => now()]);

    signIn()
        ->navigate('/admin/submissions')
        ->assertSee('Grace Hopper')
        ->assertSee('Failed')
        ->assertNoAccessibilityIssues()
        ->click('Grace Hopper')
        ->assertSee('Connection refused')
        ->assertSee('Reply')
        ->assertNoAccessibilityIssues()
        ->assertNoJavaScriptErrors();

    visit('/admin/submissions')->inDarkMode()->assertSee('Grace Hopper')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
    visit("/admin/submissions/{$submission->id}")->inDarkMode()->assertSee('Connection refused')->assertNoAccessibilityIssues()->assertNoJavaScriptErrors();
});

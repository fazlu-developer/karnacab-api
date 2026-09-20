<?php

namespace Tests\Feature;

use App\Mail\WebsiteLeadMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WebsiteLeadApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('leads', function ($table) {
            $table->id();
            $table->string('type');
            $table->string('status')->default('NEW');
            $table->string('name');
            $table->string('phone', 20);
            $table->string('email', 180)->nullable();
            $table->string('district', 80)->nullable();
            $table->text('message');
            $table->text('payload')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        config(['karnacab.leads_notify_email' => 'ops@karnacab.test']);
    }

    public function test_public_lead_is_stored_and_emailed(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/leads', [
            'type' => 'CORPORATE',
            'name' => 'Rakesh',
            'phone' => '9876543210',
            'email' => 'rakesh@example.com',
            'district' => 'Patna',
            'message' => 'Need 12 cabs for office pickup.',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'NEW')
            ->assertJsonPath('type', 'CORPORATE');

        $this->assertDatabaseHas('leads', [
            'name' => 'Rakesh',
            'type' => 'CORPORATE',
            'status' => 'NEW',
        ]);

        Mail::assertSent(WebsiteLeadMail::class, function (WebsiteLeadMail $mail) {
            return $mail->lead->name === 'Rakesh';
        });
    }
}

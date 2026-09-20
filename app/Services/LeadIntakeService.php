<?php

namespace App\Services;

use App\Mail\WebsiteLeadMail;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class LeadIntakeService
{
    public const TYPES = [
        'RIDE', 'PARCEL', 'BIHAR_PARCEL', 'TRAVEL', 'BULK', 'CORPORATE',
        'FRANCHISE', 'FLEET', 'ADVERTISE', 'SUPPORT',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function capture(array $data): Lead
    {
        $type = strtoupper((string) ($data['type'] ?? 'SUPPORT'));
        if (! in_array($type, self::TYPES, true)) {
            $type = 'SUPPORT';
        }

        $lead = Lead::query()->create([
            'type' => $type,
            'status' => 'NEW',
            'name' => $data['name'],
            'phone' => (string) ($data['phone'] ?: '—'),
            'email' => $data['email'] ?? null,
            'district' => $data['district'] ?? null,
            'message' => $data['message'],
            'payload' => $data['payload'] ?? null,
        ]);

        $this->emailOps($lead);
        $this->notifyAdmins($lead);

        return $lead;
    }

    public function emailOps(Lead $lead): void
    {
        $recipients = $this->recipients();
        if ($recipients === []) {
            return;
        }

        try {
            Mail::to($recipients)->send(new WebsiteLeadMail($lead));
        } catch (\Throwable $exception) {
            Log::warning('website_lead_mail_failed', [
                'lead_id' => $lead->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function notifyAdmins(Lead $lead): void
    {
        try {
            app(DriverSessionService::class)->notifyAdmins(
                'Website '.$lead->type.' enquiry',
                $lead->name.' · '.$lead->phone.' · '.substr((string) $lead->message, 0, 180),
                ['type' => 'lead', 'id' => (string) $lead->id],
            );
        } catch (\Throwable $exception) {
            Log::warning('website_lead_notify_failed', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $raw = (string) config('karnacab.leads_notify_email', '');
        $emails = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL)));

        return $emails;
    }
}

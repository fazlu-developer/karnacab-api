<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificationTemplates
{
    /**
     * @param  array{title: string, body: string, channels: list<string>}  $fallback
     * @return array{title: string, body: string, channels: list<string>}
     */
    public function definition(string $event, array $fallback): array
    {
        $this->ensureTable();
        $row = DB::table('notification_event_templates')->where('event_key', $event)->first();
        if (! $row) {
            return $fallback;
        }
        $channels = json_decode((string) ($row->channels ?? ''), true);

        return [
            'title' => (string) ($row->title ?: $fallback['title']),
            'body' => (string) ($row->body ?: $fallback['body']),
            'channels' => is_array($channels) && $channels !== [] ? array_values($channels) : $fallback['channels'],
        ];
    }

    /**
     * @param  array<string, mixed>  $vars
     */
    public static function fill(string $template, array $vars): string
    {
        $out = preg_replace_callback('/\{(\w+)\}/', function (array $match) use ($vars) {
            $value = $vars[$match[1]] ?? '';

            return is_scalar($value) ? (string) $value : '';
        }, $template) ?? $template;

        return trim(preg_replace('/\s+/', ' ', $out) ?? $out);
    }

    public function ensureTable(): void
    {
        if (Schema::hasTable('notification_event_templates')) {
            return;
        }
        Schema::create('notification_event_templates', function ($table) {
            $table->id();
            $table->string('event_key', 80)->unique();
            $table->string('title', 180);
            $table->text('body');
            $table->json('channels');
            $table->timestamps();
        });
    }
}

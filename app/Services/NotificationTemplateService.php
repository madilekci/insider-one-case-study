<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class NotificationTemplateService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $templates = [];

        foreach (config('notification_templates', []) as $key => $template) {
            $templates[] = array_merge(['key' => $key], $template);
        }

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $key): ?array
    {
        $template = config('notification_templates.' . $key);

        return is_array($template) ? array_merge(['key' => $key], $template) : null;
    }

    public function render(string $key, string $channel, array $variables = []): string
    {
        $template = $this->get($key);

        if (! $template) {
            throw ValidationException::withMessages([
                'template_key' => ['The selected template is invalid.'],
            ]);
        }

        if (($template['channel'] ?? null) !== $channel) {
            throw ValidationException::withMessages([
                'template_key' => ['The selected template does not support this channel.'],
            ]);
        }

        $requiredVariables = (array) ($template['variables'] ?? []);
        $missingVariables = array_values(array_diff($requiredVariables, array_keys($variables)));

        if ($missingVariables !== []) {
            throw ValidationException::withMessages([
                'template_variables' => ['Missing required template variables: ' . implode(', ', $missingVariables)],
            ]);
        }

        $rendered = (string) ($template['body'] ?? '');

        foreach ($variables as $name => $value) {
            $rendered = str_replace('{{' . $name . '}}', (string) $value, $rendered);
        }

        preg_match_all('/\{\{([a-zA-Z0-9_.-]+)\}\}/', $rendered, $matches);

        if (! empty($matches[1])) {
            throw ValidationException::withMessages([
                'template_variables' => ['Template variables were not fully resolved: ' . implode(', ', array_unique($matches[1]))],
            ]);
        }

        return $rendered;
    }
}
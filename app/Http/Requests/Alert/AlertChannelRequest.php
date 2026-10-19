<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertChannel;
use App\Services\Settings\NotificationSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Создание (POST) и изменение (PATCH) канала — AlertChannelInput. Секреты (`bot_token`, `secret`) при изменении
 * можно не передавать или передать пустыми — тогда остаются прежние (если тип канала не менялся).
 */
class AlertChannelRequest extends FormRequest
{
    private function channel(): ?AlertChannel
    {
        return $this->route('alert_channel');
    }

    private function type(): ?string
    {
        return $this->input('type', $this->channel()?->type);
    }

    /** Секреты прежнего канала сохраняются, пока тип не сменили; иначе всё поля типа обязательны. */
    private function keepsSecrets(): bool
    {
        return $this->channel() !== null && $this->channel()->type === $this->type();
    }

    protected function prepareForValidation(): void
    {
        $config = $this->input('config');
        if (is_array($config) && isset($config['chat_id']) && is_int($config['chat_id'])) {
            $config['chat_id'] = (string) $config['chat_id'];
            $this->merge(['config' => $config]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->channel() ? 'sometimes' : 'required';
        $each = $this->keepsSecrets() ? 'sometimes' : 'required';
        $type = $this->type();

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::in(AlertChannel::TYPES)],
            'enabled' => ['sometimes', 'boolean'],
            'config' => [$required, 'array'],
            'config.bot_token' => $type === AlertChannel::TELEGRAM ? [$each, 'nullable', 'string', 'max:255', ...($each === 'required' ? ['filled'] : [])] : ['prohibited'],
            'config.chat_id' => $type === AlertChannel::TELEGRAM ? [$each, 'string', 'max:255'] : ['prohibited'],
            'config.recipients' => $type === AlertChannel::EMAIL ? [$each, 'array', 'min:1', 'max:20'] : ['prohibited'],
            'config.recipients.*' => ['string', 'email:rfc', 'max:255'],
            'config.url' => $type === AlertChannel::WEBHOOK ? [$each, 'string', 'max:2048', 'url:http,https'] : ['prohibited'],
            'config.secret' => $type === AlertChannel::WEBHOOK ? ['nullable', 'string', 'max:255'] : ['prohibited'],
        ];
    }

    /** Канал «Почта» нельзя создать (или перевести в этот тип), пока почта не настроена: письма уходили бы в никуда. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $becomesEmail = $this->type() === AlertChannel::EMAIL && $this->channel()?->type !== AlertChannel::EMAIL;
            if ($becomesEmail && ! app(NotificationSettings::class)->mailConfigured()) {
                $validator->errors()->add('type', NotificationSettings::NOT_CONFIGURED);
            }
        }];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'type' => 'тип',
            'config' => 'настройки',
            'config.bot_token' => 'токен бота',
            'config.chat_id' => 'chat_id',
            'config.recipients' => 'получатели',
            'config.recipients.*' => 'адрес почты',
            'config.url' => 'адрес вебхука',
            'config.secret' => 'секрет',
        ];
    }

    /**
     * Поля канала для сохранения. Настройки — только те, что относятся к типу; пустой секрет — прежний.
     *
     * @return array<string, mixed>
     */
    public function channelData(): array
    {
        $data = $this->safe()->only(['name', 'type', 'enabled']);
        $type = $this->type();
        $input = (array) $this->validated('config', []);
        $old = $this->keepsSecrets() ? ($this->channel()->config ?? []) : [];

        $config = [];
        foreach (match ($type) {
            AlertChannel::TELEGRAM => ['chat_id', 'bot_token'],
            AlertChannel::EMAIL => ['recipients'],
            default => ['url', 'secret'],
        } as $key) {
            $value = $input[$key] ?? null;
            $secret = in_array($key, ['bot_token', 'secret'], true);
            $config[$key] = filled($value) || (! $secret && array_key_exists($key, $input)) ? $value : ($old[$key] ?? null);
        }
        $data['config'] = array_filter($config, fn ($v) => $v !== null && $v !== '');

        return $data;
    }
}

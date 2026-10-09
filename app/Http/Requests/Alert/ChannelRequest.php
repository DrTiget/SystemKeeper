<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Создание и изменение канала; тип менять нельзя, настройки обновляются частично (секрет можно не передавать). */
class ChannelRequest extends FormRequest
{
    private function type(): ?string
    {
        $channel = $this->route('alert_channel');

        return $channel instanceof AlertChannel ? $channel->type : $this->input('type');
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:255'],
            'type' => $creating ? ['required', Rule::in(AlertChannel::TYPES)] : ['prohibited'],
            'enabled' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.bot_token' => $this->type() === AlertChannel::TYPE_TELEGRAM ? [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'regex:/^\d+:[A-Za-z0-9_-]+$/'] : ['prohibited'],
            'settings.chat_id' => $this->type() === AlertChannel::TYPE_TELEGRAM ? [$creating ? 'required' : 'sometimes', 'string', 'max:64'] : ['prohibited'],
            'settings.recipients' => $this->type() === AlertChannel::TYPE_EMAIL ? [$creating ? 'required' : 'sometimes', 'array', 'min:1', 'max:20'] : ['prohibited'],
            'settings.recipients.*' => ['email', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // chat_id в Telegram бывает числом
        if (is_int($this->input('settings.chat_id'))) {
            $settings = $this->input('settings');
            $settings['chat_id'] = (string) $settings['chat_id'];
            $this->merge(['settings' => $settings]);
        }
    }

    public function attributes(): array
    {
        return [
            'name' => 'название', 'type' => 'тип', 'enabled' => 'включён', 'settings' => 'настройки',
            'settings.bot_token' => 'токен бота', 'settings.chat_id' => 'chat_id', 'settings.recipients' => 'получатели',
            'settings.recipients.*' => 'email получателя',
        ];
    }
}

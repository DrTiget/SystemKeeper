<?php

namespace App\Http\Requests\Alert;

use App\Models\AlertChannel;
use App\Services\Alerts\RuleSuggestions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Применение рекомендуемых правил: выбранные ключи из GET /alert-rules/suggestions и каналы, в которые они будут слать.
 * Ключ, которого нет в наборе или который уже закрыт правилом, — ошибка на `keys`.
 */
class ApplySuggestionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keys' => ['required', 'array', 'min:1', 'max:50'],
            'keys.*' => ['required', 'string', 'distinct'],
            'channel_ids' => ['required', 'array', 'min:1', 'max:50'],
            'channel_ids.*' => ['integer', Rule::exists('alert_channels', 'id')],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['keys' => 'правила', 'channel_ids' => 'каналы', 'channel_ids.*' => 'канал'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['channel_ids.required' => 'Выберите хотя бы один канал уведомлений.', 'channel_ids.min' => 'Выберите хотя бы один канал уведомлений.'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('keys') || $validator->errors()->has('keys.*')) {
                return;
            }
            if (! AlertChannel::query()->exists()) {
                $validator->errors()->add('channel_ids', 'Сначала создайте канал уведомлений: правилам некуда слать сообщения.');
            }
            $available = array_column(app(RuleSuggestions::class)->all(), 'key');
            $bad = array_values(array_diff($this->input('keys', []), $available));
            if ($bad) {
                $validator->errors()->add('keys', 'Эти правила нельзя добавить — они неизвестны или уже есть: '.implode(', ', $bad).'.');
            }
        }];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_values($this->validated('keys'));
    }

    /** @return list<int> */
    public function channelIds(): array
    {
        return array_map('intval', array_values($this->validated('channel_ids')));
    }
}

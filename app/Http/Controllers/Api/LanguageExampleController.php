<?php

namespace App\Http\Controllers\Api;

use App\Models\LanguageExample;
use Illuminate\Validation\Rule;

class LanguageExampleController extends TenantResourceController
{
    protected function model(): string
    {
        return LanguageExample::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'customer_message' => [$required, 'string', 'max:2000'],
            'good_reply' => [$required, 'string', 'max:2000'],
            'language' => ['nullable', Rule::in(['ru', 'tj', 'tj_latin', 'en'])],
        ];
    }
}

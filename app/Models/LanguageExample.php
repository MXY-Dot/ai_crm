<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'customer_message', 'good_reply', 'language'])]
class LanguageExample extends Model
{
    use BelongsToTenant;
}

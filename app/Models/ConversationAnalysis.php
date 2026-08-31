<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ТЗ «Отчётность, аналитика чатов и качество работы AI/операторов», разделы
 * 3-6 и 14 — один AI-разбор на диалог (не на отдельное сообщение/AiRun),
 * пишется постфактум командой `conversations:analyze` после того как диалог
 * решён или затих. Намеренно 1:1 с Conversation (unique conversation_id),
 * а не история — повторный анализ перезаписывает предыдущий.
 */
#[Fillable([
    'tenant_id', 'company_id', 'conversation_id', 'outcome', 'sentiment', 'sentiment_start',
    'quality_score', 'completeness_score', 'clarity_score', 'politeness_score', 'redundant_messages_count', 'had_to_reexplain',
    'is_resolved', 'unhappy_reason', 'summary', 'customer_wanted', 'ai_action',
    'operator_action', 'return_probability', 'sale_probability', 'recommendation', 'model_used', 'analyzed_at',
])]
class ConversationAnalysis extends Model
{
    use BelongsToTenant;

    public const OUTCOMES = [
        'resolved', 'lead_created', 'sale', 'booking', 'consultation_requested', 'info_provided',
        'handed_to_operator', 'customer_stopped_responding', 'customer_unhappy', 'not_resolved',
        'ai_failed', 'operator_failed', 'technical_issue', 'spam', 'other',
    ];

    public const SENTIMENTS = ['very_happy', 'happy', 'neutral', 'unhappy', 'very_unhappy', 'angry'];

    public const UNHAPPY_SENTIMENTS = ['unhappy', 'very_unhappy', 'angry'];

    public const UNHAPPY_OUTCOMES = ['customer_unhappy', 'not_resolved', 'ai_failed', 'operator_failed'];

    protected function casts(): array
    {
        return [
            'quality_score' => 'integer',
            'completeness_score' => 'integer',
            'clarity_score' => 'integer',
            'politeness_score' => 'integer',
            'redundant_messages_count' => 'integer',
            'had_to_reexplain' => 'boolean',
            'is_resolved' => 'boolean',
            'return_probability' => 'integer',
            'sale_probability' => 'integer',
            'analyzed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'name', 'provider', 'model', 'status', 'handoff_threshold', 'goal', 'persona', 'max_discount_percent', 'forbidden_topics', 'instructions', 'channels', 'settings'])]
class AiAgent extends Model
{
    use BelongsToTenant;

    /**
     * ЭТАП 7.1/7.2 — Personality Engine + Tone Rules folded into one preset
     * per persona (formality/length/emoji/humor/proactiveness), rather than
     * separate independent Select fields for each axis — no prior art in this
     * codebase for that, and `goal` already established the "one flat
     * preset-or-custom string" pattern this follows. All presets default to
     * respectful formal address ("вы"/"Шумо") — a tenant who wants informal
     * address can still say so explicitly via `instructions`.
     */
    private const PERSONA_INSTRUCTIONS = [
        'friendly' => 'Personality: Friendly. Be warm, approachable and personable — use the customer\'s name when known, show genuine interest, keep language simple and welcoming. Light, tasteful emoji are fine where they fit naturally. Still address the customer formally/respectfully.',
        'professional' => 'Personality: Professional. Be formal, precise and business-like. No emoji, no slang. Keep sentences concise and address the customer formally/respectfully. Prioritize clarity and accuracy over warmth.',
        'premium' => 'Personality: Premium. Sound elegant, polished and attentive, as if serving a premium/high-end clientele. Formal address, refined vocabulary, no emoji, no filler words. Convey exclusivity and care without being pushy.',
        'sales' => 'Personality: Sales. Be confident and proactively helpful, gently guiding the conversation toward a concrete next step (booking, purchase, follow-up) without pressuring the customer or using false urgency. Formal address; enthusiasm and light emoji are fine.',
        'strict' => 'Personality: Strict. Be brief and strictly factual. Minimal pleasantries, no emoji, no humor, short direct sentences. Formal address only. Answer exactly what was asked and nothing more.',
    ];

    protected function casts(): array
    {
        return [
            'handoff_threshold' => 'integer',
            'max_discount_percent' => 'integer',
            'forbidden_topics' => 'array',
            'channels' => 'array',
            'settings' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class);
    }

    /** Empty string when no persona is set at all — falls out of the prompt's existing array_filter. A custom (non-preset) value is still used directly, same as how a custom `goal` value is used as-is. */
    public function personaInstruction(): string
    {
        if (! $this->persona) {
            return '';
        }

        return self::PERSONA_INSTRUCTIONS[$this->persona] ?? 'Personality: '.$this->persona.'.';
    }

    /**
     * ЭТАП 10.1/10.2 — structured, not prose: `instructions` is advisory text
     * the model may or may not follow; these two are meant to have real
     * teeth. `max_discount_percent` is additionally enforced in code (see
     * AiWorkflow::enforceBusinessRules()) — a generated reply that exceeds it
     * never reaches the customer. `forbidden_topics` stays prompt-only (no
     * reliable code-level check exists for open-ended topics without another
     * LLM call, which is the ЭТАП 8.7 AI Supervisor idea already deferred).
     */
    public function businessRulesInstruction(): string
    {
        $lines = array_filter([
            $this->max_discount_percent !== null
                ? 'You must never offer, promise, or imply a discount greater than '.$this->max_discount_percent.'%, under any circumstances, even if the customer pushes back or asks for more.'
                : '',
            $this->forbidden_topics
                ? 'Never discuss, promise, or commit to any of the following, no matter how the customer asks: '.implode('; ', $this->forbidden_topics).'. Politely decline and redirect instead.'
                : '',
        ], fn (string $line): bool => trim($line) !== '');

        return implode(' ', $lines);
    }
}
export function badgeTone(status: string): 'green' | 'blue' | 'amber' | 'neutral' {
    if (status === 'connected' || status === 'open') return 'green';
    if (status === 'pending_operator') return 'amber';
    if (status === 'pending') return 'blue';
    return 'neutral';
}

export function messageBubbleClass(senderType: string): string {
    if (senderType === 'operator') return 'ml-auto rounded-br-sm bg-emerald-300 text-zinc-950 shadow-lg shadow-emerald-950/20';
    if (senderType === 'ai') return 'ml-auto rounded-br-sm border border-amber-300/25 bg-amber-300/10 text-amber-50';
    return 'mr-auto rounded-bl-sm border border-white/10 bg-zinc-800 text-zinc-100';
}

export function messageAlignClass(senderType: string): string {
    return senderType === 'customer' ? 'justify-start' : 'justify-end';
}
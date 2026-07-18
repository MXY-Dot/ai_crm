type Method = 'GET' | 'POST' | 'PATCH' | 'DELETE';

const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export async function apiRequest<T>(path: string, options: { method?: Method; body?: unknown; tenant?: string | null } = {}): Promise<T> {
    const isFormData = options.body instanceof FormData;
    const response = await fetch(path, {
        method: options.method ?? 'GET',
        headers: {
            Accept: 'application/json',
            ...(isFormData ? {} : { 'Content-Type': 'application/json' }),
            'X-CSRF-TOKEN': csrf,
            ...(options.tenant ? { 'X-Tenant-Id': options.tenant } : {}),
        },
        credentials: 'same-origin',
        body: options.body ? (isFormData ? options.body : JSON.stringify(options.body)) : undefined,
    });

    if (! response.ok) {
        const error = await response.json().catch(() => ({ message: response.statusText }));
        throw new Error(error.message ?? response.statusText);
    }

    return response.json() as Promise<T>;
}
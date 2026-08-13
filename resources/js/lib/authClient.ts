import axios from 'axios';

export type AuthErrors = Record<string, string>;

export async function authPost(path: string, data: Record<string, unknown> = {}): Promise<Record<string, unknown>> {
    try {
        const response = await axios.post(path, data, { headers: { Accept: 'application/json' } });
        return response.data ?? {};
    } catch (caught) {
        if (axios.isAxiosError(caught) && caught.response?.status === 422) {
            const errors = caught.response.data?.errors ?? {};
            const message = caught.response.data?.message ?? 'Validation failed';
            const error = new Error(message) as Error & { errors: AuthErrors };
            error.errors = Object.fromEntries(
                Object.entries(errors).map(([key, value]) => [key, Array.isArray(value) ? String(value[0] ?? '') : String(value)]),
            );
            throw error;
        }

        throw caught;
    }
}
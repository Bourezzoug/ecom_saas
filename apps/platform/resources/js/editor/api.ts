/**
 * Minimal JSON client for the editor endpoints (session auth + Laravel's
 * XSRF-TOKEN cookie). Throws ApiError with Laravel's validation errors.
 */
export class ApiError extends Error {
    constructor(
        message: string,
        public readonly status: number,
        public readonly errors: Record<string, string[]> = {},
        public readonly body: Record<string, unknown> = {},
    ) {
        super(message);
    }

    firstError(): string {
        const first = Object.values(this.errors)[0]?.[0];

        return first ?? this.message;
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export async function api<T>(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const isForm = body instanceof FormData;
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body !== undefined && !isForm
                ? { 'Content-Type': 'application/json' }
                : {}),
        },
    };

    if (body !== undefined && method !== 'GET') {
        init.body = isForm ? body : JSON.stringify(body);
    }

    const response = await fetch(url, init);

    const data = (await response.json().catch(() => ({}))) as Record<
        string,
        unknown
    >;

    if (!response.ok) {
        throw new ApiError(
            typeof data.message === 'string'
                ? data.message
                : response.statusText,
            response.status,
            (data.errors as Record<string, string[]>) ?? {},
            data,
        );
    }

    return data as T;
}

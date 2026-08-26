import { Form } from '@inertiajs/react';

type LoginProps = {
    locale: string;
    labels: {
        title: string;
        phone: string;
        password: string;
        submit: string;
        failed: string;
    };
};

export default function Login({ locale, labels }: LoginProps) {
    return (
        <main className="mx-auto max-w-lg p-8" lang={locale}>
            <h1 className="text-2xl font-semibold">{labels.title}</h1>
            <Form action="/login" method="post" className="mt-6 grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <label className="grid gap-1 text-sm">
                            {labels.phone}
                            <input
                                name="phone"
                                type="tel"
                                autoComplete="username"
                                className="rounded border px-3 py-2"
                            />
                        </label>
                        <label className="grid gap-1 text-sm">
                            {labels.password}
                            <input
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                className="rounded border px-3 py-2"
                            />
                        </label>
                        {errors.phone ? (
                            <p role="alert" className="text-sm">
                                {errors.phone}
                            </p>
                        ) : null}
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded bg-slate-900 px-4 py-2 text-white"
                        >
                            {labels.submit}
                        </button>
                    </>
                )}
            </Form>
        </main>
    );
}

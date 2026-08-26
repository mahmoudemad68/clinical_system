import { Form } from '@inertiajs/react';

type MfaProps = {
    locale: string;
    labels: {
        title: string;
        code: string;
        submit: string;
        failed: string;
    };
};

export default function Mfa({ locale, labels }: MfaProps) {
    return (
        <main className="mx-auto max-w-lg p-8" lang={locale}>
            <h1 className="text-2xl font-semibold">{labels.title}</h1>
            <Form action="/mfa" method="post" className="mt-6 grid gap-4">
                {({ errors, processing }) => (
                    <>
                        <label className="grid gap-1 text-sm">
                            {labels.code}
                            <input
                                name="code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                maxLength={6}
                                className="rounded border px-3 py-2"
                            />
                        </label>
                        {errors.code ? (
                            <p role="alert" className="text-sm">
                                {errors.code}
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

type StatusViewProps = {
    service: string;
    version: string;
    status: string;
    message: string;
    locale: string;
    labels: {
        title: string;
        service: string;
        version: string;
        status: string;
        message: string;
    };
};

export default function StatusView({
    service,
    version,
    status,
    message,
    locale,
    labels,
}: StatusViewProps) {
    return (
        <main className="mx-auto max-w-lg p-8" lang={locale}>
            <h1 className="text-2xl font-semibold">{labels.title}</h1>
            <dl className="mt-6 grid gap-3 text-sm">
                <div>
                    <dt className="font-medium">{labels.service}</dt>
                    <dd data-testid="service">{service}</dd>
                </div>
                <div>
                    <dt className="font-medium">{labels.version}</dt>
                    <dd data-testid="version">{version}</dd>
                </div>
                <div>
                    <dt className="font-medium">{labels.status}</dt>
                    <dd data-testid="status">{status}</dd>
                </div>
                <div>
                    <dt className="font-medium">{labels.message}</dt>
                    <dd data-testid="message">{message}</dd>
                </div>
            </dl>
        </main>
    );
}

import StatusView from '../../components/StatusView';

type StatusProps = {
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

export default function Status(props: StatusProps) {
    return <StatusView {...props} />;
}

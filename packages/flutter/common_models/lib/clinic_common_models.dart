/// Shared models for the clinic Flutter clients.
///
/// These are client-side domain and view models. Generated API DTOs stay at the
/// network edge in `clinic_api_client` and are mapped into these types, so a
/// contract change does not ripple straight into the widget tree (phase file,
/// "Client architecture").
library;

export 'src/platform_health.dart';

/// Typed API surface for the clinic platform.
///
/// Wire DTOs stay inside this package and are mapped into `clinic_common_models`
/// types before they reach a repository or a widget (phase file, "Client
/// architecture"). That boundary is what stops an OpenAPI change from rippling
/// straight into the widget tree.
///
/// Phase 00 exposes health and version. Phase 02 adds the own-patient profile
/// adapter. There is no GET-by-patient-id helper.
library;

export 'src/generated/openapi_contract.dart';
export 'src/intent_idempotency.dart';
export 'src/patient_api.dart';
export 'src/platform_api.dart';

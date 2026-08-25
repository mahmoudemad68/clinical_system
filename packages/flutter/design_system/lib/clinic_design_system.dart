/// Shared widgets and theme for the clinic Flutter clients.
///
/// The point of the monorepo: the health panel below is written once and used
/// by the patient app, the doctor desktop, and the pharmacy desktop. Three
/// copies would drift, and the Arabic one would drift first.
library;

export 'src/clinic_theme.dart';
export 'src/health_panel.dart';
export 'src/status_indicator.dart';

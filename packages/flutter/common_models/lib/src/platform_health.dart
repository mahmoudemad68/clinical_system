import 'package:meta/meta.dart';

/// Status of one platform component.
///
/// `degraded` exists so an AI outage can be shown honestly without claiming the
/// platform is down. An AI outage is not a core outage (plan.md section 141),
/// and collapsing these into a boolean is how that distinction gets lost.
enum ComponentStatus {
  operational,
  degraded,
  unavailable;

  /// Parse a server value, defaulting to [unavailable] on anything unknown.
  ///
  /// Fail closed: an unrecognised status is more likely a problem than a
  /// success, and showing "operational" for a value we do not understand would
  /// be the dangerous direction to guess in.
  static ComponentStatus fromWire(String? value) => switch (value) {
    'operational' => ComponentStatus.operational,
    'degraded' => ComponentStatus.degraded,
    _ => ComponentStatus.unavailable,
  };
}

/// Coarse platform health for display.
///
/// Deliberately carries no hostnames, dependency versions, or error detail:
/// the server does not send them, and a client must not invent them.
@immutable
class PlatformHealth {
  const PlatformHealth({
    required this.status,
    required this.message,
    required this.core,
    required this.realtime,
    required this.ai,
    required this.version,
    required this.serverTime,
  });

  final ComponentStatus status;

  /// Localized human message, already negotiated by the server through
  /// `Accept-Language`. The client displays it rather than composing its own,
  /// so wording stays consistent across four clients.
  final String message;

  final ComponentStatus core;
  final ComponentStatus realtime;
  final ComponentStatus ai;

  final String version;

  /// Always UTC on the wire. Convert for display only at the edge.
  final DateTime serverTime;

  /// True when core capability is available, whatever the AI is doing.
  bool get coreUsable => core != ComponentStatus.unavailable;

  @override
  bool operator ==(Object other) =>
      identical(this, other) ||
      other is PlatformHealth &&
          other.status == status &&
          other.message == message &&
          other.core == core &&
          other.realtime == realtime &&
          other.ai == ai &&
          other.version == version &&
          other.serverTime == serverTime;

  @override
  int get hashCode =>
      Object.hash(status, message, core, realtime, ai, version, serverTime);
}

import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:test/test.dart';

void main() {
  group('ComponentStatus.fromWire', () {
    test('maps every known server value', () {
      expect(
        ComponentStatus.fromWire('operational'),
        ComponentStatus.operational,
      );
      expect(ComponentStatus.fromWire('degraded'), ComponentStatus.degraded);
      expect(
        ComponentStatus.fromWire('unavailable'),
        ComponentStatus.unavailable,
      );
    });

    test('fails closed on an unknown or missing value', () {
      // Guessing "operational" for a value we do not understand is the
      // dangerous direction: it would show a healthy platform during an outage.
      expect(
        ComponentStatus.fromWire('something-new'),
        ComponentStatus.unavailable,
      );
      expect(ComponentStatus.fromWire(null), ComponentStatus.unavailable);
      expect(ComponentStatus.fromWire(''), ComponentStatus.unavailable);
    });
  });

  group('PlatformHealth', () {
    PlatformHealth build({
      ComponentStatus core = ComponentStatus.operational,
      ComponentStatus ai = ComponentStatus.operational,
    }) => PlatformHealth(
      status: core,
      message: 'ok',
      core: core,
      realtime: ComponentStatus.operational,
      ai: ai,
      version: '0.1.0',
      serverTime: DateTime.utc(2026, 8, 24, 19, 5),
    );

    test('core stays usable when only AI is unavailable', () {
      // An AI outage is not a core outage (plan.md section 141).
      expect(build(ai: ComponentStatus.unavailable).coreUsable, isTrue);
    });

    test('core is not usable when core itself is unavailable', () {
      expect(build(core: ComponentStatus.unavailable).coreUsable, isFalse);
    });

    test('is a value type', () {
      expect(build(), equals(build()));
      expect(build().hashCode, equals(build().hashCode));
      expect(build(ai: ComponentStatus.degraded), isNot(equals(build())));
    });
  });
}

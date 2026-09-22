import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_patient_app/onboarding/onboarding_screen.dart';
import 'package:clinic_patient_app/profile/account_gate_screen.dart';
import 'package:clinic_patient_app/profile/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

Map<String, dynamic> healthData() => {
  'status': 'operational',
  'message': 'ok',
  'components': {
    'core': 'operational',
    'realtime': 'operational',
    'ai': 'operational',
  },
  'version': 'test',
  'server_time': '2026-09-01T12:00:00Z',
};

void main() {
  testWidgets('empty vault lands on the auth surface', (tester) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/health')) {
        return jsonEnvelope(200, healthData());
      }
      return jsonEnvelope(401, null);
    });
    await tester.pumpWidget(
      clinicHost(
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('auth-submit')), findsOneWidget);
    expect(find.byType(OnboardingScreen), findsNothing);
  });

  testWidgets('own profile routes to the Phase 02 profile surface', (
    tester,
  ) async {
    final vault = MemoryVault();
    final store = TokenStore(vault);
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/health')) {
        return jsonEnvelope(200, healthData());
      }
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      return jsonEnvelope(200, profileWire(name: 'Visible Patient'));
    });
    await tester.pumpWidget(
      clinicHost(
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(ProfileScreen), findsOneWidget);
    expect(find.text('Visible Patient'), findsOneWidget);
    expect(find.byKey(const Key('profile-edit')), findsOneWidget);
  });

  testWidgets('missing own profile routes to onboarding', (tester) async {
    final vault = MemoryVault();
    final store = TokenStore(vault);
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/health')) {
        return jsonEnvelope(200, healthData());
      }
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      return jsonEnvelope(
        404,
        null,
        errors: [
          {'code': 'NOT_FOUND', 'message': 'Not found.'},
        ],
      );
    });
    await tester.pumpWidget(
      clinicHost(
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(OnboardingScreen), findsOneWidget);
  });

  testWidgets('non-patient accounts cannot use the patient profile surface', (
    tester,
  ) async {
    final vault = MemoryVault();
    final store = TokenStore(vault);
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      expect(isPath(options, 'patients'), isFalse);
      if (isPath(options, '/health')) {
        return jsonEnvelope(200, healthData());
      }
      final me = mePatient()..['account_type'] = 'doctor';
      return jsonEnvelope(200, me);
    });
    await tester.pumpWidget(
      clinicHost(
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.byType(AccountGateScreen), findsOneWidget);
    expect(find.byKey(const Key('account-gate')), findsOneWidget);
    expect(find.byType(OnboardingScreen), findsNothing);
    expect(find.byType(ProfileScreen), findsNothing);
  });
}

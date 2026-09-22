import 'dart:convert';
import 'dart:io';

import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_networking/clinic_networking.dart';
import 'package:clinic_patient_app/app_providers.dart';
import 'package:clinic_patient_app/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

bool get _required =>
    Platform.environment['CLINIC_REQUIRE_PATIENT_PROFILE_E2E'] == '1';

String get _baseUrl =>
    Platform.environment['CLINIC_API_BASE_URL'] ?? 'http://localhost:8080';

String get _fixturesPath =>
    Platform.environment['CLINIC_PATIENT_E2E_FIXTURES'] ?? '';

/// Flutter's widget-test binding mocks dart:io [HttpClient] to HTTP 400.
/// This restores a real client so Dio can reach local Core.
class _PassthroughHttpOverrides extends HttpOverrides {}

void main() {
  testWidgets('real Core patient onboarding, edit, conflict, review, isolation', (
    tester,
  ) async {
    if (!_required) {
      return;
    }

    final previousHttp = HttpOverrides.current;
    HttpOverrides.global = _PassthroughHttpOverrides();
    addTearDown(() {
      HttpOverrides.global = previousHttp;
    });

    expect(
      _fixturesPath,
      isNotEmpty,
      reason: 'CLINIC_PATIENT_E2E_FIXTURES is required',
    );
    final fixtures = jsonDecode(
      File(_fixturesPath).readAsStringSync(),
    ) as Map<String, dynamic>;
    final password = fixtures['password'] as String;
    final onboarder = fixtures['onboarder'] as Map<String, dynamic>;
    final patientA = fixtures['patientA'] as Map<String, dynamic>;
    final patientB = fixtures['patientB'] as Map<String, dynamic>;
    final conflict = fixtures['conflict'] as Map<String, dynamic>;
    final reviewer = fixtures['reviewer'] as Map<String, dynamic>;

    Object? healthError;
    final health = await tester.runAsync(() async {
      try {
        return await PlatformApi(ClinicHttpClient(baseUrl: _baseUrl)).health();
      } catch (error) {
        healthError = error;
        return null;
      }
    });
    if (health == null) {
      fail(
        'Core health request failed at $_baseUrl: ${healthError ?? 'no result'}',
      );
    }
    expect(health.coreUsable, isTrue);

    final client = ClinicHttpClient(baseUrl: _baseUrl);
    addTearDown(client.close);
    final vault = MemoryVault();
    final tokens = TokenStore(vault);

    Future<void> pumpApp() async {
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            httpClientProvider.overrideWithValue(client),
            tokenStoreProvider.overrideWithValue(tokens),
          ],
          child: const ClinicApp(),
        ),
      );
      await pumpUntilFound(
        tester,
        find.byKey(const Key('sign-in')),
        realAsync: true,
        maxPumps: 200,
      );
    }

    Future<void> login(String phone, {required Finder landed}) async {
      await tester.enterText(find.byType(TextField).at(0), phone);
      await tester.enterText(find.byType(TextField).at(1), password);
      await tester.tap(find.byKey(const Key('sign-in')));
      await pumpUntilFound(tester, landed, realAsync: true, maxPumps: 200);
    }

    Future<void> logout() async {
      final signOut = find.byKey(const Key('sign-out'));
      expect(signOut, findsOneWidget);
      await tester.ensureVisible(signOut);
      await tester.tap(signOut);
      await pumpUntilFound(
        tester,
        find.byKey(const Key('sign-in')),
        realAsync: true,
        maxPumps: 200,
      );
    }

    Future<void> waitFor(Finder finder) {
      return pumpUntilFound(tester, finder, realAsync: true, maxPumps: 200);
    }

    Future<void> waitGone(Finder finder) {
      return pumpUntilGone(tester, finder, realAsync: true, maxPumps: 200);
    }

    await pumpApp();

    // Isolation: A then B.
    await login(
      patientA['phone'] as String,
      landed: find.text('Patient A Visible'),
    );
    expect(find.text('Patient A Visible'), findsOneWidget);
    await logout();
    expect(find.text('Patient A Visible'), findsNothing);
    await login(
      patientB['phone'] as String,
      landed: find.text('Patient B Visible'),
    );
    expect(find.text('Patient A Visible'), findsNothing);
    expect(find.text('Patient B Visible'), findsOneWidget);
    await logout();

    // Fresh-enough onboarding (seeded verified patient, no profile).
    await login(
      onboarder['phone'] as String,
      landed: find.byKey(const Key('onboarding-national-id')),
    );
    expect(find.byKey(const Key('onboarding-national-id')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'Onboarded Patient',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      onboarder['national_id'] as String,
    );
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('onboarding-gender')));
    await selectDropdown(tester, const Key('onboarding-gender'), 'Female');
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('self-reported-hint')));
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('onboarding-review')));
    expect(find.text(onboarder['national_id'] as String), findsNothing);
    await tester.tap(find.byKey(const Key('onboarding-submit')));
    await waitFor(find.byKey(const Key('profile-full-name')));
    expect(find.text('Onboarded Patient'), findsOneWidget);
    expect(find.textContaining('National ID'), findsNothing);
    await tester.tap(find.byKey(const Key('edit-demographics')));
    await waitFor(find.byKey(const Key('demographics-save')));
    await tester.enterText(find.byKey(const Key('edit-height')), '171');
    await tester.tap(find.byKey(const Key('demographics-save')));
    await waitGone(find.byKey(const Key('demographics-save')));
    await waitFor(find.byKey(const Key('profile-full-name')));
    expect(find.textContaining('171'), findsWidgets);
    await logout();

    // VERSION_CONFLICT via a second authorized client.
    await login(
      conflict['phone'] as String,
      landed: find.byKey(const Key('edit-demographics')),
    );
    expect(find.byKey(const Key('edit-demographics')), findsOneWidget);
    await tester.tap(find.byKey(const Key('edit-demographics')));
    await waitFor(find.byKey(const Key('demographics-save')));

    final second = ClinicHttpClient(baseUrl: _baseUrl);
    addTearDown(second.close);
    final secondTokens = TokenStore(MemoryVault());
    final secondAuth = AuthApi(second, secondTokens);
    await tester.runAsync(() async {
      await secondAuth.login(
        phone: conflict['phone'] as String,
        password: password,
        platform: 'android',
        deviceLabel: 'conflict-second',
      );
      await PatientApi(second).updateDemographics(
        const PatientDemographicsPatch(version: 1, heightCm: 180),
      );
    });

    await tester.enterText(find.byKey(const Key('edit-weight')), '80');
    await tester.tap(find.byKey(const Key('demographics-save')));
    await waitFor(find.byKey(const Key('version-conflict')));
    expect(find.byKey(const Key('version-conflict')), findsOneWidget);
    await tester.tap(find.byKey(const Key('version-conflict-refresh')));
    await waitGone(find.byKey(const Key('version-conflict')));
    expect(find.byKey(const Key('version-conflict')), findsNothing);
    await tester.pageBack();
    await waitFor(find.byKey(const Key('edit-demographics')));
    await logout();

    // Manual review: unlinked collision, generic UI.
    await login(
      reviewer['phone'] as String,
      landed: find.byKey(const Key('onboarding-national-id')),
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'Review Patient',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      reviewer['national_id'] as String,
    );
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('onboarding-gender')));
    await selectDropdown(tester, const Key('onboarding-gender'), 'Female');
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('self-reported-hint')));
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await waitFor(find.byKey(const Key('onboarding-review')));
    await tester.tap(find.byKey(const Key('onboarding-submit')));
    await waitFor(find.byKey(const Key('manual-review')));
    expect(find.textContaining('already exists'), findsNothing);
    expect(find.textContaining('National ID'), findsNothing);
    expect(find.text(reviewer['national_id'] as String), findsNothing);

    final evidence = {
      'skipped': false,
      'core_health': health.core.name,
      'onboarding': 'profile_ready',
      'version_conflict': true,
      'manual_review': 'generic',
      'isolation': true,
    };
    final evidencePath =
        Platform.environment['CLINIC_PATIENT_E2E_EVIDENCE'] ??
        'tests/flutter-e2e/logs/patient-profile-e2e.json';
    File(evidencePath).parent.createSync(recursive: true);
    File(evidencePath).writeAsStringSync('${jsonEncode(evidence)}\n');
  }, timeout: const Timeout(Duration(minutes: 3)));
}

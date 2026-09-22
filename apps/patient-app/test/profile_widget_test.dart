import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_patient_app/profile/edit_demographics_screen.dart';
import 'package:clinic_patient_app/profile/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

PatientProfile sample({
  int version = 1,
  String name = 'Own Name',
  PatientLifecycleStatus status = PatientLifecycleStatus.active,
}) {
  return PatientProfile(
    patientId: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
    fullName: name,
    gender: 'female',
    dateOfBirth: '1990-01-15',
    heightCm: '165.50',
    weightKg: '62.30',
    maritalStatus: 'single',
    bloodType: 'A+',
    status: status,
    version: version,
    createdAt: DateTime.utc(2026, 9, 1),
    updatedAt: DateTime.utc(2026, 9, 1),
  );
}

void main() {
  testWidgets('profile displays safe fields and not national id', (
    tester,
  ) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(200, profileWire()),
    );
    await tester.pumpWidget(
      wrap(
        ProfileScreen(profile: sample()),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    expect(find.byKey(const Key('profile-full-name')), findsOneWidget);
    expect(find.text('Own Name'), findsOneWidget);
    expect(find.text('Self-reported'), findsWidgets);
    expect(find.textContaining('2920101'), findsNothing);
    expect(find.byKey(const Key('profile-edit')), findsOneWidget);
  });

  testWidgets('non-active profiles are read-only', (tester) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(200, profileWire()),
    );
    await tester.pumpWidget(
      wrap(
        ProfileScreen(
          profile: sample(status: PatientLifecycleStatus.restricted),
        ),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    expect(find.byKey(const Key('profile-readonly')), findsOneWidget);
    expect(find.byKey(const Key('profile-edit')), findsNothing);
  });

  testWidgets('VERSION_CONFLICT shows refresh, not a silent retry', (
    tester,
  ) async {
    final vault = MemoryVault();
    var patched = false;
    final adapter = ScriptedAdapter((options) async {
      if (options.method == 'PATCH') {
        patched = true;
        expect(options.data['version'], 1);
        return jsonEnvelope(
          409,
          null,
          errors: [
            {
              'code': 'VERSION_CONFLICT',
              'message': 'The resource was updated by another request.',
            },
          ],
        );
      }
      return jsonEnvelope(200, profileWire(version: 2, name: 'Server Name'));
    });
    await tester.pumpWidget(
      wrap(
        EditDemographicsScreen(profile: sample()),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    await tester.enterText(find.byKey(const Key('edit-height')), '170');
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    expect(patched, isTrue);
    expect(find.byKey(const Key('version-conflict')), findsOneWidget);
    expect(find.textContaining('updated elsewhere'), findsOneWidget);
    expect(find.text('Refresh status'), findsOneWidget);

    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('version-conflict')), findsNothing);
    expect(find.text('Server Name'), findsOneWidget);
  });

  testWidgets('edit demographics has no national id control', (tester) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(200, profileWire()),
    );
    await tester.pumpWidget(
      wrap(
        EditDemographicsScreen(profile: sample()),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    expect(find.byKey(const Key('onboarding-national-id')), findsNothing);
    expect(find.textContaining('National ID'), findsNothing);
    expect(find.textContaining('2920101'), findsNothing);
  });

  testWidgets('Arabic profile is RTL and still omits national id', (
    tester,
  ) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(200, profileWire()),
    );
    await tester.pumpWidget(
      wrap(
        ProfileScreen(profile: sample()),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
        locale: ClinicLocales.arabic,
      ),
    );
    expect(
      Directionality.of(tester.element(find.byType(ProfileScreen))),
      TextDirection.rtl,
    );
    expect(find.textContaining('الرقم القومي'), findsNothing);
    expect(find.text('Own Name'), findsOneWidget);
  });

  testWidgets('large text keeps profile edit action', (tester) async {
    final vault = MemoryVault();
    final adapter = ScriptedAdapter(
      (_) async => jsonEnvelope(200, profileWire()),
    );
    await tester.pumpWidget(
      wrap(
        ProfileScreen(profile: sample()),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
        textScale: 2,
      ),
    );
    expect(find.byKey(const Key('profile-edit')), findsOneWidget);
  });
}

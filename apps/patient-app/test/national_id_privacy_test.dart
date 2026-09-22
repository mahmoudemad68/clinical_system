import 'dart:io';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_patient_app/app_providers.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/session/session_controller.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';
import 'support/synthetic_national_id.dart';

void main() {
  test('National ID is not written to the credential vault', () async {
    const nid = kSyntheticNationalId;
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/patients/onboarding')) {
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': 'pid',
          'version': 1,
        });
      }
      if (options.path.contains('/patients/me/profile')) {
        return jsonEnvelope(200, profileWire());
      }
      return jsonEnvelope(200, identityWire());
    });
    final vault = MemoryVault();
    final store = TokenStore(vault);
    await store.write(access: 'tok', refresh: 'ref');
    final container = ProviderContainer(
      overrides: [
        httpClientProvider.overrideWithValue(testClient(adapter)),
        tokenStoreProvider.overrideWithValue(store),
      ],
    );
    addTearDown(container.dispose);
    await container.read(sessionProvider.future);
    container
        .read(onboardingDraftProvider.notifier)
        .update(
          (current) => current.copyWith(fullName: 'Ada', gender: 'female'),
        );
    await container
        .read(onboardingSubmitProvider.notifier)
        .submit(nationalId: nid);
    final persisted = vault.values.values.join('|');
    expect(persisted, isNot(contains(nid)));
    expect(vault.values.keys, isNot(contains('national_id')));
    expect(vault.values.keys.where((key) => key.contains('profile')), isEmpty);
  });

  test('patient-app sources do not persist National ID and do not log it', () {
    final files = Directory('lib')
        .listSync(recursive: true)
        .whereType<File>()
        .where((file) => file.path.endsWith('.dart'));
    for (final file in files) {
      final source = file.readAsStringSync();
      expect(source, isNot(contains('SharedPreferences')));
      expect(source, isNot(contains('print(')));
      expect(source, isNot(contains('debugPrint')));
      expect(source, isNot(contains('LogInterceptor')));
    }
  });
}

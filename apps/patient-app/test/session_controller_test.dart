import 'package:clinic_api_client/clinic_api_client.dart';
import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_patient_app/app_providers.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/profile/own_profile_controller.dart';
import 'package:clinic_patient_app/profile/patient_surface.dart';
import 'package:clinic_patient_app/session/session_controller.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';
import 'support/synthetic_national_id.dart';

ProviderContainer containerWith(ScriptedAdapter adapter, {TokenStore? store}) {
  final tokens = store ?? TokenStore(MemoryVault());
  return ProviderContainer(
    overrides: [
      httpClientProvider.overrideWithValue(testClient(adapter)),
      tokenStoreProvider.overrideWithValue(tokens),
    ],
  );
}

void main() {
  test('unauthenticated session stays anonymous', () async {
    final adapter = ScriptedAdapter((_) async => jsonEnvelope(401, null));
    final container = containerWith(adapter);
    addTearDown(container.dispose);
    final identity = await container.read(sessionProvider.future);
    expect(identity, isNull);
    expect(container.read(patientSurfaceProvider), PatientSurface.auth);
  });

  test('authenticated patient with a profile routes to profile', () async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/me') && !options.path.contains('profile')) {
        return jsonEnvelope(200, identityWire());
      }
      if (options.path.contains('/patients/me/profile')) {
        return jsonEnvelope(200, profileWire(name: 'Authoritative Name'));
      }
      return jsonEnvelope(404, null);
    });
    final store = TokenStore(MemoryVault());
    await store.write(access: 'a', refresh: 'r');
    final container = containerWith(adapter, store: store);
    addTearDown(container.dispose);
    final identity = await container.read(sessionProvider.future);
    expect(identity?.isPatient, isTrue);
    final lookup = await container.read(ownProfileProvider.future);
    expect((lookup as OwnProfileFound).profile.fullName, 'Authoritative Name');
    expect(container.read(patientSurfaceProvider), PatientSurface.profile);
  });

  test(
    'authenticated patient without a profile routes to onboarding',
    () async {
      final adapter = ScriptedAdapter((options) async {
        if (options.path.contains('/me') && !options.path.contains('profile')) {
          return jsonEnvelope(200, identityWire());
        }
        if (options.path.contains('/patients/me/profile')) {
          return jsonEnvelope(
            404,
            null,
            errors: [
              {'code': 'NOT_FOUND', 'message': 'Not found.'},
            ],
          );
        }
        return jsonEnvelope(404, null);
      });
      final store = TokenStore(MemoryVault());
      await store.write(access: 'a', refresh: 'r');
      final container = containerWith(adapter, store: store);
      addTearDown(container.dispose);
      await container.read(sessionProvider.future);
      await container.read(ownProfileProvider.future);
      expect(container.read(patientSurfaceProvider), PatientSurface.onboarding);
    },
  );

  test('profile_ready refetches authoritative own profile', () async {
    var profileCalls = 0;
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/patients/onboarding')) {
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': 'pid',
          'version': 1,
        });
      }
      if (options.path.contains('/patients/me/profile')) {
        profileCalls += 1;
        return jsonEnvelope(200, profileWire(name: 'From Server'));
      }
      if (options.path.contains('/me')) {
        return jsonEnvelope(200, identityWire());
      }
      return jsonEnvelope(404, null);
    });
    final store = TokenStore(MemoryVault());
    await store.write(access: 'a', refresh: 'r');
    final container = containerWith(adapter, store: store);
    addTearDown(container.dispose);
    await container.read(sessionProvider.future);
    container
        .read(onboardingDraftProvider.notifier)
        .update(
          (current) =>
              current.copyWith(fullName: 'Form Name', gender: 'female'),
        );
    final status = await container
        .read(onboardingSubmitProvider.notifier)
        .submit(nationalId: kSyntheticNationalId);
    expect(status, PatientOnboardingStatus.profileReady);
    final lookup = container.read(ownProfileProvider).asData?.value;
    expect((lookup as OwnProfileFound).profile.fullName, 'From Server');
    expect(profileCalls, greaterThan(0));
  });

  test(
    'manual_review_required does not branch on local collision reasons',
    () async {
      Future<bool> reviewFor(String nid) async {
        final adapter = ScriptedAdapter((options) async {
          if (options.path.contains('/patients/onboarding')) {
            return jsonEnvelope(200, {'status': 'manual_review_required'});
          }
          if (options.path.contains('/patients/me/profile')) {
            return jsonEnvelope(
              404,
              null,
              errors: [
                {'code': 'NOT_FOUND', 'message': 'Not found.'},
              ],
            );
          }
          return jsonEnvelope(200, identityWire());
        });
        final store = TokenStore(MemoryVault());
        await store.write(access: 'a', refresh: 'r');
        final container = containerWith(adapter, store: store);
        addTearDown(container.dispose);
        await container.read(sessionProvider.future);
        container
            .read(onboardingDraftProvider.notifier)
            .update(
              (current) => current.copyWith(fullName: 'Ada', gender: 'female'),
            );
        final status = await container
            .read(onboardingSubmitProvider.notifier)
            .submit(nationalId: nid);
        expect(status, PatientOnboardingStatus.manualReviewRequired);
        expect(container.read(manualReviewProvider), isTrue);
        expect(
          container.read(patientSurfaceProvider),
          PatientSurface.manualReview,
        );
        return container.read(manualReviewProvider);
      }

      expect(
        await reviewFor(kSyntheticNationalId),
        await reviewFor(kSyntheticNationalIdAlt),
      );
    },
  );

  test(
    'logout clears session so a later identity cannot see prior profile state',
    () async {
      var user = 'user-a';
      final adapter = ScriptedAdapter((options) async {
        if (options.path.contains('/logout')) {
          return jsonEnvelope(200, {'status': 'logged_out'});
        }
        if (options.path.contains('/me') && !options.path.contains('profile')) {
          return jsonEnvelope(200, identityWire(userId: user));
        }
        if (options.path.contains('/patients/me/profile')) {
          if (user == 'user-a') {
            return jsonEnvelope(200, profileWire(name: 'Patient A'));
          }
          return jsonEnvelope(200, profileWire(name: 'Patient B'));
        }
        return jsonEnvelope(404, null);
      });
      final vault = MemoryVault();
      final store = TokenStore(vault);
      await store.write(access: 'a', refresh: 'r');
      final container = containerWith(adapter, store: store);
      addTearDown(container.dispose);
      await container.read(sessionProvider.future);
      var lookup = await container.read(ownProfileProvider.future);
      expect((lookup as OwnProfileFound).profile.fullName, 'Patient A');

      await container.read(sessionProvider.notifier).signOut();
      expect(container.read(sessionProvider).asData?.value, isNull);
      expect(await store.readAccess(), isNull);
      expect(vault.values.values.join(), isNot(contains('Patient A')));

      user = 'user-b';
      await store.write(access: 'b', refresh: 'r2');
      await container.read(sessionProvider.notifier).markAuthenticated();
      final lookupB = await container.read(ownProfileProvider.future);
      expect((lookupB as OwnProfileFound).profile.fullName, 'Patient B');
      expect(lookupB.profile.fullName, isNot('Patient A'));
    },
  );

  test(
    'onboarding retries reuse the same idempotency key until retired',
    () async {
      var calls = 0;
      final keys = <String?>[];
      final adapter = ScriptedAdapter((options) async {
        if (options.path.contains('/patients/onboarding')) {
          calls += 1;
          keys.add(options.headers['Idempotency-Key']?.toString());
          if (calls == 1) {
            throw DioException(
              requestOptions: options,
              type: DioExceptionType.connectionTimeout,
            );
          }
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
      final store = TokenStore(MemoryVault());
      await store.write(access: 'a', refresh: 'r');
      final container = containerWith(adapter, store: store);
      addTearDown(container.dispose);
      await container.read(sessionProvider.future);
      container
          .read(onboardingDraftProvider.notifier)
          .update(
            (current) => current.copyWith(fullName: 'Ada', gender: 'female'),
          );
      await container
          .read(onboardingSubmitProvider.notifier)
          .submit(nationalId: kSyntheticNationalId);
      await container
          .read(onboardingSubmitProvider.notifier)
          .submit(nationalId: kSyntheticNationalId);
      expect(keys, hasLength(2));
      expect(keys[0], keys[1]);
    },
  );

  test('stale authentication failure returns to auth', () async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/me')) {
        return jsonEnvelope(
          401,
          null,
          errors: [
            {'code': 'UNAUTHENTICATED', 'message': 'Sign in again.'},
          ],
        );
      }
      return jsonEnvelope(401, null);
    });
    final store = TokenStore(MemoryVault());
    await store.write(access: 'stale', refresh: 'stale-r');
    final container = containerWith(adapter, store: store);
    addTearDown(container.dispose);
    final identity = await container.read(sessionProvider.future);
    expect(identity, isNull);
    expect(container.read(patientSurfaceProvider), PatientSurface.auth);
  });

  test('non-patient accounts cannot use the patient profile surface', () async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/me')) {
        return jsonEnvelope(200, identityWire(accountType: 'doctor'));
      }
      return jsonEnvelope(404, null);
    });
    final store = TokenStore(MemoryVault());
    await store.write(access: 'a', refresh: 'r');
    final container = containerWith(adapter, store: store);
    addTearDown(container.dispose);
    await container.read(sessionProvider.future);
    expect(container.read(patientSurfaceProvider), PatientSurface.unsupported);
  });
}

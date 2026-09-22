import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/session/patient_session.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

void main() {
  late MemoryVault vault;
  late TokenStore store;

  setUp(() {
    vault = MemoryVault();
    store = TokenStore(vault);
  });

  Future<ProviderContainer> containerFor(ScriptedAdapter adapter) async {
    final client = testClient(adapter);
    final container = ProviderContainer(
      overrides: patientOverrides(vault: vault, client: client),
    );
    addTearDown(container.dispose);
    return container;
  }

  test('unauthenticated when the vault is empty', () async {
    final adapter = ScriptedAdapter((_) async => jsonEnvelope(401, null));
    final container = await containerFor(adapter);
    await container.read(patientSessionProvider.notifier).restore();
    expect(
      container.read(patientSessionProvider),
      isA<PatientRouteUnauthenticated>(),
    );
  });

  test('authenticated profile exists', () async {
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      return jsonEnvelope(200, profileWire());
    });
    final container = await containerFor(adapter);
    await container.read(patientSessionProvider.notifier).restore();
    final state = container.read(patientSessionProvider);
    expect(state, isA<PatientRouteProfile>());
    expect(
      (state as PatientRouteProfile).profile.fullName,
      'Synthetic Patient',
    );
  });

  test('onboarding required when own profile is absent', () async {
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
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
    final container = await containerFor(adapter);
    await container.read(patientSessionProvider.notifier).restore();
    expect(
      container.read(patientSessionProvider),
      isA<PatientRouteOnboarding>(),
    );
  });

  test('wrong account types do not enter patient onboarding', () async {
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      expect(isPath(options, 'patients'), isFalse);
      final me = mePatient()..['account_type'] = 'doctor';
      return jsonEnvelope(200, me);
    });
    final container = await containerFor(adapter);
    await container.read(patientSessionProvider.notifier).restore();
    expect(
      container.read(patientSessionProvider),
      isA<PatientRouteWrongAccount>(),
    );
  });

  test('profile_ready refetches the authoritative projection', () async {
    await store.write(access: 'a', refresh: 'r');
    var profileCalls = 0;
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      profileCalls++;
      return jsonEnvelope(200, profileWire(name: 'Authoritative'));
    });
    final container = await containerFor(adapter);
    await container
        .read(patientSessionProvider.notifier)
        .applyOnboarding(
          const PatientOnboardingResult(
            status: PatientOnboardingStatus.profileReady,
            patientId: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
            version: 1,
          ),
        );
    final state = container.read(patientSessionProvider);
    expect(state, isA<PatientRouteProfile>());
    expect((state as PatientRouteProfile).profile.fullName, 'Authoritative');
    expect(profileCalls, 1);
  });

  test(
    'manual_review_required is generic and sticky until profile exists',
    () async {
      await store.write(access: 'a', refresh: 'r');
      final adapter = ScriptedAdapter((options) async {
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
      final container = await containerFor(adapter);
      await container
          .read(patientSessionProvider.notifier)
          .applyOnboarding(
            const PatientOnboardingResult(
              status: PatientOnboardingStatus.manualReviewRequired,
            ),
          );
      expect(
        container.read(patientSessionProvider),
        isA<PatientRouteManualReview>(),
      );
      await container.read(patientSessionProvider.notifier).refresh();
      expect(
        container.read(patientSessionProvider),
        isA<PatientRouteManualReview>(),
      );
    },
  );

  test('logout clears profile and onboarding state', () async {
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/logout')) {
        return jsonEnvelope(200, <String, Object?>{});
      }
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      return jsonEnvelope(200, profileWire(name: 'Patient A'));
    });
    final container = await containerFor(adapter);
    await container.read(patientSessionProvider.notifier).restore();
    container.read(onboardingProvider.notifier).update((draft) {
      draft.nationalId = '29201011234567';
      draft.fullName = 'Patient A';
    });
    await container.read(patientSessionProvider.notifier).signOut();
    expect(
      container.read(patientSessionProvider),
      isA<PatientRouteUnauthenticated>(),
    );
    expect(container.read(onboardingProvider).draft.nationalId, isEmpty);
    expect(await store.readAccess(), isNull);
  });

  test(
    'Patient A logout then Patient B does not leak A demographics',
    () async {
      await store.write(access: 'a-access', refresh: 'a-refresh');
      var currentName = 'Patient A';
      final adapter = ScriptedAdapter((options) async {
        if (isPath(options, '/logout')) {
          return jsonEnvelope(200, <String, Object?>{});
        }
        if (isPath(options, '/api/v1/me')) {
          return jsonEnvelope(200, mePatient());
        }
        return jsonEnvelope(200, profileWire(name: currentName));
      });
      final container = await containerFor(adapter);
      await container.read(patientSessionProvider.notifier).restore();
      expect(
        (container.read(
          patientSessionProvider,
        ) as PatientRouteProfile).profile.fullName,
        'Patient A',
      );
      await container.read(patientSessionProvider.notifier).signOut();
      currentName = 'Patient B';
      await store.write(access: 'b-access', refresh: 'b-refresh');
      await container.read(patientSessionProvider.notifier).restore();
      final state = container.read(patientSessionProvider);
      expect(state, isA<PatientRouteProfile>());
      expect((state as PatientRouteProfile).profile.fullName, 'Patient B');
      expect(state.profile.fullName, isNot('Patient A'));
    },
  );

  test('stale generations are ignored after logout', () async {
    await store.write(access: 'a', refresh: 'r');
    final adapter = ScriptedAdapter((options) async {
      if (isPath(options, '/api/v1/me')) {
        await Future<void>.delayed(const Duration(milliseconds: 30));
        return jsonEnvelope(200, mePatient());
      }
      if (isPath(options, '/logout')) {
        return jsonEnvelope(200, <String, Object?>{});
      }
      return jsonEnvelope(200, profileWire(name: 'Stale A'));
    });
    final container = await containerFor(adapter);
    final pending = container.read(patientSessionProvider.notifier).restore();
    await container.read(patientSessionProvider.notifier).signOut();
    await pending;
    expect(
      container.read(patientSessionProvider),
      isA<PatientRouteUnauthenticated>(),
    );
  });
}

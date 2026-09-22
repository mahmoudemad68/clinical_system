import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_patient_app/main.dart';
import 'package:clinic_patient_app/shell/locale_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';
import 'support/session_overrides.dart';
import 'support/synthetic_national_id.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('onboarding stepper hides National ID on the review step', (
    tester,
  ) async {
    const nid = kSyntheticNationalId;
    var onboarded = false;
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/patients/onboarding')) {
        onboarded = true;
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': 'pid',
          'version': 1,
        });
      }
      if (options.path.contains('/patients/me/profile')) {
        if (!onboarded) {
          return jsonEnvelope(
            404,
            null,
            errors: [
              {'code': 'NOT_FOUND', 'message': 'Not found.'},
            ],
          );
        }
        return jsonEnvelope(200, profileWire(name: 'From Server'));
      }
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
      }
      return jsonEnvelope(200, identityWire());
    });
    final client = testClient(adapter);
    final tokens = TokenStore(MemoryVault());

    await tester.pumpWidget(
      ProviderScope(
        overrides: seededPatientOverrides(
          client: client,
          tokens: tokens,
          liveProfile: true,
        ),
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(
      tester,
      find.byKey(const Key('onboarding-national-id')),
    );

    expect(find.byKey(const Key('onboarding-national-id')), findsOneWidget);
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'Ada Lovelace',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      nid,
    );
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await pumpUntilFound(tester, find.byKey(const Key('onboarding-gender')));

    await selectDropdown(tester, const Key('onboarding-gender'), 'Female');
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await pumpUntilFound(tester, find.byKey(const Key('self-reported-hint')));

    expect(find.byKey(const Key('self-reported-hint')), findsOneWidget);
    expect(find.textContaining('Self-reported'), findsWidgets);
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await pumpUntilFound(tester, find.byKey(const Key('onboarding-review')));

    expect(find.byKey(const Key('onboarding-review')), findsOneWidget);
    expect(find.text('Ada Lovelace'), findsOneWidget);
    expect(find.text(nid), findsNothing);
    expect(find.byKey(const Key('onboarding-national-id')), findsNothing);

    await tester.tap(find.byKey(const Key('onboarding-submit')));
    await pumpUntilFound(tester, find.byKey(const Key('profile-full-name')));
    expect(find.text('From Server'), findsOneWidget);
    expect(find.text(nid), findsNothing);
  });

  testWidgets('validation is announced as text', (tester) async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
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
    await tester.pumpWidget(
      ProviderScope(
        overrides: seededPatientOverrides(
          client: testClient(adapter),
          tokens: TokenStore(MemoryVault()),
        ),
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(tester, find.byKey(const Key('onboarding-continue')));
    await tester.tap(find.byKey(const Key('onboarding-continue')));
    await pumpUntilFound(
      tester,
      find.byKey(const Key('onboarding-identity-error')),
    );
  });

  testWidgets('manual review uses the same generic wording', (tester) async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
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

    await tester.pumpWidget(
      ProviderScope(
        overrides: seededPatientOverrides(
          client: testClient(adapter),
          tokens: TokenStore(MemoryVault()),
          manualReview: true,
        ),
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(tester, find.byKey(const Key('manual-review')));
    expect(find.byKey(const Key('manual-review-safe')), findsOneWidget);
    expect(find.textContaining('already exists'), findsNothing);
    expect(find.textContaining('National ID'), findsNothing);
    expect(find.textContaining('unlinked'), findsNothing);
    expect(find.textContaining('belongs to someone else'), findsNothing);
  });

  testWidgets('profile displays authoritative fields without National ID', (
    tester,
  ) async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
      }
      if (options.path.contains('/patients/me/profile')) {
        return jsonEnvelope(200, profileWire(name: 'Server Name'));
      }
      return jsonEnvelope(200, identityWire());
    });
    await tester.pumpWidget(
      ProviderScope(
        overrides: seededPatientOverrides(
          client: testClient(adapter),
          tokens: TokenStore(MemoryVault()),
          profile: () => ReadyOwnProfile(),
        ),
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(tester, find.byKey(const Key('profile-full-name')));
    expect(find.text('Server Name'), findsOneWidget);
    expect(find.textContaining('National ID'), findsNothing);
    expect(find.byKey(const Key('edit-demographics')), findsOneWidget);
  });

  testWidgets('VERSION_CONFLICT shows refresh, not a silent retry', (
    tester,
  ) async {
    var version = 1;
    var patchCalls = 0;
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/demographics')) {
        patchCalls += 1;
        return jsonEnvelope(
          409,
          null,
          errors: [
            {
              'code': 'VERSION_CONFLICT',
              'message': 'The resource was updated.',
            },
          ],
        );
      }
      if (options.path.contains('/patients/me/profile')) {
        return jsonEnvelope(
          200,
          profileWire(name: 'Server Name', version: version),
        );
      }
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
      }
      return jsonEnvelope(200, identityWire());
    });
    await tester.pumpWidget(
      ProviderScope(
        overrides: seededPatientOverrides(
          client: testClient(adapter),
          tokens: TokenStore(MemoryVault()),
          profile: () => ReadyOwnProfile(),
        ),
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(tester, find.byKey(const Key('edit-demographics')));
    await tester.tap(find.byKey(const Key('edit-demographics')));
    await pumpUntilFound(tester, find.byKey(const Key('demographics-save')));
    expect(find.byKey(const Key('sign-out')), findsOneWidget);
    await tester.tap(find.byKey(const Key('demographics-save')));
    await pumpUntilFound(tester, find.byKey(const Key('version-conflict')));
    expect(find.byKey(const Key('version-conflict-refresh')), findsOneWidget);
    expect(patchCalls, 1);
    version = 2;
  });

  testWidgets('Arabic RTL layout for onboarding', (tester) async {
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
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
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          ...seededPatientOverrides(
            client: testClient(adapter),
            tokens: TokenStore(MemoryVault()),
          ),
          localeProvider.overrideWith(_ArabicLocale.new),
        ],
        child: const ClinicApp(),
      ),
    );
    await pumpUntilFound(tester, find.text('إكمال الملف الشخصي'));
    final direction = Directionality.of(
      tester.element(find.byKey(const Key('onboarding-full-name'))),
    );
    expect(direction, TextDirection.rtl);
  });

  testWidgets('large text still shows the primary action', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
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
    await tester.pumpWidget(
      MediaQuery(
        data: const MediaQueryData(textScaler: TextScaler.linear(2)),
        child: ProviderScope(
          overrides: seededPatientOverrides(
            client: testClient(adapter),
            tokens: TokenStore(MemoryVault()),
          ),
          child: const ClinicApp(),
        ),
      ),
    );
    await pumpUntilFound(tester, find.byKey(const Key('onboarding-continue')));
    await tester.ensureVisible(find.byKey(const Key('onboarding-continue')));
  });
}

class _ArabicLocale extends LocaleController {
  @override
  Locale build() => ClinicLocales.arabic;
}

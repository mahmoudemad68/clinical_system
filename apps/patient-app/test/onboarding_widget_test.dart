import 'package:clinic_localization/clinic_localization.dart';
import 'package:clinic_patient_app/onboarding/onboarding_controller.dart';
import 'package:clinic_patient_app/onboarding/onboarding_screen.dart';
import 'package:clinic_patient_app/onboarding/review_holding_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

const canary = '29901011234567';

void main() {
  late MemoryVault vault;
  late ScriptedAdapter adapter;

  setUp(() {
    vault = MemoryVault();
    adapter = ScriptedAdapter((options) async {
      if (isPath(options, 'onboarding')) {
        return jsonEnvelope(201, {
          'status': 'profile_ready',
          'patient_id': '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7c01',
          'version': 1,
        });
      }
      if (isPath(options, '/patients/me/profile')) {
        return jsonEnvelope(200, profileWire());
      }
      if (isPath(options, '/logout')) {
        return jsonEnvelope(200, <String, Object?>{});
      }
      if (isPath(options, '/api/v1/me')) {
        return jsonEnvelope(200, mePatient());
      }
      return jsonEnvelope(404, null);
    });
  });

  Future<void> pumpOnboarding(
    WidgetTester tester, {
    Locale locale = ClinicLocales.english,
    double textScale = 1,
  }) async {
    await tester.pumpWidget(
      wrap(
        const OnboardingScreen(),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
        locale: locale,
        textScale: textScale,
      ),
    );
    await tester.pumpAndSettle();
  }

  testWidgets('identity validation is announced as text', (tester) async {
    await pumpOnboarding(tester);
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pump();
    expect(find.text('Enter your National ID.'), findsOneWidget);
    expect(find.text('Enter a valid full name.'), findsOneWidget);
  });

  testWidgets('stepper keeps draft in memory and hides national id on review', (
    tester,
  ) async {
    await pumpOnboarding(tester);
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      canary,
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'Ada Lovelace',
    );
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Female'));
    await tester.pump();
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();

    expect(find.textContaining('Self-reported'), findsWidgets);
    await tester.enterText(find.byKey(const Key('onboarding-height')), '165');
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('onboarding-review')), findsOneWidget);
    expect(find.text('Ada Lovelace'), findsOneWidget);
    expect(find.text(canary), findsNothing);
    expect(find.textContaining('National ID is not shown'), findsOneWidget);
  });

  testWidgets('height out of range is rejected without clamping', (
    tester,
  ) async {
    await pumpOnboarding(tester);
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      canary,
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'Ada Lovelace',
    );
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Female'));
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const Key('onboarding-height')), '900');
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pump();
    expect(
      find.textContaining('Enter a height within the allowed storage range'),
      findsOneWidget,
    );
    expect(find.text('900'), findsOneWidget);
  });

  testWidgets('Arabic RTL onboarding review still hides national id', (
    tester,
  ) async {
    await pumpOnboarding(tester, locale: ClinicLocales.arabic);
    expect(
      Directionality.of(tester.element(find.byType(OnboardingScreen))),
      TextDirection.rtl,
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      canary,
    );
    await tester.enterText(
      find.byKey(const Key('onboarding-full-name')),
      'منى',
    );
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('أنثى'));
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('primary-action')));
    await tester.pumpAndSettle();
    expect(find.text(canary), findsNothing);
    expect(find.byKey(const Key('onboarding-review')), findsOneWidget);
  });

  testWidgets('large text keeps the primary action', (tester) async {
    await pumpOnboarding(tester, textScale: 2);
    expect(find.byKey(const Key('primary-action')), findsOneWidget);
  });

  testWidgets('logout clears national id from the onboarding controller', (
    tester,
  ) async {
    await pumpOnboarding(tester);
    await tester.enterText(
      find.byKey(const Key('onboarding-national-id')),
      canary,
    );
    await tester.pump();
    await tester.tap(find.byKey(const Key('sign-out')));
    await tester.pumpAndSettle();
    final context = tester.element(find.byType(OnboardingScreen));
    final container = ProviderScope.containerOf(context);
    expect(container.read(onboardingProvider).draft.nationalId, isEmpty);
  });

  testWidgets('manual review wording is generic', (tester) async {
    await tester.pumpWidget(
      wrap(
        const ReviewHoldingScreen(),
        overrides: patientOverrides(vault: vault, client: testClient(adapter)),
      ),
    );
    expect(find.byKey(const Key('manual-review-body')), findsOneWidget);
    expect(find.textContaining('already exists'), findsNothing);
    expect(find.textContaining('National ID'), findsNothing);
    expect(find.textContaining('claimed'), findsNothing);
    expect(find.textContaining('unlinked'), findsNothing);
  });
}

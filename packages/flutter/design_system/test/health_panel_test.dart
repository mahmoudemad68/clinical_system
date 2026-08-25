import 'package:clinic_common_models/clinic_common_models.dart';
import 'package:clinic_design_system/clinic_design_system.dart';
import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

/// Phase 00 end-to-end requirement for the Flutter clients: display core health
/// and version in Arabic and English.
///
/// Testing the shared widget once covers all three apps, which is exactly why
/// it lives in the design system rather than being copied into each.
void main() {
  PlatformHealth health({
    ComponentStatus ai = ComponentStatus.operational,
    String message = 'All services are operating normally.',
  }) => PlatformHealth(
    status: ai == ComponentStatus.operational
        ? ComponentStatus.operational
        : ComponentStatus.degraded,
    message: message,
    core: ComponentStatus.operational,
    realtime: ComponentStatus.operational,
    ai: ai,
    version: '0.1.0-test',
    serverTime: DateTime.utc(2026, 8, 24, 19, 5),
  );

  Widget wrap(Widget child, Locale locale) => MaterialApp(
    locale: locale,
    supportedLocales: ClinicLocales.supported,
    localizationsDelegates: const [
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    home: Scaffold(body: child),
  );

  testWidgets('shows version and status in English', (tester) async {
    await tester.pumpWidget(
      wrap(
        HealthPanel(health: health(), isLoading: false),
        ClinicLocales.english,
      ),
    );

    expect(find.byKey(const Key('health-version')), findsOneWidget);
    expect(find.text('0.1.0-test'), findsOneWidget);
    expect(find.text('Platform health'), findsOneWidget);
    expect(find.text('Operational'), findsWidgets);
  });

  testWidgets('shows version and status in Arabic', (tester) async {
    await tester.pumpWidget(
      wrap(
        HealthPanel(health: health(), isLoading: false),
        ClinicLocales.arabic,
      ),
    );

    expect(find.text('0.1.0-test'), findsOneWidget);
    expect(find.text('حالة المنصة'), findsOneWidget);
    expect(find.text('تعمل'), findsWidgets);
  });

  testWidgets('renders Arabic right-to-left', (tester) async {
    await tester.pumpWidget(
      wrap(
        HealthPanel(health: health(), isLoading: false),
        ClinicLocales.arabic,
      ),
    );

    final direction = Directionality.of(
      tester.element(find.byKey(const Key('health-version'))),
    );

    expect(direction, TextDirection.rtl);
  });

  testWidgets('shows a degraded AI without claiming the platform is down', (
    tester,
  ) async {
    await tester.pumpWidget(
      wrap(
        HealthPanel(
          health: health(
            ai: ComponentStatus.unavailable,
            message: 'Some optional services are down.',
          ),
          isLoading: false,
        ),
        ClinicLocales.english,
      ),
    );

    // Core still reads Operational; only AI is Unavailable.
    expect(find.text('Unavailable'), findsOneWidget);
    expect(find.text('Operational'), findsWidgets);
  });

  testWidgets('surfaces the request id and a retry action on failure', (
    tester,
  ) async {
    var retried = false;

    await tester.pumpWidget(
      wrap(
        HealthPanel(
          health: null,
          isLoading: false,
          errorMessage: 'The platform could not be reached.',
          requestId: '0199a5c8-1f2e-7c3a-9b41-2f6d0c5e7a10',
          onRetry: () => retried = true,
        ),
        ClinicLocales.english,
      ),
    );

    expect(find.byKey(const Key('health-error')), findsOneWidget);
    // The request id is the only handle support has for this exact failure, so
    // it is shown to the user rather than buried in a log they cannot reach.
    expect(find.byKey(const Key('health-request-id')), findsOneWidget);

    await tester.tap(find.byKey(const Key('health-retry')));
    expect(retried, isTrue);
  });

  testWidgets('shows a loading state before data arrives', (tester) async {
    await tester.pumpWidget(
      wrap(
        const HealthPanel(health: null, isLoading: true),
        ClinicLocales.english,
      ),
    );

    expect(find.byKey(const Key('health-loading')), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsOneWidget);
  });

  testWidgets('conveys status as text, not colour alone', (tester) async {
    await tester.pumpWidget(
      wrap(
        HealthPanel(
          health: health(ai: ComponentStatus.degraded),
          isLoading: false,
        ),
        ClinicLocales.english,
      ),
    );

    // Colour-only status fails WCAG 1.4.1 and is unreadable for a colour-blind
    // user. Every indicator must carry a text label.
    expect(find.text('Degraded'), findsOneWidget);
    expect(find.text('Operational'), findsWidgets);
  });
}

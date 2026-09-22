import 'dart:async';

import 'package:clinic_authentication/clinic_authentication.dart';
import 'package:clinic_patient_app/main.dart';
import 'package:clinic_patient_app/session/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/harness.dart';

void main() {
  testWidgets('logout leaves no previous patient name on the auth surface', (
    tester,
  ) async {
    var user = 'user-a';
    final adapter = ScriptedAdapter((options) async {
      if (options.path.contains('/logout')) {
        return jsonEnvelope(200, {'status': 'logged_out'});
      }
      if (options.path.contains('/health')) {
        return jsonEnvelope(200, healthWire());
      }
      if (options.path.contains('/me') && !options.path.contains('profile')) {
        return jsonEnvelope(200, identityWire(userId: user));
      }
      if (options.path.contains('/patients/me/profile')) {
        return jsonEnvelope(
          200,
          profileWire(
            name: user == 'user-a' ? 'Patient A Visible' : 'Patient B Visible',
          ),
        );
      }
      return jsonEnvelope(404, null);
    });
    final store = TokenStore(MemoryVault());
    await store.write(access: 'a', refresh: 'r');

    await tester.pumpWidget(
      patientApp(client: testClient(adapter), tokens: store),
    );
    await pumpUntilFound(tester, find.text('Patient A Visible'));
    expect(find.text('Patient A Visible'), findsOneWidget);

    await tester.tap(find.byKey(const Key('sign-out')));
    await pumpUntilGone(tester, find.text('Patient A Visible'));
    expect(find.text('Patient A Visible'), findsNothing);
    expect(find.text('Sign in'), findsWidgets);

    user = 'user-b';
    await store.write(access: 'b', refresh: 'r2');
    final context = tester.element(find.byType(ClinicApp));
    final container = ProviderScope.containerOf(context);
    // Do not await HTTP here: FakeAsync only flushes Dio while pumping.
    unawaited(container.read(sessionProvider.notifier).markAuthenticated());
    await pumpUntilFound(tester, find.text('Patient B Visible'));
    expect(find.text('Patient A Visible'), findsNothing);
    expect(find.text('Patient B Visible'), findsOneWidget);
  });
}

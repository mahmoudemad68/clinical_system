import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'patient_chrome.dart';
import '../session/session_controller.dart';

class UnsupportedAccountScreen extends ConsumerWidget {
  const UnsupportedAccountScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    return PatientChrome(
      title: strings.appTitlePatient,
      showSignOut: true,
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              strings.unsupportedAccountTitle,
              key: const Key('unsupported-account'),
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            const SizedBox(height: 12),
            Text(strings.unsupportedAccountBody),
            const SizedBox(height: 24),
            FilledButton(
              onPressed: () => ref.read(sessionProvider.notifier).signOut(),
              child: Text(strings.signOut),
            ),
          ],
        ),
      ),
    );
  }
}

import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/patient_session.dart';
import '../widgets/patient_chrome.dart';

class AccountGateScreen extends ConsumerWidget {
  const AccountGateScreen({super.key, required this.accountType});

  final String accountType;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.appTitlePatient),
        actions: const [LanguageMenuButton()],
      ),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(strings.accountNotPatient, key: const Key('account-gate')),
            const SizedBox(height: 24),
            FilledButton(
              key: const Key('account-gate-sign-out'),
              onPressed: () =>
                  ref.read(patientSessionProvider.notifier).signOut(),
              child: Text(strings.signOut),
            ),
          ],
        ),
      ),
    );
  }
}

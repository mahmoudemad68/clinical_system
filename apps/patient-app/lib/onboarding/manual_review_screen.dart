import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../profile/own_profile_controller.dart';
import '../session/session_controller.dart';
import '../shell/patient_chrome.dart';

class ManualReviewScreen extends ConsumerWidget {
  const ManualReviewScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    final theme = Theme.of(context);
    final loading = ref.watch(ownProfileProvider).isLoading;

    return PatientChrome(
      title: strings.reviewPendingTitle,
      showSignOut: true,
      footer: FilledButton(
        key: const Key('manual-review-refresh'),
        onPressed: loading
            ? null
            : () => ref.read(ownProfileProvider.notifier).refresh(),
        child: Text(strings.refreshAction),
      ),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Icon(Icons.hourglass_top, size: 64, color: theme.colorScheme.primary),
          const SizedBox(height: 16),
          Text(
            strings.reviewPendingTitle,
            key: const Key('manual-review'),
            style: theme.textTheme.headlineSmall,
          ),
          const SizedBox(height: 12),
          Text(strings.reviewPendingBody),
          const SizedBox(height: 12),
          Text(strings.reviewPendingSafe, key: const Key('manual-review-safe')),
          const SizedBox(height: 24),
          OutlinedButton(
            onPressed: () => ref.read(sessionProvider.notifier).signOut(),
            child: Text(strings.signOut),
          ),
        ],
      ),
    );
  }
}

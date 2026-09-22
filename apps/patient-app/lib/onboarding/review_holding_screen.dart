import 'package:clinic_localization/clinic_localization.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/patient_session.dart';
import '../widgets/patient_chrome.dart';

class ReviewHoldingScreen extends ConsumerWidget {
  const ReviewHoldingScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final strings = ClinicStrings.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.manualReviewTitle),
        actions: [
          const LanguageMenuButton(),
          SignOutButton(
            onPressed: () =>
                ref.read(patientSessionProvider.notifier).signOut(),
          ),
        ],
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const ExcludeSemantics(
                child: Icon(Icons.hourglass_top, size: 64),
              ),
              const SizedBox(height: 16),
              Text(
                strings.manualReviewTitle,
                key: const Key('manual-review-title'),
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 12),
              Text(
                strings.manualReviewBody,
                key: const Key('manual-review-body'),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 12),
              Text(
                strings.manualReviewNext,
                key: const Key('manual-review-next'),
                textAlign: TextAlign.center,
              ),
              const Spacer(),
              FilledButton(
                key: const Key('manual-review-refresh'),
                onPressed: () =>
                    ref.read(patientSessionProvider.notifier).refresh(),
                child: Text(strings.refreshAction),
              ),
              TextButton(
                key: const Key('manual-review-sign-out'),
                onPressed: () =>
                    ref.read(patientSessionProvider.notifier).signOut(),
                child: Text(strings.signOut),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
